<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class SeoConversionFunnelReadService
{
    private const TABLE = 'analytics_seo_conversion_daily';

    private const RUN_TABLE = 'analytics_seo_conversion_refresh_runs';

    /**
     * @var list<string>
     */
    private const METRICS = [
        'landing_pv_count',
        'article_to_test_click_count',
        'start_test_count',
        'complete_test_count',
        'view_result_count',
        'return_public_content_count',
    ];

    /**
     * @var list<string>
     */
    private const PRIVATE_PATH_SEGMENTS = [
        'result',
        'results',
        'order',
        'orders',
        'share',
        'shares',
        'pay',
        'payment',
        'payments',
        'history',
    ];

    /**
     * @return array<string,mixed>
     */
    public function read(int $orgId, array $filters = [], int $limit = 25): array
    {
        $groupBy = $this->normalizeGroupBy($filters['group_by'] ?? null);
        $windowDays = $this->normalizeWindow($filters['window_days'] ?? null);
        $limit = max(1, min($limit, 100));

        if (! SchemaBaseline::hasTable(self::TABLE)) {
            return $this->emptyPayload($groupBy, ['analytics_seo_conversion_daily_missing']);
        }

        $allRows = $this->mappedRows($orgId, $filters, $groupBy, $windowDays);
        $refreshEvidence = $this->refreshEvidence($orgId);
        $lastRefreshedAt = $refreshEvidence['last_successful_refresh_at'];
        $freshnessAgeHours = $lastRefreshedAt === null
            ? null
            : now()->diffInHours($lastRefreshedAt, true);
        $measurementHold = $freshnessAgeHours === null
            || $freshnessAgeHours > 48
            || ($refreshEvidence['latest_status'] ?? null) === 'blocked';
        $warnings = $measurementHold ? [$refreshEvidence['warning']] : [];
        $totals = $measurementHold ? $this->nullMetrics() : $this->totals($allRows);
        $rows = array_slice($allRows, 0, $limit);
        if ($measurementHold) {
            $rows = array_map(function (array $row): array {
                $row['metrics'] = $this->nullMetrics();

                return $row;
            }, $rows);
        }

        $windowTotals = [];
        foreach ([7, 28, 90] as $days) {
            $windowTotals[(string) $days] = $measurementHold
                ? $this->nullMetrics()
                : $this->totals($this->mappedRows($orgId, $filters, $groupBy, $days));
        }

        return [
            'source_table' => self::TABLE,
            'group_by' => $groupBy,
            'window_days' => $windowDays,
            'available_windows' => [7, 28, 90],
            'filters' => $this->safeFilters($filters),
            'privacy' => $this->privacyStatus(),
            'product_event_mapping' => $this->productEventMapping(),
            'measurement_state' => $measurementHold ? 'MEASUREMENT_HOLD' : 'production_healthy',
            'freshness' => [
                'last_successful_refresh_at' => $lastRefreshedAt,
                'latest_attempt_at' => $refreshEvidence['latest_attempt_at'],
                'latest_attempt_status' => $refreshEvidence['latest_status'],
                'latest_trigger_mode' => $refreshEvidence['trigger_mode'],
                'age_hours' => $freshnessAgeHours,
                'max_age_hours' => 48,
            ],
            'stage_status' => $this->stageStatus($measurementHold, $lastRefreshedAt),
            'totals' => $totals,
            'window_totals' => $windowTotals,
            'recent_rows' => $rows,
            'warnings' => $warnings,
        ];
    }

    public function currentPublicZeroRefreshProven(): bool
    {
        if (! SchemaBaseline::hasTable(self::RUN_TABLE)) {
            return false;
        }

        $runs = DB::table(self::RUN_TABLE)
            ->where('status', 'success')
            ->where('org_scope_count', 1)
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get(['status', 'trigger_mode', 'completed_at', 'org_scope_count', 'receipt_json']);
        foreach ($runs as $run) {
            $receipt = $this->boundedPublicReceipt($run);
            if ($receipt === null
                || ($receipt['attempted_rows'] ?? null) !== 0
                || ($receipt['upserted_rows'] ?? null) !== 0
                || data_get($receipt, 'readback_receipt.status') !== 'pass') {
                continue;
            }
            $expected = data_get($receipt, 'readback_receipt.expected_metrics');
            $persisted = data_get($receipt, 'readback_receipt.persisted_metrics');
            if (is_array($expected) && is_array($persisted)
                && $this->allMetricValuesAreZero($expected)
                && $this->allMetricValuesAreZero($persisted)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{last_successful_refresh_at:mixed,latest_attempt_at:mixed,latest_status:?string,trigger_mode:?string,warning:string} */
    private function refreshEvidence(int $orgId): array
    {
        if (SchemaBaseline::hasTable(self::RUN_TABLE)) {
            $runs = DB::table(self::RUN_TABLE)
                ->orderByDesc('completed_at')
                ->limit(100)
                ->get(['status', 'trigger_mode', 'completed_at', 'org_scope_count', 'receipt_json']);
            $relevant = $runs->filter(fn (object $run): bool => $this->refreshRunCoversOrg($run, $orgId));
            $latest = $relevant->first();
            $lastSuccess = $relevant
                ->first(static fn (object $run): bool => (string) $run->status === 'success')
                ?->completed_at;

            if ($latest !== null || $lastSuccess !== null) {
                return [
                    'last_successful_refresh_at' => $lastSuccess,
                    'latest_attempt_at' => $latest?->completed_at,
                    'latest_status' => $latest === null ? null : (string) $latest->status,
                    'trigger_mode' => $latest === null ? null : (string) $latest->trigger_mode,
                    'warning' => $latest !== null && (string) $latest->status === 'blocked'
                        ? 'seo_conversion_refresh_readback_blocked'
                        : 'seo_conversion_refresh_missing_or_stale',
                ];
            }

            return [
                'last_successful_refresh_at' => null,
                'latest_attempt_at' => null,
                'latest_status' => null,
                'trigger_mode' => null,
                'warning' => 'seo_conversion_refresh_missing_or_stale',
            ];
        }

        $legacy = DB::table(self::TABLE)
            ->where('org_id', max(0, $orgId))
            ->max('last_refreshed_at');

        return [
            'last_successful_refresh_at' => $legacy,
            'latest_attempt_at' => $legacy,
            'latest_status' => $legacy === null ? null : 'legacy_success',
            'trigger_mode' => null,
            'warning' => 'seo_conversion_daily_missing_or_stale',
        ];
    }

    private function refreshRunCoversOrg(object $run, int $orgId): bool
    {
        if ((int) ($run->org_scope_count ?? -1) === 0) {
            return true;
        }
        if ($orgId !== 0 || (int) ($run->org_scope_count ?? -1) !== 1) {
            return false;
        }

        return $this->boundedPublicReceipt($run) !== null;
    }

    /** @return array<string,mixed>|null */
    private function boundedPublicReceipt(object $run): ?array
    {
        try {
            $receipt = json_decode((string) ($run->receipt_json ?? ''), true, 32, JSON_THROW_ON_ERROR);
            $from = \Carbon\CarbonImmutable::parse((string) ($receipt['from'] ?? ''), 'UTC')->startOfDay();
            $to = \Carbon\CarbonImmutable::parse((string) ($receipt['to'] ?? ''), 'UTC')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
        $schemaVersion = $receipt['schema_version'] ?? null;
        $expected = data_get($receipt, 'readback_receipt.expected_metrics');
        $persisted = data_get($receipt, 'readback_receipt.persisted_metrics');
        $current = $this->metricsForRange($from, $to, 0, is_array($persisted) ? array_keys($persisted) : []);
        $snapshot = (array) ($receipt['readmodel_snapshot'] ?? []);
        $newHashesValid = true;
        if ($schemaVersion === 'analytics-seo-conversion-refresh-receipt.v2') {
            $withoutHash = $receipt;
            unset($withoutHash['receipt_hash']);
            try {
                $snapshotFrom = \Carbon\CarbonImmutable::parse((string) ($snapshot['from'] ?? ''), 'UTC')->startOfDay();
                $snapshotTo = \Carbon\CarbonImmutable::parse((string) ($snapshot['to'] ?? ''), 'UTC')->startOfDay();
            } catch (\Throwable) {
                return null;
            }
            $snapshotMetrics = $snapshot['persisted_metrics'] ?? null;
            $currentSnapshotMetrics = $this->metricsForRange(
                $snapshotFrom,
                $snapshotTo,
                0,
                is_array($snapshotMetrics) ? array_keys($snapshotMetrics) : [],
            );
            $newHashesValid = ($receipt['environment'] ?? null) === app()->environment()
                && ($snapshot['environment'] ?? null) === app()->environment()
                && ($snapshot['org_scope_mode'] ?? null) === 'bounded'
                && ($snapshot['org_scope_count'] ?? null) === 1
                && ($snapshot['public_org_zero_only'] ?? null) === true
                && (int) $snapshotFrom->diffInDays($snapshotTo) === 89
                && $snapshotTo->isSameDay(now('UTC'))
                && is_array($snapshotMetrics)
                && $snapshotMetrics === $currentSnapshotMetrics
                && is_string($receipt['readmodel_snapshot_hash'] ?? null)
                && hash_equals($this->canonicalHash($snapshot), (string) $receipt['readmodel_snapshot_hash'])
                && is_string($receipt['receipt_hash'] ?? null)
                && hash_equals($this->canonicalHash($withoutHash), (string) $receipt['receipt_hash']);
        }
        if (! is_array($receipt)
            || ! in_array($schemaVersion, ['analytics-seo-conversion-refresh-receipt.v1', 'analytics-seo-conversion-refresh-receipt.v2'], true)
            || ($receipt['status'] ?? null) !== 'success'
            || ($receipt['org_scope_mode'] ?? null) !== 'bounded'
            || ($receipt['org_scope_count'] ?? null) !== 1
            || ($receipt['public_org_zero_only'] ?? null) !== true
            || ($schemaVersion === 'analytics-seo-conversion-refresh-receipt.v1' && (int) $from->diffInDays($to) !== 89)
            || ($schemaVersion === 'analytics-seo-conversion-refresh-receipt.v1' && ! $to->isSameDay(now('UTC')))
            || ! is_array($expected)
            || ! is_array($persisted)
            || $expected !== $persisted
            || $persisted !== $current
            || ! $newHashesValid
            || ($receipt['raw_query_exposed'] ?? null) !== false
            || ($receipt['raw_session_or_business_identifiers_exposed'] ?? null) !== false
            || ($receipt['private_paths_allowed'] ?? null) !== false
            || ($receipt['search_submission_allowed'] ?? null) !== false) {
            return null;
        }

        return $receipt;
    }

    /** @param list<string> $metrics @return array<string, int> */
    private function metricsForRange(\Carbon\CarbonImmutable $from, \Carbon\CarbonImmutable $to, int $orgId, array $metrics): array
    {
        $allowed = [...self::METRICS, 'result_ready_count'];
        if ($metrics === [] || array_diff($metrics, $allowed) !== []) {
            return [];
        }
        $query = DB::table(self::TABLE)
            ->where('org_id', $orgId)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()]);
        foreach ($metrics as $metric) {
            $query->selectRaw(sprintf('COALESCE(SUM(%s), 0) AS %s', $metric, $metric));
        }
        $row = $query->first();

        return array_combine($metrics, array_map(
            static fn (string $metric): int => max(0, (int) ($row->{$metric} ?? 0)),
            $metrics,
        ));
    }

    /** @param array<string, mixed> $value */
    private function canonicalHash(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }

            return $item;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $metrics */
    private function allMetricValuesAreZero(array $metrics): bool
    {
        foreach ([
            ...self::METRICS,
            'result_ready_count',
        ] as $metric) {
            if (($metrics[$metric] ?? null) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string,mixed>> */
    private function mappedRows(int $orgId, array $filters, string $groupBy, int $windowDays): array
    {
        $groupColumns = $this->groupColumns($groupBy);
        $query = DB::table(self::TABLE)
            ->select($groupColumns)
            ->where('org_id', max(0, $orgId))
            ->where('day', '>=', now()->subDays($windowDays - 1)->toDateString());
        foreach (self::METRICS as $metric) {
            $query->selectRaw(sprintf('SUM(%s) AS %s', $metric, $metric));
        }
        $this->applyFilters($query, $filters);
        $query->groupBy($groupColumns)
            ->orderByDesc('landing_pv_count')
            ->orderByDesc('start_test_count');

        $rows = [];
        foreach ($query->get() as $row) {
            $mapped = $this->mapRow($row, $groupBy);
            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyPayload(string $groupBy, array $warnings): array
    {
        return [
            'source_table' => self::TABLE,
            'group_by' => $groupBy,
            'filters' => [],
            'privacy' => $this->privacyStatus(),
            'product_event_mapping' => $this->productEventMapping(),
            'totals' => [
                ...$this->nullMetrics(),
            ],
            'measurement_state' => 'MEASUREMENT_HOLD',
            'stage_status' => $this->stageStatus(true, null),
            'recent_rows' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string,string|bool>
     */
    private function privacyStatus(): array
    {
        return [
            'raw_session_id_exposed' => false,
            'session_dimension' => 'not_stored_or_exposed_in_seo_operations',
            'query_policy' => 'query_and_fragment_stripped_before_daily_storage',
            'private_path_policy' => 'result_order_share_pay_history_excluded',
            'raw_business_identifier_policy' => 'business_identifiers_rejected_before_daily_storage',
        ];
    }

    /** @return array<string, string> */
    private function productEventMapping(): array
    {
        return [
            'start_test' => self::TABLE.'.start_test_count',
            'complete_test' => self::TABLE.'.complete_test_count',
            'view_result' => self::TABLE.'.view_result_count',
        ];
    }

    /**
     * @return list<string>
     */
    private function groupColumns(string $groupBy): array
    {
        return match ($groupBy) {
            'article' => ['source_article', 'lang', 'page_type', 'scale_id', 'form_id', 'url', 'source_url', 'target_test'],
            'test' => ['target_test', 'lang', 'scale_id', 'form_id', 'url', 'source_url'],
            default => ['url', 'lang', 'page_type', 'source_article', 'target_test', 'scale_id', 'form_id', 'source_url'],
        };
    }

    private function applyFilters(\Illuminate\Database\Query\Builder $query, array $filters): void
    {
        foreach ([
            'lang',
            'page_type',
            'source_article',
            'scale_id',
            'form_id',
        ] as $field) {
            $value = $this->normalizeText($filters[$field] ?? null, 160);
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        foreach (['url', 'source_url', 'target_test'] as $field) {
            $value = $this->normalizePublicPathFilter($filters[$field] ?? null);
            if ($value !== '') {
                $query->where(function (\Illuminate\Database\Query\Builder $nested) use ($field, $value): void {
                    $nested->where($field, $value)
                        ->orWhere($field, 'like', '%'.$value);
                });
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private function safeFilters(array $filters): array
    {
        $safe = [];
        if (array_key_exists('window_days', $filters)) {
            $safe['window_days'] = (string) $this->normalizeWindow($filters['window_days']);
        }
        foreach ([
            'group_by',
            'lang',
            'page_type',
            'source_article',
            'scale_id',
            'form_id',
        ] as $field) {
            $value = $this->normalizeText($filters[$field] ?? null, 160);
            if ($value !== '') {
                $safe[$field] = $value;
            }
        }

        foreach (['url', 'source_url', 'target_test'] as $field) {
            $value = $this->normalizePublicPathFilter($filters[$field] ?? null);
            if ($value !== '') {
                $safe[$field] = $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function mapRow(object $row, string $groupBy): ?array
    {
        $urlPath = $this->safePath($row->url ?? null);
        $sourcePath = $this->safePath($row->source_url ?? null);
        $targetPath = $this->safePath($row->target_test ?? null);

        if ($this->containsPrivatePath([$urlPath, $sourcePath, $targetPath])) {
            return null;
        }

        $metrics = [];
        foreach (self::METRICS as $metric) {
            $metrics[$metric] = (int) ($row->{$metric} ?? 0);
        }

        return [
            'group_key' => $this->groupKey($row, $groupBy, $urlPath, $targetPath),
            'url_path' => $urlPath,
            'lang' => (string) ($row->lang ?? ''),
            'page_type' => (string) ($row->page_type ?? ''),
            'source_url_path' => $sourcePath,
            'source_article' => (string) ($row->source_article ?? ''),
            'target_test_path' => $targetPath,
            'scale_id' => (string) ($row->scale_id ?? ''),
            'form_id' => (string) ($row->form_id ?? ''),
            'referrer_host' => (string) ($row->referrer_host ?? ''),
            'metrics' => $metrics,
            'privacy' => [
                'raw_session_id_exposed' => false,
                'private_path_excluded' => true,
                'query_stripped' => true,
            ],
        ];
    }

    private function groupKey(object $row, string $groupBy, ?string $urlPath, ?string $targetPath): string
    {
        return match ($groupBy) {
            'article' => (string) ($row->source_article ?? ''),
            'test' => $targetPath ?? '',
            default => $urlPath ?? '',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function totals(array $rows): array
    {
        $totals = array_fill_keys(self::METRICS, 0);

        foreach ($rows as $row) {
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            foreach (self::METRICS as $metric) {
                $totals[$metric] += (int) ($metrics[$metric] ?? 0);
            }
        }

        return $totals;
    }

    private function normalizeGroupBy(mixed $value): string
    {
        $candidate = $this->normalizeText($value, 32);

        return in_array($candidate, ['url', 'article', 'test'], true) ? $candidate : 'url';
    }

    private function normalizeWindow(mixed $value): int
    {
        $window = (int) $value;

        return in_array($window, [7, 28, 90], true) ? $window : 28;
    }

    /** @return array<string,null> */
    private function nullMetrics(): array
    {
        return array_fill_keys(self::METRICS, null);
    }

    /** @return array<string,array<string,mixed>> */
    private function stageStatus(bool $hold, mixed $lastRefreshedAt): array
    {
        $status = $hold ? 'MEASUREMENT_HOLD' : 'pass';
        $stages = [
            'search_landing' => 'landing_pv_count',
            'test_start' => 'start_test_count',
            'test_complete' => 'complete_test_count',
            'result_view' => 'view_result_count',
            'return_public_content' => 'return_public_content_count',
        ];

        return collect($stages)->mapWithKeys(static fn (string $metric, string $stage): array => [
            $stage => [
                'status' => $status,
                'metric' => $metric,
                'last_refreshed_at' => $lastRefreshedAt,
            ],
        ])->all();
    }

    private function normalizeText(mixed $value, int $maxLength): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[\x00-\x1F\x7F]+/', '', $normalized) ?? '';

        return mb_substr($normalized, 0, $maxLength);
    }

    private function normalizePublicPathFilter(mixed $value): string
    {
        $path = $this->safePath($value);
        if ($path === null || $this->isPrivatePath($path)) {
            return '';
        }

        return $path;
    }

    private function safePath(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $candidate = trim((string) $value);
        if ($candidate === '') {
            return null;
        }

        $path = parse_url($candidate, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return '/';
        }

        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function isPrivatePath(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', strtolower($path)), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return false;
        }

        $firstContentSegment = in_array($segments[0], ['en', 'zh', 'zh-cn', 'zh-tw'], true)
            ? ($segments[1] ?? '')
            : $segments[0];

        return in_array($firstContentSegment, self::PRIVATE_PATH_SEGMENTS, true);
    }

    /**
     * @param  list<?string>  $paths
     */
    private function containsPrivatePath(array $paths): bool
    {
        foreach ($paths as $path) {
            if ($path !== null && $this->isPrivatePath($path)) {
                return true;
            }
        }

        return false;
    }
}
