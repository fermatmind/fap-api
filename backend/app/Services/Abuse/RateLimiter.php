<?php

namespace App\Services\Abuse;

use Illuminate\Support\Facades\Cache;

class RateLimiter
{
    public function limit(string $envKey, int $default): int
    {
        $raw = env($envKey);
        if (is_numeric($raw)) {
            $val = (int) $raw;
            return $val >= 0 ? $val : $default;
        }

        return $default;
    }

    public function hit(string $key, int $limit, int $seconds): bool
    {
        if ($limit <= 0) {
            return true;
        }

        $cacheKey = 'rate:' . $key;
        $current = (int) Cache::get($cacheKey, 0);
        if ($current >= $limit) {
            return false;
        }

        Cache::put($cacheKey, $current + 1, $seconds);
        return true;
    }
}
