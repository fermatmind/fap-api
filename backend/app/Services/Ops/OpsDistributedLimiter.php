<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\Redis;

class OpsDistributedLimiter
{
    public static function hit(string $key, int $ttl = 60): int
    {
        $count = (int) Redis::incr($key);

        if ($count === 1) {
            Redis::expire($key, $ttl);
        }

        return $count;
    }

    public static function tooMany(string $key, int $max): bool
    {
        return (int) Redis::get($key) > $max;
    }

    public static function attempts(string $key): int
    {
        return (int) Redis::get($key);
    }

    public static function clear(string $key): void
    {
        Redis::del($key);
    }
}
