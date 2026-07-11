<?php

declare(strict_types=1);

namespace App\Services\Career;

use Illuminate\Support\Facades\Cache;

final class CareerRuntimeSloService
{
    public const SAMPLE_KEY = 'career:runtime-slo:samples:v1';

    /** @param array<string, mixed> $context */
    public function record(string $surface, int $status, float $durationMs, array $context = []): void
    {
        $lock = Cache::lock(self::SAMPLE_KEY.':lock', 5);
        if (! $lock->get()) {
            return;
        }

        try {
            $samples = Cache::get(self::SAMPLE_KEY, []);
            $samples = is_array($samples) ? $samples : [];
            $samples[] = [
                'at' => now()->timestamp,
                'surface' => $surface,
                'status' => $status,
                'duration_ms' => round(max(0, $durationMs), 3),
                'context' => $context,
            ];
            Cache::put(self::SAMPLE_KEY, array_slice($samples, -2000), now()->addHours(2));
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function evaluate(array $snapshot = []): array
    {
        $cutoff = now()->subMinutes(10)->timestamp;
        $samples = array_values(array_filter((array) Cache::get(self::SAMPLE_KEY, []), static fn (mixed $sample): bool => is_array($sample) && (int) ($sample['at'] ?? 0) >= $cutoff
        ));
        $api = array_values(array_filter($samples, static fn (array $sample): bool => ($sample['surface'] ?? null) === 'career_directory_api'));
        $durations = array_map(static fn (array $sample): float => (float) ($sample['duration_ms'] ?? 0), $api);
        sort($durations);
        $failures = count(array_filter($api, static fn (array $sample): bool => (int) ($sample['status'] ?? 0) >= 500));
        $failureRate = $api === [] ? 0.0 : $failures / count($api);
        $p95 = $durations === [] ? 0.0 : $durations[(int) floor((count($durations) - 1) * 0.95)];

        $alerts = [];
        if ($failureRate > 0.01) {
            $alerts[] = 'career_api_5xx_rate_above_1_percent';
        }
        if ($p95 > 800) {
            $alerts[] = 'career_api_warm_p95_above_800ms';
        }
        if ((float) ($snapshot['last_rebuild_ms'] ?? 0) > 5000) {
            $alerts[] = 'career_cache_rebuild_above_5s';
        }
        if (($snapshot['false_empty'] ?? false) === true) {
            $alerts[] = 'career_page_false_empty';
        }
        if (($snapshot['locale_count_mismatch'] ?? false) === true) {
            $alerts[] = 'career_locale_count_mismatch';
        }
        if (($snapshot['cache_stale'] ?? false) === true) {
            $alerts[] = 'career_cache_age_exceeded';
        }
        if (($snapshot['smoke_failed'] ?? false) === true) {
            $alerts[] = 'career_release_smoke_failed';
        }

        return [
            'sample_count' => count($api),
            'api_5xx_rate' => round($failureRate, 4),
            'warm_p95_ms' => round($p95, 3),
            'alerts' => $alerts,
            'status' => $alerts === [] ? 'pass' : 'alert',
        ];
    }
}
