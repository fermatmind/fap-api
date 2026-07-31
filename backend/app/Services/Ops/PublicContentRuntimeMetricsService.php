<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\PublicContentRuntimeDaily;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PublicContentRuntimeMetricsService
{
    private const KEY_PREFIX = 'public-content-runtime:v1';

    /**
     * @return array{family:string,priority:string}|null
     */
    public function resolveRoute(string $routeTemplate, ?string $framework = null): ?array
    {
        $routes = (array) config('public_content_observability.routes', []);
        $route = $routes[$routeTemplate] ?? null;
        if (! is_array($route)) {
            return null;
        }

        if (($route['framework_family'] ?? false) === true) {
            $frameworkKey = strtolower(trim((string) $framework));
            $route = config("public_content_observability.framework_families.{$frameworkKey}");
        }

        if (! is_array($route)) {
            return null;
        }

        $family = trim((string) ($route['family'] ?? ''));
        $priority = trim((string) ($route['priority'] ?? ''));
        if ($family === '' || ! in_array($priority, ['L1', 'L2', 'L3'], true)) {
            return null;
        }

        return ['family' => $family, 'priority' => $priority];
    }

    public function canonicalLocale(?string $locale): string
    {
        $candidate = trim((string) $locale);
        foreach ((array) config('public_content_observability.allowed_locales', []) as $allowed) {
            if (strcasecmp($candidate, (string) $allowed) === 0) {
                return (string) $allowed;
            }
        }

        return 'unknown';
    }

    public function record(
        string $routeFamily,
        string $priority,
        string $locale,
        int $statusCode,
        float $durationMs,
        bool $timedOut = false,
    ): void {
        if (! (bool) config('public_content_observability.enabled', true)) {
            return;
        }

        $catalog = $this->familyCatalog();
        if (($catalog[$routeFamily] ?? null) !== $priority) {
            return;
        }

        $locale = $this->canonicalLocale($locale);
        $minute = CarbonImmutable::now('UTC')->startOfMinute();
        $minuteId = $minute->format('YmdHi');
        $metricKey = $this->metricKey($minuteId, $routeFamily, $locale);
        $store = $this->store();
        $lock = $store->lock($metricKey.':lock', 2);

        if (! $lock->get()) {
            return;
        }

        try {
            $payload = $store->get($metricKey, $this->emptyMinute(
                $minuteId,
                $routeFamily,
                $priority,
                $locale,
            ));
            $payload = is_array($payload) ? $payload : $this->emptyMinute(
                $minuteId,
                $routeFamily,
                $priority,
                $locale,
            );

            $durationMs = round(max(0, $durationMs), 3);
            $payload['request_count'] = (int) ($payload['request_count'] ?? 0) + 1;
            $payload['duration_count'] = (int) ($payload['duration_count'] ?? 0) + 1;
            $payload['duration_sum_ms'] = round((float) ($payload['duration_sum_ms'] ?? 0) + $durationMs, 3);
            $payload['duration_max_ms'] = max((float) ($payload['duration_max_ms'] ?? 0), $durationMs);
            $payload['duration_histogram'] = $this->incrementHistogram(
                (array) ($payload['duration_histogram'] ?? []),
                $durationMs,
            );

            $counter = $this->statusCounter($statusCode, $timedOut);
            $payload[$counter] = (int) ($payload[$counter] ?? 0) + 1;
            if ($counter === 'success_count') {
                $payload['last_success_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            } else {
                $payload['last_failure_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            }

            $store->put($metricKey, $payload, CarbonImmutable::now('UTC')->addDays($this->minuteRetentionDays()));
        } finally {
            $lock->release();
        }

        $this->initializeRollupCursor($store, $minute);
        $this->indexMetricKey($store, $minuteId, $metricKey);
    }

    public function rollupPending(): void
    {
        if (! (bool) config('public_content_observability.enabled', true)) {
            return;
        }

        $store = $this->store();
        $currentMinute = CarbonImmutable::now('UTC')->startOfMinute();
        $lastClosedMinute = $currentMinute->subMinutes($this->rollupLagMinutes());
        $cursorId = (string) $store->get(self::KEY_PREFIX.':rollup-cursor', '');
        $cursor = $this->parseMinute($cursorId);
        $oldest = $currentMinute->subDays($this->minuteRetentionDays())->startOfMinute();
        $start = $cursor?->addMinute() ?? $lastClosedMinute;
        if ($start->lessThan($oldest)) {
            $start = $oldest;
        }

        $processed = 0;
        while ($start->lessThanOrEqualTo($lastClosedMinute) && $processed < 1440) {
            $minuteId = $start->format('YmdHi');
            $this->rollupMinute($store, $minuteId);
            $store->put(
                self::KEY_PREFIX.':rollup-cursor',
                $minuteId,
                CarbonImmutable::now('UTC')->addDays($this->minuteRetentionDays()),
            );
            $start = $start->addMinute();
            $processed++;
        }

        $this->pruneDaily($store);
    }

    /**
     * @return array<string, mixed>
     */
    public function query(int $windowMinutes, ?string $routeFamily = null, ?string $locale = null): array
    {
        $max = max(1, (int) config('public_content_observability.query_max_minutes', 129600));
        $windowMinutes = max(1, min($windowMinutes, $max));
        $catalog = $this->familyCatalog();
        if ($routeFamily !== null && ! array_key_exists($routeFamily, $catalog)) {
            return $this->queryEnvelope($windowMinutes, [], 'invalid_route_family');
        }

        $locale = $locale === null ? null : $this->canonicalLocale($locale);
        $now = CarbonImmutable::now('UTC')->startOfMinute();
        $requestedStart = $now->subMinutes($windowMinutes - 1);
        $oldestCachedMinute = max(
            $requestedStart,
            $now->subDays($this->minuteRetentionDays())->addMinute(),
        );
        $usesDaily = $requestedStart->lessThan($oldestCachedMinute);
        $effectiveStart = $usesDaily ? $requestedStart->startOfDay() : $requestedStart;
        $cacheStart = $oldestCachedMinute;
        $groups = [];

        if ($usesDaily) {
            // Daily data owns the whole boundary day. Minute data resumes on the
            // following UTC day so the two resolutions never overlap or leave a gap.
            $dailyEnd = $oldestCachedMinute->startOfDay();
            $query = PublicContentRuntimeDaily::query()
                ->whereBetween('day', [
                    $effectiveStart->toDateString(),
                    $dailyEnd->toDateString(),
                ]);
            $this->applyFilters($query, $routeFamily, $locale);
            foreach ($query->get() as $daily) {
                $this->mergeGroup($groups, $daily->route_family, $daily->priority, $daily->locale, [
                    'request_count' => $daily->request_count,
                    'success_count' => $daily->success_count,
                    'not_found_count' => $daily->not_found_count,
                    'rate_limited_count' => $daily->rate_limited_count,
                    'client_error_count' => $daily->client_error_count,
                    'server_error_count' => $daily->server_error_count,
                    'timeout_count' => $daily->timeout_count,
                    'duration_count' => $daily->duration_count,
                    'duration_sum_ms' => $daily->duration_sum_ms,
                    'duration_max_ms' => $daily->duration_max_ms,
                    'duration_histogram' => $daily->duration_histogram,
                    'last_success_at' => $daily->last_success_at?->toIso8601String(),
                    'last_failure_at' => $daily->last_failure_at?->toIso8601String(),
                ]);
            }
            $cacheStart = $dailyEnd->addDay();
        }

        if ($cacheStart->lessThanOrEqualTo($now)) {
            $this->mergeCachedMinutes($groups, $cacheStart, $now, $routeFamily, $locale);
        }

        $items = array_values(array_map(fn (array $group): array => $this->presentGroup($group), $groups));
        usort($items, static fn (array $left, array $right): int => [
            $left['priority'],
            $left['route_family'],
            $left['locale'],
        ] <=> [
            $right['priority'],
            $right['route_family'],
            $right['locale'],
        ]);

        return $this->queryEnvelope($windowMinutes, $items, null, $effectiveStart, $usesDaily);
    }

    /** @return array<string, string> */
    public function familyCatalog(): array
    {
        $catalog = [];
        foreach ((array) config('public_content_observability.routes', []) as $route) {
            if (is_array($route) && isset($route['family'], $route['priority'])) {
                $catalog[(string) $route['family']] = (string) $route['priority'];
            }
        }
        foreach ((array) config('public_content_observability.framework_families', []) as $route) {
            if (is_array($route) && isset($route['family'], $route['priority'])) {
                $catalog[(string) $route['family']] = (string) $route['priority'];
            }
        }
        ksort($catalog);

        return $catalog;
    }

    private function store(): Repository
    {
        return Cache::store((string) config('public_content_observability.cache_store', 'redis'));
    }

    private function minuteRetentionDays(): int
    {
        return max(1, (int) config('public_content_observability.minute_retention_days', 7));
    }

    private function rollupLagMinutes(): int
    {
        return max(1, (int) config('public_content_observability.rollup_lag_minutes', 2));
    }

    /** @return array<string, mixed> */
    private function emptyMinute(string $minute, string $family, string $priority, string $locale): array
    {
        return [
            'minute' => $minute,
            'route_family' => $family,
            'priority' => $priority,
            'locale' => $locale,
            'request_count' => 0,
            'success_count' => 0,
            'not_found_count' => 0,
            'rate_limited_count' => 0,
            'client_error_count' => 0,
            'server_error_count' => 0,
            'timeout_count' => 0,
            'duration_count' => 0,
            'duration_sum_ms' => 0.0,
            'duration_max_ms' => 0.0,
            'duration_histogram' => $this->emptyHistogram(),
            'last_success_at' => null,
            'last_failure_at' => null,
        ];
    }

    /** @return array<string, int> */
    private function emptyHistogram(): array
    {
        $histogram = [];
        foreach ((array) config('public_content_observability.duration_buckets_ms', []) as $bucket) {
            $histogram[(string) (int) $bucket] = 0;
        }
        $histogram['inf'] = 0;

        return $histogram;
    }

    /** @param array<string, mixed> $histogram @return array<string, int> */
    private function incrementHistogram(array $histogram, float $durationMs): array
    {
        $histogram = array_replace($this->emptyHistogram(), $histogram);
        foreach ((array) config('public_content_observability.duration_buckets_ms', []) as $bucket) {
            if ($durationMs <= (int) $bucket) {
                $key = (string) (int) $bucket;
                $histogram[$key] = (int) $histogram[$key] + 1;

                return $histogram;
            }
        }
        $histogram['inf'] = (int) $histogram['inf'] + 1;

        return $histogram;
    }

    private function statusCounter(int $statusCode, bool $timedOut): string
    {
        if ($timedOut || in_array($statusCode, [408, 504], true)) {
            return 'timeout_count';
        }
        if ($statusCode >= 200 && $statusCode < 400) {
            return 'success_count';
        }
        if ($statusCode === 404) {
            return 'not_found_count';
        }
        if ($statusCode === 429) {
            return 'rate_limited_count';
        }
        if ($statusCode >= 500) {
            return 'server_error_count';
        }

        return 'client_error_count';
    }

    private function metricKey(string $minute, string $family, string $locale): string
    {
        return self::KEY_PREFIX.":minute:{$minute}:{$family}:".str_replace('-', '_', $locale);
    }

    private function minuteIndexKey(string $minute): string
    {
        return self::KEY_PREFIX.":minute-index:{$minute}";
    }

    private function initializeRollupCursor(Repository $store, CarbonImmutable $minute): void
    {
        $store->add(
            self::KEY_PREFIX.':rollup-cursor',
            $minute->subMinute()->format('YmdHi'),
            CarbonImmutable::now('UTC')->addDays($this->minuteRetentionDays()),
        );
    }

    private function indexMetricKey(Repository $store, string $minute, string $metricKey): void
    {
        $memberKey = self::KEY_PREFIX.':indexed:'.hash('sha256', $metricKey);
        $expiresAt = CarbonImmutable::now('UTC')->addDays($this->minuteRetentionDays());
        if (! $store->add($memberKey, true, $expiresAt)) {
            return;
        }

        $indexKey = $this->minuteIndexKey($minute);
        $lock = $store->lock($indexKey.':lock', 2);
        if (! $lock->get()) {
            $store->forget($memberKey);

            return;
        }
        try {
            $index = array_values(array_filter((array) $store->get($indexKey, []), 'is_string'));
            if (! in_array($metricKey, $index, true)) {
                $index[] = $metricKey;
            }
            $store->put($indexKey, $index, $expiresAt);
        } finally {
            $lock->release();
        }
    }

    private function parseMinute(string $minute): ?CarbonImmutable
    {
        if (preg_match('/^\d{12}$/', $minute) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('YmdHi', $minute, 'UTC') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function rollupMinute(Repository $store, string $minuteId): void
    {
        $metricKeys = array_values(array_filter(
            (array) $store->get($this->minuteIndexKey($minuteId), []),
            'is_string',
        ));
        if ($metricKeys === []) {
            return;
        }

        foreach ($this->cacheMany($store, $metricKeys) as $payload) {
            if (! is_array($payload) || ($payload['minute'] ?? null) !== $minuteId) {
                continue;
            }
            $this->persistMinute($payload);
        }
    }

    /** @param array<string, mixed> $payload */
    private function persistMinute(array $payload): void
    {
        $minute = $this->parseMinute((string) ($payload['minute'] ?? ''));
        $family = (string) ($payload['route_family'] ?? '');
        $priority = (string) ($payload['priority'] ?? '');
        $locale = $this->canonicalLocale((string) ($payload['locale'] ?? ''));
        if ($minute === null || ($this->familyCatalog()[$family] ?? null) !== $priority) {
            return;
        }

        DB::transaction(function () use ($payload, $minute, $family, $priority, $locale): void {
            $daily = PublicContentRuntimeDaily::query()
                ->where('day', $minute->toDateString())
                ->where('route_family', $family)
                ->where('locale', $locale)
                ->lockForUpdate()
                ->first();
            if ($daily === null) {
                $daily = new PublicContentRuntimeDaily([
                    'day' => $minute->toDateString(),
                    'route_family' => $family,
                    'priority' => $priority,
                    'locale' => $locale,
                    'duration_histogram' => $this->emptyHistogram(),
                    'rolled_minutes' => [],
                ]);
            }

            $rolledMinutes = array_values(array_filter((array) $daily->rolled_minutes, 'is_string'));
            $minuteId = $minute->format('YmdHi');
            if (in_array($minuteId, $rolledMinutes, true)) {
                return;
            }

            foreach ($this->counterFields() as $field) {
                $daily->{$field} = (int) $daily->{$field} + (int) ($payload[$field] ?? 0);
            }
            $daily->duration_sum_ms = round(
                (float) $daily->duration_sum_ms + (float) ($payload['duration_sum_ms'] ?? 0),
                3,
            );
            $daily->duration_max_ms = max(
                (float) $daily->duration_max_ms,
                (float) ($payload['duration_max_ms'] ?? 0),
            );
            $daily->duration_histogram = $this->mergeHistograms(
                (array) $daily->duration_histogram,
                (array) ($payload['duration_histogram'] ?? []),
            );
            $rolledMinutes[] = $minuteId;
            sort($rolledMinutes);
            $daily->rolled_minutes = $rolledMinutes;
            $daily->last_success_at = $this->latestTimestamp(
                $daily->last_success_at?->toIso8601String(),
                $payload['last_success_at'] ?? null,
            );
            $daily->last_failure_at = $this->latestTimestamp(
                $daily->last_failure_at?->toIso8601String(),
                $payload['last_failure_at'] ?? null,
            );
            $daily->save();
        }, 3);
    }

    /** @return list<string> */
    private function counterFields(): array
    {
        return [
            'request_count',
            'success_count',
            'not_found_count',
            'rate_limited_count',
            'client_error_count',
            'server_error_count',
            'timeout_count',
            'duration_count',
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right @return array<string, int> */
    private function mergeHistograms(array $left, array $right): array
    {
        $merged = $this->emptyHistogram();
        foreach (array_keys($merged) as $bucket) {
            $merged[$bucket] = (int) ($left[$bucket] ?? 0) + (int) ($right[$bucket] ?? 0);
        }

        return $merged;
    }

    private function latestTimestamp(mixed $left, mixed $right): ?CarbonImmutable
    {
        $timestamps = [];
        foreach ([$left, $right] as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            try {
                $timestamps[] = CarbonImmutable::parse($value, 'UTC');
            } catch (\Throwable) {
                // Ignore malformed in-memory telemetry rather than affecting public reads.
                continue;
            }
        }
        if ($timestamps === []) {
            return null;
        }
        usort($timestamps, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return end($timestamps) ?: null;
    }

    private function pruneDaily(Repository $store): void
    {
        $day = CarbonImmutable::now('UTC')->toDateString();
        $guard = self::KEY_PREFIX.":daily-prune:{$day}";
        if (! $store->add($guard, true, CarbonImmutable::now('UTC')->addDays(2))) {
            return;
        }

        PublicContentRuntimeDaily::query()
            ->where('day', '<', CarbonImmutable::now('UTC')
                ->subDays(max(1, (int) config('public_content_observability.daily_retention_days', 90)))
                ->toDateString())
            ->delete();
    }

    /** @param array<string, array<string, mixed>> $groups */
    private function mergeCachedMinutes(
        array &$groups,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $routeFamily,
        ?string $locale,
    ): void {
        $indexKeys = [];
        for ($cursor = $start; $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addMinute()) {
            $indexKeys[] = $this->minuteIndexKey($cursor->format('YmdHi'));
        }
        $store = $this->store();
        $metricKeys = [];
        foreach ($this->cacheMany($store, $indexKeys) as $index) {
            foreach ((array) $index as $metricKey) {
                if (is_string($metricKey)) {
                    $metricKeys[$metricKey] = true;
                }
            }
        }
        foreach ($this->cacheMany($store, array_keys($metricKeys)) as $payload) {
            if (! is_array($payload)) {
                continue;
            }
            $family = (string) ($payload['route_family'] ?? '');
            $payloadLocale = (string) ($payload['locale'] ?? 'unknown');
            if (($routeFamily !== null && $routeFamily !== $family) || ($locale !== null && $locale !== $payloadLocale)) {
                continue;
            }
            $this->mergeGroup(
                $groups,
                $family,
                (string) ($payload['priority'] ?? ''),
                $payloadLocale,
                $payload,
            );
        }
    }

    /** @param list<string> $keys @return array<string, mixed> */
    private function cacheMany(Repository $store, array $keys): array
    {
        $values = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $values += $store->many($chunk);
        }

        return $values;
    }

    /** @param Builder<PublicContentRuntimeDaily> $query */
    private function applyFilters(Builder $query, ?string $routeFamily, ?string $locale): void
    {
        if ($routeFamily !== null) {
            $query->where('route_family', $routeFamily);
        }
        if ($locale !== null) {
            $query->where('locale', $locale);
        }
    }

    /** @param array<string, array<string, mixed>> $groups @param array<string, mixed> $source */
    private function mergeGroup(array &$groups, string $family, string $priority, string $locale, array $source): void
    {
        if (($this->familyCatalog()[$family] ?? null) !== $priority) {
            return;
        }
        $key = "{$priority}|{$family}|{$locale}";
        $group = $groups[$key] ?? $this->emptyMinute('', $family, $priority, $locale);
        foreach ($this->counterFields() as $field) {
            $group[$field] = (int) ($group[$field] ?? 0) + (int) ($source[$field] ?? 0);
        }
        $group['duration_sum_ms'] = round(
            (float) ($group['duration_sum_ms'] ?? 0) + (float) ($source['duration_sum_ms'] ?? 0),
            3,
        );
        $group['duration_max_ms'] = max(
            (float) ($group['duration_max_ms'] ?? 0),
            (float) ($source['duration_max_ms'] ?? 0),
        );
        $group['duration_histogram'] = $this->mergeHistograms(
            (array) ($group['duration_histogram'] ?? []),
            (array) ($source['duration_histogram'] ?? []),
        );
        $group['last_success_at'] = $this->latestIso(
            $group['last_success_at'] ?? null,
            $source['last_success_at'] ?? null,
        );
        $group['last_failure_at'] = $this->latestIso(
            $group['last_failure_at'] ?? null,
            $source['last_failure_at'] ?? null,
        );
        $groups[$key] = $group;
    }

    private function latestIso(mixed $left, mixed $right): ?string
    {
        return $this->latestTimestamp($left, $right)?->toIso8601String();
    }

    /**
     * @return list<array{route_family:string,priority:string,locale:string,p95_ms:float,request_count:int}>
     */
    public function p95Exceedances(int $windowMinutes, float $thresholdMs): array
    {
        $result = $this->query($windowMinutes);
        if (! $result['ok']) {
            return [];
        }

        $exceedances = [];
        foreach ($result['items'] as $item) {
            $p95 = (float) ($item['p95_ms'] ?? 0);
            if ($p95 > $thresholdMs && (int) ($item['request_count'] ?? 0) > 0) {
                $exceedances[] = [
                    'route_family' => (string) $item['route_family'],
                    'priority' => (string) $item['priority'],
                    'locale' => (string) $item['locale'],
                    'p95_ms' => $p95,
                    'request_count' => (int) $item['request_count'],
                ];
            }
        }

        return $exceedances;
    }

    /** @param array<string, mixed> $group @return array<string, mixed> */
    private function presentGroup(array $group): array
    {
        $requests = max(0, (int) ($group['request_count'] ?? 0));
        $durationCount = max(0, (int) ($group['duration_count'] ?? 0));

        return [
            'route_family' => $group['route_family'],
            'priority' => $group['priority'],
            'locale' => $group['locale'],
            'request_count' => $requests,
            'success_rate' => $this->rate((int) ($group['success_count'] ?? 0), $requests),
            'not_found_rate' => $this->rate((int) ($group['not_found_count'] ?? 0), $requests),
            'rate_limited_rate' => $this->rate((int) ($group['rate_limited_count'] ?? 0), $requests),
            'client_error_rate' => $this->rate((int) ($group['client_error_count'] ?? 0), $requests),
            'server_error_rate' => $this->rate((int) ($group['server_error_count'] ?? 0), $requests),
            'timeout_rate' => $this->rate((int) ($group['timeout_count'] ?? 0), $requests),
            'duration_avg_ms' => $durationCount === 0
                ? 0.0
                : round((float) ($group['duration_sum_ms'] ?? 0) / $durationCount, 3),
            'duration_max_ms' => round((float) ($group['duration_max_ms'] ?? 0), 3),
            'p50_ms' => $this->percentile((array) ($group['duration_histogram'] ?? []), $durationCount, 0.50),
            'p95_ms' => $this->percentile((array) ($group['duration_histogram'] ?? []), $durationCount, 0.95),
            'p99_ms' => $this->percentile((array) ($group['duration_histogram'] ?? []), $durationCount, 0.99),
            'last_success_at' => $group['last_success_at'] ?? null,
            'last_failure_at' => $group['last_failure_at'] ?? null,
        ];
    }

    private function rate(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count / $total, 6);
    }

    /** @param array<string, mixed> $histogram */
    private function percentile(array $histogram, int $total, float $quantile): float
    {
        if ($total <= 0) {
            return 0.0;
        }
        $target = (int) ceil($total * $quantile);
        $seen = 0;
        foreach ((array) config('public_content_observability.duration_buckets_ms', []) as $bucket) {
            $seen += (int) ($histogram[(string) (int) $bucket] ?? 0);
            if ($seen >= $target) {
                return (float) (int) $bucket;
            }
        }

        return (float) max((array) config('public_content_observability.duration_buckets_ms', [30000]));
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    private function queryEnvelope(
        int $windowMinutes,
        array $items,
        ?string $error = null,
        ?CarbonImmutable $effectiveStart = null,
        bool $usesDaily = false,
    ): array {
        return [
            'ok' => $error === null,
            'scope' => 'anonymous_org_0_public_get',
            'window_minutes' => $windowMinutes,
            'effective_start_at' => $effectiveStart?->toIso8601String(),
            'aggregation_granularity' => $usesDaily ? 'daily_and_minute' : 'minute',
            'minute_retention_days' => $this->minuteRetentionDays(),
            'daily_retention_days' => max(1, (int) config('public_content_observability.daily_retention_days', 90)),
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'error_code' => $error,
            'items' => $items,
        ];
    }
}
