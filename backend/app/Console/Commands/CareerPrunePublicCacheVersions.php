<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\CareerCacheBackupRetention;
use App\Services\Career\CareerCacheVersionRetention;
use App\Support\PublicProjectionCache as Cache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerPrunePublicCacheVersions extends Command
{
    protected $signature = 'career:prune-public-cache-versions {--pressure : Skip below 70 percent and stop at 60 percent memory use} {--max-seconds=60 : Wall-clock budget including scan and backup} {--apply : Reclaim only the inspected, backed-up immutable projections} {--max-bytes=536870912 : Maximum estimated bytes to reclaim per invocation}';

    protected $description = 'Inventory and retain active/LKG/pinned Career cache versions; dry-run by default.';

    public function handle(CareerCacheVersionRetention $policy): int
    {
        $seconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT);
        if ($seconds === false || $seconds < 1 || $seconds > 60) {
            return self::INVALID;
        }
        $deadline = microtime(true) + $seconds;
        $root = storage_path('app/private/career-cache-retention');
        File::ensureDirectoryExists($root, 0770);
        $lease = fopen(storage_path('app/private/career-cache-retention.lock'), 'c');
        if ($lease !== false) {
            clearstatcache(true, storage_path('app/private/career-cache-retention.lock'));
            if ((fileperms(storage_path('app/private/career-cache-retention.lock')) & 0777) !== 0660) {
                chmod(storage_path('app/private/career-cache-retention.lock'), 0660);
            }
        }
        if ($lease === false || ! flock($lease, LOCK_EX | LOCK_NB)) {
            $this->line('{"status":"busy"}');

            return self::SUCCESS;
        }
        try {
            $result = $this->prune($policy, $root, $deadline);
            if ($this->option('pressure') && $result === self::SUCCESS) {
                $memories = [Cache::store()->getStore()->connection()->client()->info('memory')];
                if (in_array(Cache::state()['mode'], ['mirror', 'primary'], true)) {
                    [$other] = \App\Services\Career\PublicProjectionMigration::connection(Cache::state()['mode'] === 'mirror');
                    $memories[] = $other->info('memory');
                }
                $healthy = true;
                foreach ($memories as $memory) {
                    $healthy = $healthy && (int) ($memory['maxmemory'] ?? 0) > 0
                        && (int) ($memory['used_memory'] ?? PHP_INT_MAX) < (int) $memory['maxmemory'] * 0.85;
                }
                app(\App\Services\Ops\CacheLifecycleAlerts::class)->observe('redis_capacity', $healthy, true);
            }
            app(\App\Services\Ops\CacheLifecycleAlerts::class)->observe('career_retention', $result === self::SUCCESS);

            return $result;
        } catch (\Throwable $error) {
            app(\App\Services\Ops\CacheLifecycleAlerts::class)->observe('career_retention', false,
                str_contains($error->getMessage(), 'OOM') || str_contains($error->getMessage(), 'protected payload'));
            $this->error('Career retention failed; active content retained.');

            return self::FAILURE;
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    private function prune(CareerCacheVersionRetention $policy, string $root, float $deadline): int
    {
        $backups = new CareerCacheBackupRetention;
        $backupBytes = $backups->rotateAndMeasure($root, time(), (bool) $this->option('apply'));
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
        if ($this->option('pressure')) {
            $memory = $redis->info('memory');
            $maximum = (int) ($memory['maxmemory'] ?? 0);
            if ($maximum <= 0) {
                throw new \RuntimeException('Career cache memory budget unavailable.');
            }
            if ((int) $memory['used_memory'] < $maximum * 0.7) {
                $this->line('{"status":"below_threshold"}');

                return self::SUCCESS;
            }
        }
        $backups->assertHeadroom($backupBytes, 1048576);
        $prefix = (string) $redis->getOption(\Redis::OPT_PREFIX).$store->getPrefix();
        $directory = $root.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($directory, 0770);
        $plan = fopen($directory.'/plan.jsonl', 'xb');
        clearstatcache(true, $directory.'/plan.jsonl');
        if ((fileperms($directory.'/plan.jsonl') & 0777) !== 0660) {
            chmod($directory.'/plan.jsonl', 0660);
        }
        $cursorPath = storage_path('app/private/career-cache-retention-cursor.json');
        $scanState = is_file($cursorPath) ? json_decode((string) file_get_contents($cursorPath), true) : null;
        $cursor = $this->option('pressure') && is_array($scanState) && ($scanState['prefix'] ?? null) === $prefix
            ? (int) ($scanState['cursor'] ?? 0) : null;
        $seen = [];
        $count = 0;
        $bytes = 0;
        $now = time();
        try {
            do {
                if (microtime(true) >= $deadline - (int) $this->option('max-seconds') / 2) {
                    break;
                }
                $keys = $redis->scan($cursor, $prefix.'career:public-authority:*', 500);
                foreach ($keys ?: [] as $physicalKey) {
                    // Leave at least half the budget for durable backups and deletion.
                    if (microtime(true) >= $deadline - (int) $this->option('max-seconds') / 2 || $bytes >= $limit) {
                        break 2;
                    }
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
                    $record = json_encode($entry, JSON_THROW_ON_ERROR)."\n";
                    $backups->assertHeadroom($backupBytes, strlen($record) + 64);
                    if (fwrite($plan, $record) !== strlen($record)) {
                        throw new \RuntimeException('Career retention plan write failed.');
                    }
                    $backupBytes += strlen($record);
                    $count++;
                    $bytes += $size;
                }
            } while ($cursor !== 0);
        } finally {
            fclose($plan);
        }
        if ($this->option('apply') && $this->option('pressure')) {
            $temporary = $cursorPath.'.tmp';
            if (file_put_contents($temporary, json_encode(['prefix' => $prefix, 'cursor' => $cursor], JSON_THROW_ON_ERROR), LOCK_EX) === false
                || ! rename($temporary, $cursorPath)) {
                throw new \RuntimeException('Career retention scan progress write failed.');
            }
        }
        unset($seen);
        $this->line(json_encode(['status' => 'planned', 'candidates' => $count, 'estimated_bytes' => $bytes, 'plan_sha256' => hash_file('sha256', $directory.'/plan.jsonl')], JSON_THROW_ON_ERROR));
        if (! $this->option('apply') || $count === 0) {
            file_put_contents($directory.'/complete', (string) time());

            return self::SUCCESS;
        }

        if (disk_free_space($directory) < min($bytes, $limit) * 2 + 268435456) {
            throw new \RuntimeException('Insufficient disk headroom for durable Career cache backups.');
        }
        $backup = fopen($directory.'/removed.jsonl', 'xb');
        clearstatcache(true, $directory.'/removed.jsonl');
        if ((fileperms($directory.'/removed.jsonl') & 0777) !== 0660) {
            chmod($directory.'/removed.jsonl', 0660);
        }
        $protected = [];
        $removed = 0;
        $freed = 0;
        try {
            $rows = new \SplFileObject($directory.'/plan.jsonl');
            foreach ($rows as $line) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                if ($this->option('pressure') && (int) ($redis->info('memory')['used_memory'] ?? PHP_INT_MAX) <= $maximum * 0.6) {
                    break;
                }
                if (trim($line) === '') {
                    continue;
                }
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($freed + $entry['bytes'] > $limit) {
                    continue;
                }
                Cache::mutation(function () use ($entry, $prefix, $redis, $backup, $backups, &$backupBytes, &$protected, &$removed, &$freed): void {
                    $key = $prefix.$entry['key'];
                    $base = $prefix.$entry['base'];
                    $active = $redis->rawCommand('GET', $base.':active');
                    $lkg = $redis->rawCommand('GET', $base.':lkg');
                    // Bind Lua's raw compare to the exact planned pointers, not two racy reads.
                    if ($active !== serialize($entry['active']) || $lkg !== serialize($entry['lkg'])) {
                        return;
                    }
                    // Re-read application values; decoding remains owned by the configured Redis store.
                    if (Cache::get($entry['base'].':active') !== $entry['active'] || Cache::get($entry['base'].':lkg') !== $entry['lkg']
                        || Cache::has($entry['base'].':pins:'.$entry['version'])) {
                        return;
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
                        return;
                    }
                    $dump = $redis->rawCommand('DUMP', $key);
                    if (! is_string($dump)) {
                        return;
                    }
                    $mirror = null;
                    $mode = Cache::state()['mode'];
                    if (in_array($mode, ['mirror', 'primary'], true)) {
                        [$mirror, $mirrorPrefix] = \App\Services\Career\PublicProjectionMigration::connection($mode === 'mirror');
                        $mirrorBase = $mirrorPrefix.$entry['base'];
                        if ($mirror->rawCommand('GET', $mirrorBase.':active') !== $active
                            || $mirror->rawCommand('GET', $mirrorBase.':lkg') !== $lkg
                            || $mirror->rawCommand('EXISTS', $mirrorBase.':pins:'.$entry['version']) !== 0
                            || $mirror->rawCommand('DUMP', $mirrorPrefix.$entry['key']) !== $dump) {
                            return;
                        }
                    }
                    $record = json_encode(['key' => $entry['key'], 'dump' => base64_encode($dump), 'ttl_ms' => $redis->rawCommand('PTTL', $key)], JSON_THROW_ON_ERROR)."\n";
                    $backups->assertHeadroom($backupBytes, strlen($record) + 64);
                    if (fwrite($backup, $record) !== strlen($record) || ! fflush($backup) || ! fsync($backup)) {
                        throw new \RuntimeException('Career retention backup write failed.');
                    }
                    $backupBytes += strlen($record);
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
                    if ($deleted === 1 && $mirror !== null) {
                        try {
                            $mirror->rawCommand('UNLINK', $mirrorPrefix.$entry['key']);
                            Cache::changed();
                        } catch (\Throwable $error) {
                            Cache::changed(true);
                            throw $error;
                        }
                    }
                    $removed += $deleted;
                    $freed += $deleted ? $entry['bytes'] : 0;
                });
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
        file_put_contents($directory.'/complete', (string) time());
        $this->line(json_encode(['status' => 'reclaimed', 'protected_readbacks' => count($protected), 'removed' => $removed, 'estimated_bytes' => $freed, 'backup_sha256' => hash_file('sha256', $directory.'/removed.jsonl')], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
