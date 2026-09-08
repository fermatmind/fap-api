<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Support\PublicProjectionCache as Projection;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;

/** Same-host public namespace migration. Never changes the default connection. */
final class PublicProjectionMigration
{
    public const INTEGRITY_FAILURE = 1001;

    public static function headroom(int $additionalBytes = 0): void
    {
        if (PHP_OS_FAMILY !== 'Linux' && app()->environment('testing')) {
            return;
        }
        $memory = @file_get_contents('/proc/meminfo');
        if (! is_string($memory) || ! preg_match('/^MemAvailable:\s+(\d+) kB/m', $memory, $match)
            || (int) $match[1] * 1024 < (int) config('public_projection_cache.minimum_available_bytes') + $additionalBytes) {
            throw new \RuntimeException('Insufficient host memory for public projection candidate.');
        }
    }

    /** @return array{0:\Redis,1:string} */
    public static function connection(bool $target): array
    {
        $store = Cache::store($target ? 'public_projection' : null)->getStore();
        if (! $store instanceof RedisStore || ! ($client = $store->connection()->client()) instanceof \Redis) {
            throw new \RuntimeException('Public projections require phpredis stores.');
        }

        return [$client, (string) $client->getOption(\Redis::OPT_PREFIX).$store->getPrefix()];
    }

    /** Yield physical keys without touching queue/limiter/lock namespaces. */
    public function keys(\Redis $redis, string $prefix): \Generator
    {
        $cursor = null;
        do {
            $keys = $redis->scan($cursor, $prefix.'*', 100);
            foreach ($keys ?: [] as $key) {
                if (str_starts_with($key, $prefix) && Projection::selected(substr($key, strlen($prefix)))) {
                    yield $key;
                }
            }
        } while ($cursor !== 0);
    }

    public function prepare(): void
    {
        [$source] = self::connection(false);
        [$target] = self::connection(true);
        $info = $target->info('memory');
        if (($source->info('server')['run_id'] ?? null) === ($target->info('server')['run_id'] ?? null)
            || (int) ($info['maxmemory'] ?? 0) !== (int) config('public_projection_cache.maxmemory_bytes')
            || ($info['maxmemory_policy'] ?? null) !== 'noeviction'
            || (int) ($target->info('persistence')['aof_enabled'] ?? 0) !== 1) {
            throw new \RuntimeException('Public projection Redis isolation contract failed.');
        }
        self::headroom();
        Projection::mutation(function (): void {
            $state = Projection::state();
            if (! is_file(Projection::statePath())) {
                Projection::writeState([...$state, 'epoch' => 0, 'mirror_dirty' => false]);
            }
        }, true);
    }

    public function activate(): array
    {
        $this->prepare();
        if (config('public_projection_cache.phase') !== 'activate') {
            return ['status' => 'prepared'];
        }

        return $this->synchronize(false);
    }

    /** Copies live authoritative bytes and validates both namespaces before a read switch. */
    public function synchronize(bool $rollback): array
    {
        $state = Projection::mutation(function () use ($rollback): array {
            $state = Projection::state();
            if ($state['mode'] === 'isolated') {
                throw new \RuntimeException('Legacy cache retired; code rollback must retain the public store.');
            }
            if (! $rollback && $state['mode'] === 'legacy') {
                $state = [...$state, 'mode' => 'mirror', 'epoch' => 0, 'mirror_dirty' => true];
                Projection::writeState($state);
            }

            return $state;
        }, true);
        $reverse = $state['mode'] === 'primary';
        if ((! $rollback && $reverse) || ($rollback && ! $reverse)) {
            return ['status' => $state['mode']];
        }
        [$source, $prefix] = self::connection($reverse);
        [$destination, $targetPrefix] = self::connection(! $reverse);
        self::headroom(max(0, (int) $source->info('memory')['used_memory'] * 2 - (int) $destination->info('memory')['used_memory']));
        $deadline = microtime(true) + 180;
        $count = 0;
        foreach ($this->keys($source, $prefix) as $key) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Public projection copy budget exhausted; serving store retained.');
            }
            Projection::mutation(function () use ($source, $destination, $key, $prefix, $targetPrefix): void {
                $dump = $source->rawCommand('DUMP', $key);
                $ttl = $source->rawCommand('PTTL', $key);
                $targetKey = $targetPrefix.substr($key, strlen($prefix));
                if (! is_string($dump) || $ttl === -2 || $ttl === 0) {
                    $destination->rawCommand('UNLINK', $targetKey);

                    return;
                }
                self::headroom((int) ($destination->info('memory')['used_memory_rss'] ?? 0));
                $idle = $source->rawCommand('OBJECT', 'IDLETIME', $key);
                $result = $destination->rawCommand('RESTORE', $targetKey, max(0, $ttl), $dump, 'REPLACE', 'IDLETIME', max(0, (int) $idle));
                if ($result !== true && $result !== 'OK') {
                    throw new \RuntimeException('Public projection copy failed.');
                }
            });
            $count++;
        }
        if ($count === 0) {
            throw new \RuntimeException('Empty public projection source; switch refused.');
        }
        // A withdrawal during a previous interrupted copy must not survive as an orphan.
        foreach ($this->keys($destination, $targetPrefix) as $key) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Public projection verification budget exhausted.');
            }
            Projection::mutation(function () use ($source, $destination, $key, $prefix, $targetPrefix): void {
                if ($source->rawCommand('EXISTS', $prefix.substr($key, strlen($targetPrefix))) === 0) {
                    $destination->rawCommand('UNLINK', $key);
                }
            });
        }
        // Exercise durable compaction before switching reads. The original remains
        // live throughout; reserve a full destination RSS for copy-on-write.
        $rss = (int) ($destination->info('memory')['used_memory_rss'] ?? 0);
        self::headroom($rss);
        $persistence = $destination->info('persistence');
        if (! ($persistence['aof_rewrite_in_progress'] ?? 0)) {
            $destination->bgrewriteaof();
        }
        do {
            self::headroom();
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Public projection persistence verification timed out.');
            }
            usleep(100000);
            $persistence = $destination->info('persistence');
        } while ($persistence['aof_rewrite_in_progress'] ?? 0);
        if (($persistence['aof_last_bgrewrite_status'] ?? null) !== 'ok'
            || ($persistence['aof_last_write_status'] ?? null) !== 'ok') {
            throw new \RuntimeException('Public projection persistence failed; original store retained.');
        }
        $epoch = $this->verify($source, $prefix, $destination, $targetPrefix, $deadline);
        Projection::mutation(function () use ($epoch, $rollback): void {
            $state = Projection::state();
            if (($state['mirror_dirty'] ?? true) || (int) ($state['epoch'] ?? 0) !== $epoch) {
                throw new \RuntimeException('Concurrent publication changed migration snapshot; switch refused.');
            }
            self::headroom();
            Projection::writeState([...$state, 'mode' => $rollback ? 'mirror' : 'primary',
                'primary_since' => $rollback ? null : time(), 'accepted_releases' => []]);
        });

        return ['status' => $rollback ? 'mirror' : 'primary', 'verified_keys' => $count, 'destination_rss_bytes' => $rss,
            'rewrite_cow_bytes' => (int) ($persistence['aof_last_cow_size'] ?? 0)];
    }

    private function verify(\Redis $source, string $prefix, \Redis $destination, string $targetPrefix, float $deadline): int
    {
        $epoch = Projection::mutation(function (): int {
            $state = Projection::state();
            Projection::writeState([...$state, 'mirror_dirty' => false]);

            return (int) ($state['epoch'] ?? 0);
        });
        foreach ([[$source, $prefix, $destination, $targetPrefix], [$destination, $targetPrefix, $source, $prefix]] as [$left, $leftPrefix, $right, $rightPrefix]) {
            foreach ($this->keys($left, $leftPrefix) as $key) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Public projection verification budget exhausted.');
                }
                Projection::mutation(function () use ($left, $right, $key, $leftPrefix, $rightPrefix): void {
                    $other = $rightPrefix.substr($key, strlen($leftPrefix));
                    $logical = substr($key, strlen($leftPrefix));
                    if (($pointerBase = Projection::pointerBase($logical)) !== null) {
                        $raw = $left->rawCommand('GET', $key);
                        $version = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : null;
                        if (! is_string($version) || ! $left->rawCommand('EXISTS', $leftPrefix.$pointerBase.':versions:'.$version)) {
                            throw new \RuntimeException('Public projection active/LKG payload missing.');
                        }
                    }

                    $dump = $left->rawCommand('DUMP', $key);
                    if ($dump !== $right->rawCommand('DUMP', $other)) {
                        throw new \RuntimeException('Public projection mirror differs; switch refused.');
                    }
                    $a = $left->rawCommand('PTTL', $key);
                    $b = $right->rawCommand('PTTL', $other);
                    if (($a < 0 || $b < 0) ? $a !== $b : abs($a - $b) > 1000) {
                        throw new \RuntimeException('Public projection expiry differs; switch refused.');
                    }
                });
            }
        }

        return $epoch;
    }

    public function accept(string $sha): void
    {
        if (! preg_match('/^[a-f0-9]{40}$/D', $sha)) {
            throw new \InvalidArgumentException('Invalid accepted release SHA.');
        }
        Projection::mutation(function () use ($sha): void {
            $state = Projection::state();
            if ($state['mode'] !== 'primary' || ($state['mirror_dirty'] ?? true)) {
                return;
            }
            $releases = array_values(array_unique([...($state['accepted_releases'] ?? []), $sha]));
            Projection::writeState([...$state, 'accepted_releases' => array_slice($releases, -2)]);
        });
    }

    public function retire(): array
    {
        $initial = Projection::state();
        if (in_array($initial['mode'], ['primary', 'isolated'], true)) {
            try {
                [$current, $prefix] = self::connection(true);
                $deadline = microtime(true) + 20;
                $references = 0;
                foreach ($this->keys($current, $prefix) as $key) {
                    if (microtime(true) >= $deadline) {
                        throw new \RuntimeException('Public integrity verification exceeded budget.');
                    }
                    $logical = substr($key, strlen($prefix));
                    if (($pointerBase = Projection::pointerBase($logical)) !== null) {
                        $references++;
                        Projection::mutation(function () use ($current, $prefix, $key, $pointerBase): void {
                            $raw = $current->rawCommand('GET', $key);
                            if ($raw === false) {
                                return; // Concurrent withdrawal removed this reference.
                            }
                            $version = @unserialize($raw, ['allowed_classes' => false]);
                            if (! is_string($version) || ! $current->rawCommand('EXISTS', $prefix.$pointerBase.':versions:'.$version)) {
                                throw new \RuntimeException('Public active/LKG payload missing.');
                            }
                        });
                    }
                }
                if ($references === 0) {
                    throw new \RuntimeException('Public projection references are missing.');
                }
                app(\App\Services\Ops\CacheLifecycleAlerts::class)->observe('projection_integrity', true);
            } catch (\Throwable $error) {
                app(\App\Services\Ops\CacheLifecycleAlerts::class)->observe('projection_integrity', false, true);
                throw new \RuntimeException('Public projection integrity failed.', self::INTEGRITY_FAILURE, $error);
            }
        }
        $epoch = null;
        if ($initial['mode'] === 'primary' && count(array_unique($initial['accepted_releases'] ?? [])) >= 2
            && time() - (int) ($initial['primary_since'] ?? time()) >= (int) config('public_projection_cache.observation_seconds')) {
            [$current, $currentPrefix] = self::connection(true);
            [$old, $oldPrefix] = self::connection(false);
            $epoch = $this->verify($current, $currentPrefix, $old, $oldPrefix, microtime(true) + 30);
        }
        $allowed = Projection::mutation(function () use ($epoch): bool {
            $state = Projection::state();
            if ($state['mode'] === 'isolated') {
                return true;
            }
            if ($state['mode'] !== 'primary' || ($state['mirror_dirty'] ?? true) || $epoch !== (int) ($state['epoch'] ?? 0)
                || count(array_unique($state['accepted_releases'] ?? [])) < 2
                || time() - (int) ($state['primary_since'] ?? time()) < (int) config('public_projection_cache.observation_seconds')) {
                return false;
            }
            Projection::writeState([...$state, 'mode' => 'isolated']);

            return true;
        });
        if (! $allowed) {
            return ['status' => 'observing'];
        }

        return $this->retireLegacy();
    }

    private function retireLegacy(): array
    {
        $root = storage_path('app/private/career-cache-retention');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($root, 0770);
        $lease = fopen(storage_path('app/private/career-cache-retention.lock'), 'c');
        if ($lease === false || ! flock($lease, LOCK_EX | LOCK_NB)) {
            return ['status' => 'retention_busy'];
        }
        $stream = null;
        try {
            $policy = new CareerCacheBackupRetention;
            $bytes = $policy->rotateAndMeasure($root, time());
            $policy->assertHeadroom($bytes, 1048576);
            if (disk_free_space($root) < 1342177280) {
                throw new \RuntimeException('Insufficient disk for retired projection backup.');
            }
            $directory = $root.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
            mkdir($directory, 0770);
            $stream = fopen($directory.'/removed.jsonl', 'xb');
            chmod($directory.'/removed.jsonl', 0660);
            [$old, $prefix] = self::connection(false);
            $deadline = microtime(true) + 30;
            $removed = 0;
            $written = 0;
            foreach ($this->keys($old, $prefix) as $key) {
                if (microtime(true) >= $deadline || $removed >= 1000 || $written >= 536870912) {
                    break;
                }
                $dump = $old->rawCommand('DUMP', $key);
                if (! is_string($dump)) {
                    continue;
                }
                $record = json_encode(['key' => substr($key, strlen($prefix)), 'dump' => base64_encode($dump),
                    'ttl_ms' => $old->rawCommand('PTTL', $key)], JSON_THROW_ON_ERROR)."\n";
                $policy->assertHeadroom($bytes + $written, strlen($record) + 64);
                if ($written + strlen($record) > 536870912) {
                    break;
                }
                if (fwrite($stream, $record) !== strlen($record) || ! fflush($stream) || ! fsync($stream)) {
                    throw new \RuntimeException('Retired projection backup failed.');
                }
                $written += strlen($record);
                $removed += (int) $old->rawCommand('UNLINK', $key);
            }
            file_put_contents($directory.'/complete', (string) time());

            return ['status' => 'isolated', 'removed_keys' => $removed];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }
}
