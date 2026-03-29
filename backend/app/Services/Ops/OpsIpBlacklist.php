<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\Redis;

class OpsIpBlacklist
{
    public static function isBlocked(string $ip): bool
    {
        return (bool) Redis::sismember('ops:ip:blacklist', $ip);
    }

    public static function block(string $ip): void
    {
        Redis::sadd('ops:ip:blacklist', $ip);
    }
}
