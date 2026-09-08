<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/** Routes only declared public projections; queue, locks and unknown keys stay put. */
final class PublicProjectionCache
{
    public static function selected(string $key): bool
    {
        return (str_starts_with($key, 'career:public-authority:') && ! str_ends_with($key, ':warm-dispatch'))
            || in_array($key, ['seo:sitemap-source:v1:fresh', 'seo:sitemap-source:v1:stale', 'seo:sitemap-source:warm-fingerprint:v1'], true);
    }

    public static function statePath(): string
    {
        return storage_path('app/ops/cache-lifecycle/public_projection.json');
    }

    public static function state(): array
    {
        $path = self::statePath();
        if (! is_file($path)) {
            if (config('public_projection_cache.phase') === 'activate') {
                throw new \RuntimeException('Public projection migration state unavailable.');
            }

            return ['version' => 1, 'mode' => 'legacy'];
        }
        $state = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($state) || ($state['version'] ?? null) !== 1
            || ! in_array($state['mode'] ?? null, ['legacy', 'mirror', 'primary', 'isolated'], true)) {
            throw new \RuntimeException('Public projection migration state invalid.');
        }

        return $state;
    }

    /** Called under the shared mutation lock by the migration command. */
    public static function writeState(array $state): void
    {
        $path = self::statePath();
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path), 0770);
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Public projection state write failed.');
        }
        chmod($temporary, 0660);
        if (! rename($temporary, $path)) {
            throw new \RuntimeException('Public projection state activation failed.');
        }
    }

    public static function store(?string $name = null): \Illuminate\Contracts\Cache\Repository
    {
        if ($name !== null) {
            return Cache::store($name);
        }

        return Cache::store(in_array(self::state()['mode'], ['primary', 'isolated'], true) ? 'public_projection' : null);
    }

    private static int $lockDepth = 0;

    public static function mutation(callable $operation, bool $force = false): mixed
    {
        if (self::$lockDepth > 0) {
            return $operation();
        }
        $path = dirname(self::statePath()).'/public_projection.lock';
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path), 0770);
        $lease = fopen($path, 'c');
        if ($lease === false) {
            throw new \RuntimeException('Public projection mutation lock unavailable.');
        }
        chmod($path, 0660);
        $deadline = microtime(true) + 10;
        try {
            while (! flock($lease, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Public projection mutation lock timed out.');
                }
                usleep(10000);
            }
            self::$lockDepth++;
            try {
                return $operation();
            } finally {
                self::$lockDepth--;
            }
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    public static function changed(bool $dirty = false): void
    {
        $state = self::state();
        if ($state['mode'] !== 'legacy') {
            self::writeState([...$state, 'epoch' => (int) ($state['epoch'] ?? 0) + 1,
                'mirror_dirty' => $dirty || ($state['mirror_dirty'] ?? false)]);
        }
    }

    public static function __callStatic(string $method, array $arguments): mixed
    {
        $key = $arguments[0] ?? null;
        if ($method === 'lock' || ! is_string($key) || ! self::selected($key)) {
            return Cache::$method(...$arguments);
        }
        if (in_array($method, ['get', 'has', 'missing'], true)) {
            return self::state()['mode'] === 'legacy' ? Cache::$method(...$arguments) : self::store()->$method(...$arguments);
        }
        if (! in_array($method, ['put', 'forever', 'forget', 'add'], true)) {
            throw new \LogicException('Unsupported public projection cache operation.');
        }

        return self::mutation(function () use ($method, $arguments): mixed {
            $state = self::state();
            $mode = $state['mode'];
            if ($mode === 'legacy') {
                return Cache::$method(...$arguments);
            }
            if ($method !== 'forget') {
                \App\Services\Career\PublicProjectionMigration::headroom();
            }
            if ($mode === 'isolated') {
                return self::store()->$method(...$arguments);
            }
            $primary = self::store();
            $secondary = Cache::store($mode === 'mirror' ? 'public_projection' : null);
            if ($method === 'add' && $primary->has($arguments[0])) {
                return false;
            }
            $writeMethod = $method === 'add' ? 'put' : $method;
            try {
                // Write the unread secondary first. Its failure must not alter the
                // serving copy. Any partial mirror blocks migration verification.
                if ($method === 'forget') {
                    // Withdrawal affects the serving copy immediately, even if the mirror is down.
                    $result = $primary->forget(...$arguments);
                    $secondary->forget(...$arguments);
                    self::changed();

                    return $result;
                }
                $secondaryResult = $secondary->$writeMethod(...$arguments);
                if ($writeMethod !== 'forget' && $secondaryResult !== true) {
                    throw new \RuntimeException('Public projection mirror write failed.');
                }
                $result = $primary->$method(...$arguments);
                if ($method !== 'forget' && $result !== true) {
                    throw new \RuntimeException('Public projection primary write failed.');
                }

                self::changed();

                return $result;
            } catch (\Throwable $error) {
                self::changed(true);
                app(\App\Services\Ops\CacheLifecycleAlerts::class)->observe('redis_capacity', false, true);
                throw $error;
            }
        });
    }
}
