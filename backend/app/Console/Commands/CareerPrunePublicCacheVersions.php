<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\CareerCacheVersionRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

final class CareerPrunePublicCacheVersions extends Command
{
    protected $signature = 'career:prune-public-cache-versions {--apply : Reclaim only the inspected, backed-up immutable projections} {--max-bytes=536870912 : Maximum estimated bytes to reclaim per invocation}';

    protected $description = 'Inventory and retain active/LKG/pinned Career cache versions; dry-run by default.';

    public function handle(CareerCacheVersionRetention $policy): int
    {
        $store = Cache::store()->getStore();
        if (! $store instanceof \Illuminate\Cache\RedisStore) {
            $this->error('Career retention requires the configured Redis cache store.');

            return self::FAILURE;
        }
        $redis = $store->connection()->client();
        if (! $redis instanceof \Redis) {
            $this->error('Career retention requires phpredis.');

            return self::FAILURE;
        }
        if (version_compare((string) ($redis->info('server')['redis_version'] ?? '0'), '7.0', '<')) {
            $this->error('Career retention requires Redis 7 atomic allow-oom support.');

            return self::FAILURE;
        }
        $limit = filter_var($this->option('max-bytes'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 2147483648) {
            return self::INVALID;
        }
        $prefix = (string) $redis->getOption(\Redis::OPT_PREFIX).$store->getPrefix();
        $directory = storage_path('app/private/career-cache-retention/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory, 0700);
        $plan = fopen($directory.'/plan.jsonl', 'xb');
        chmod($directory.'/plan.jsonl', 0600);
        $cursor = null;
        $seen = [];
        $count = 0;
        $bytes = 0;
        $now = time();
        try {
            do {
                $keys = $redis->scan($cursor, $prefix.'career:public-authority:*', 500);
                foreach ($keys ?: [] as $physicalKey) {
                    if (! str_starts_with($physicalKey, $prefix) || isset($seen[$physicalKey])) {
                        continue;
                    }
                    $seen[$physicalKey] = true;
                    $key = substr($physicalKey, strlen($prefix));
                    $identity = $policy->identify($key);
                    if ($identity === null) {
                        continue;
                    }
                    $active = Cache::get($identity['base'].':active');
                    $lkg = Cache::get($identity['base'].':lkg');
                    $pinned = Cache::has($identity['base'].':pins:'.$identity['version']);
                    $idle = $redis->rawCommand('OBJECT', 'IDLETIME', $physicalKey);
                    if ($idle === false || ! $policy->collectible($key, $active, $lkg, $pinned, (int) $idle, $now)) {
                        continue;
                    }
                    $size = (int) $redis->rawCommand('MEMORY', 'USAGE', $physicalKey);
                    $entry = ['key' => $key, ...$identity, 'active' => $active, 'lkg' => $lkg, 'bytes' => $size, 'idle_seconds' => $idle];
                    fwrite($plan, json_encode($entry, JSON_THROW_ON_ERROR)."\n");
                    $count++;
                    $bytes += $size;
                }
            } while ($cursor !== 0);
        } finally {
            fclose($plan);
        }
        unset($seen);
        $this->line(json_encode(['status' => 'planned', 'candidates' => $count, 'estimated_bytes' => $bytes, 'plan_sha256' => hash_file('sha256', $directory.'/plan.jsonl')], JSON_THROW_ON_ERROR));
        if (! $this->option('apply') || $count === 0) {
            return self::SUCCESS;
        }

        if (disk_free_space($directory) < min($bytes, $limit) * 2 + 268435456) {
            throw new \RuntimeException('Insufficient disk headroom for durable Career cache backups.');
        }
        $backup = fopen($directory.'/removed.jsonl', 'xb');
        chmod($directory.'/removed.jsonl', 0600);
        $protected = [];
        $removed = 0;
        $freed = 0;
        try {
            $rows = new \SplFileObject($directory.'/plan.jsonl');
            foreach ($rows as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($freed + $entry['bytes'] > $limit) {
                    continue;
                }
                $key = $prefix.$entry['key'];
                $base = $prefix.$entry['base'];
                $active = $redis->rawCommand('GET', $base.':active');
                $lkg = $redis->rawCommand('GET', $base.':lkg');
                // Bind Lua's raw compare to the exact planned pointers, not two racy reads.
                if ($active !== serialize($entry['active']) || $lkg !== serialize($entry['lkg'])) {
                    continue;
                }
                // Re-read application values; decoding remains owned by the configured Redis store.
                if (Cache::get($entry['base'].':active') !== $entry['active'] || Cache::get($entry['base'].':lkg') !== $entry['lkg']
                    || Cache::has($entry['base'].':pins:'.$entry['version'])) {
                    continue;
                }
                foreach (['active', 'lkg'] as $pointer) {
                    $protectedKey = $entry['base'].':versions:'.$entry[$pointer];
                    if (! isset($protected[$protectedKey])) {
                        $protectedDump = $redis->rawCommand('DUMP', $prefix.$protectedKey);
                        if (! is_string($protectedDump)) {
                            throw new \RuntimeException('Career protected payload is missing; retention stopped.');
                        }
                        $protected[$protectedKey] = sha1($protectedDump);
                    }
                }
                $idle = $redis->rawCommand('OBJECT', 'IDLETIME', $key);
                if ($idle === false || (int) $idle < CareerCacheVersionRetention::RETENTION_SECONDS) {
                    continue;
                }
                $dump = $redis->rawCommand('DUMP', $key);
                if (! is_string($dump)) {
                    continue;
                }
                $record = json_encode(['key' => $entry['key'], 'dump' => base64_encode($dump), 'ttl_ms' => $redis->rawCommand('PTTL', $key)], JSON_THROW_ON_ERROR)."\n";
                if (fwrite($backup, $record) !== strlen($record) || ! fflush($backup) || ! fsync($backup)) {
                    throw new \RuntimeException('Career retention backup write failed.');
                }
                // Compare-and-delete is one Redis operation, so a concurrent pointer switch or pin wins.
                $deleted = $redis->rawCommand('EVAL', <<<'LUA'
#!lua flags=allow-oom
if redis.call('GET', KEYS[2]) ~= ARGV[1] or redis.call('GET', KEYS[3]) ~= ARGV[2] or redis.call('EXISTS', KEYS[4]) ~= 0 then return 0 end
local payload = redis.call('DUMP', KEYS[1])
if not payload or redis.sha1hex(payload) ~= ARGV[3] then return 0 end
return redis.call('UNLINK', KEYS[1])
LUA, 4, $key, $base.':active', $base.':lkg', $base.':pins:'.$entry['version'], $active, $lkg, sha1($dump));
                if (! is_int($deleted) || ($deleted !== 0 && $deleted !== 1)) {
                    throw new \RuntimeException('Career retention atomic deletion failed.');
                }
                $removed += $deleted;
                $freed += $deleted ? $entry['bytes'] : 0;
            }
        } finally {
            fclose($backup);
        }
        foreach ($protected as $key => $digest) {
            $dump = $redis->rawCommand('DUMP', $prefix.$key);
            if (! is_string($dump) || ! hash_equals($digest, sha1($dump))) {
                throw new \RuntimeException('Career protected payload readback failed.');
            }
        }
        $this->line(json_encode(['status' => 'reclaimed', 'protected_readbacks' => count($protected), 'removed' => $removed, 'estimated_bytes' => $freed, 'backup_sha256' => hash_file('sha256', $directory.'/removed.jsonl')], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
