<?php

declare(strict_types=1);

namespace App\Services\Content\V2\Cache;

use App\Services\Content\V2\Contracts\CacheAdapterInterface;
use Illuminate\Support\Facades\Cache;

final class ContentCacheAdapter implements CacheAdapterInterface
{
    public function __construct(private readonly string $store = 'hot_redis')
    {
    }

    public function get(string $key): mixed
    {
        try {
            return Cache::store($this->store)->get($key);
        } catch (\Throwable) {
            return Cache::store()->get($key);
        }
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        try {
            Cache::store($this->store)->put($key, $value, $ttlSeconds);
        } catch (\Throwable) {
            Cache::store()->put($key, $value, $ttlSeconds);
        }
    }
}
