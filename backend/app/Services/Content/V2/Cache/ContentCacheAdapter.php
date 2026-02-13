<?php

declare(strict_types=1);

namespace App\Services\Content\V2\Cache;

use App\Services\Content\V2\Contracts\CacheAdapterInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ContentCacheAdapter implements CacheAdapterInterface
{
    private static int $hotRedisDisabledUntil = 0;

    /**
     * @var array<string, int>
     */
    private static array $lastFailureLogAt = [];

    public function __construct(private readonly string $store = 'hot_redis')
    {
    }

    public function get(string $key): mixed
    {
        if ($this->canUseConfiguredStore()) {
            try {
                return Cache::store($this->store)->get($key);
            } catch (Throwable $e) {
                $this->tripHotRedisBreaker();
                $this->logCacheFailure('get@' . $this->store, $key, $e);
            }
        }

        try {
            return Cache::store()->get($key);
        } catch (Throwable $e) {
            $this->logCacheFailure('get@default', $key, $e);

            return null;
        }
    }

    public function put(string $key, mixed $value, int $ttlSeconds): bool
    {
        if ($this->canUseConfiguredStore()) {
            try {
                return (bool) Cache::store($this->store)->put($key, $value, $ttlSeconds);
            } catch (Throwable $e) {
                $this->tripHotRedisBreaker();
                $this->logCacheFailure('put@' . $this->store, $key, $e);
            }
        }

        try {
            return (bool) Cache::store()->put($key, $value, $ttlSeconds);
        } catch (Throwable $e) {
            $this->logCacheFailure('put@default', $key, $e);

            return false;
        }
    }

    public function forget(string $key): bool
    {
        if ($this->canUseConfiguredStore()) {
            try {
                return (bool) Cache::store($this->store)->forget($key);
            } catch (Throwable $e) {
                $this->tripHotRedisBreaker();
                $this->logCacheFailure('forget@' . $this->store, $key, $e);
            }
        }

        try {
            return (bool) Cache::store()->forget($key);
        } catch (Throwable $e) {
            $this->logCacheFailure('forget@default', $key, $e);

            return false;
        }
    }

    private function canUseConfiguredStore(): bool
    {
        if ($this->store !== 'hot_redis') {
            return true;
        }

        return time() >= self::$hotRedisDisabledUntil;
    }

    private function tripHotRedisBreaker(): void
    {
        if ($this->store === 'hot_redis') {
            self::$hotRedisDisabledUntil = time() + 60;
        }
    }

    private function logCacheFailure(string $op, string $key, Throwable $e): void
    {
        $parts = explode('@', $op, 2);
        $operation = $parts[0] ?? $op;
        $store = $parts[1] ?? $this->store;
        $bucket = $operation . '@' . $store;
        $now = time();

        $lastLoggedAt = self::$lastFailureLogAt[$bucket] ?? 0;
        if (($now - $lastLoggedAt) < 60) {
            return;
        }
        self::$lastFailureLogAt[$bucket] = $now;

        Log::warning('content_cache_adapter_failed', [
            'op' => $operation,
            'key' => $key,
            'store' => $store,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'hot_redis_disabled_until' => self::$hotRedisDisabledUntil > 0 ? self::$hotRedisDisabledUntil : null,
        ]);
    }
}
