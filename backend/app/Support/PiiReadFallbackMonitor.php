<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

final class PiiReadFallbackMonitor
{
    private const CACHE_PREFIX = 'pii_read_fallback';

    private const TTL_DAYS = 14;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function record(string $metric, bool $fallbackHit): void
    {
        $normalizedMetric = $this->normalizeMetric($metric);
        if ($normalizedMetric === '') {
            return;
        }

        $day = now()->format('Ymd');

        try {
            $total = $this->incrementCounter($day, $normalizedMetric, 'total', 1);
            $fallback = $fallbackHit
                ? $this->incrementCounter($day, $normalizedMetric, 'fallback', 1)
                : $this->incrementCounter($day, $normalizedMetric, 'fallback', 0);

            if ($fallbackHit && ($fallback === 1 || $fallback % 50 === 0)) {
                $rate = $total > 0 ? round($fallback / $total, 4) : 0.0;
                $this->logger->warning('PII_READ_FALLBACK_HIT', [
                    'metric' => $normalizedMetric,
                    'day' => $day,
                    'fallback' => $fallback,
                    'total' => $total,
                    'rate' => $rate,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('PII_READ_FALLBACK_MONITOR_FAILED', [
                'metric' => $normalizedMetric,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * @return array{metric:string,day:string,total:int,fallback:int,rate:float}
     */
    public function snapshot(string $metric, ?string $day = null): array
    {
        $normalizedMetric = $this->normalizeMetric($metric);
        $day = $this->normalizeDay($day);
        $total = (int) $this->cache->get($this->cacheKey($day, $normalizedMetric, 'total'), 0);
        $fallback = (int) $this->cache->get($this->cacheKey($day, $normalizedMetric, 'fallback'), 0);

        return [
            'metric' => $normalizedMetric,
            'day' => $day,
            'total' => max(0, $total),
            'fallback' => max(0, $fallback),
            'rate' => $total > 0 ? round($fallback / $total, 4) : 0.0,
        ];
    }

    /**
     * @param  list<string>  $metrics
     * @return list<array{metric:string,day:string,total:int,fallback:int,rate:float}>
     */
    public function snapshotMany(array $metrics, ?string $day = null): array
    {
        $out = [];
        foreach ($metrics as $metric) {
            $out[] = $this->snapshot((string) $metric, $day);
        }

        return $out;
    }

    private function incrementCounter(string $day, string $metric, string $counter, int $step): int
    {
        $key = $this->cacheKey($day, $metric, $counter);
        $expiresAt = now()->addDays(self::TTL_DAYS);

        if ($this->cache->get($key) === null) {
            $this->cache->put($key, 0, $expiresAt);
        }

        if ($step > 0) {
            $value = (int) $this->cache->increment($key, $step);
            $this->cache->put($key, $value, $expiresAt);

            return $value;
        }

        return (int) $this->cache->get($key, 0);
    }

    private function cacheKey(string $day, string $metric, string $counter): string
    {
        return self::CACHE_PREFIX.':'.$day.':'.$metric.':'.$counter;
    }

    private function normalizeMetric(string $metric): string
    {
        $metric = strtolower(trim($metric));
        $metric = preg_replace('/[^a-z0-9_.-]+/', '_', $metric) ?? '';

        return trim($metric, '_');
    }

    private function normalizeDay(?string $day): string
    {
        $day = trim((string) $day);
        if ($day !== '' && preg_match('/^\d{8}$/', $day) === 1) {
            return $day;
        }

        return now()->format('Ymd');
    }
}
