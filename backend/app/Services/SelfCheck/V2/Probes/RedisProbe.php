<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2\Probes;

use App\Services\SelfCheck\V2\Contracts\ProbeInterface;
use App\Services\SelfCheck\V2\DTO\ProbeResult;
use Illuminate\Support\Facades\Redis;

final class RedisProbe implements ProbeInterface
{
    public function name(): string
    {
        return 'redis';
    }

    public function probe(bool $verbose = false): array
    {
        $cacheStore = (string) config('cache.default', 'array');
        $cacheDriver = (string) config("cache.stores.{$cacheStore}.driver", $cacheStore);
        $queueDriver = (string) config('queue.default', 'sync');

        if (!($cacheDriver === 'redis' || $queueDriver === 'redis')) {
            return (new ProbeResult(true, '', '', ['skipped' => true, 'reason' => 'redis_not_in_use']))->toArray($verbose);
        }

        $t0 = microtime(true);
        try {
            $pong = Redis::connection()->ping();
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $ok = ((string) $pong === 'PONG' || $pong === true);

            return (new ProbeResult($ok, $ok ? '' : 'REDIS_UNAVAILABLE', $ok ? '' : 'ping failed', [
                'latency_ms' => $ms,
                'client' => (string) config('database.redis.client', 'redis'),
            ]))->toArray($verbose);
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);
            return (new ProbeResult(false, 'REDIS_UNAVAILABLE', (string) $e->getMessage(), ['latency_ms' => $ms]))->toArray($verbose);
        }
    }
}
