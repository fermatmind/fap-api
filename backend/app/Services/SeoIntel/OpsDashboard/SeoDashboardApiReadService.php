<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use Illuminate\Support\Facades\Schema;

final class SeoDashboardApiReadService extends AbstractSeoDashboardReadService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return (new SeoDashboardOverviewReadService($this->connectionName))->read();
    }

    /**
     * @return array<string, mixed>
     */
    public function urlTruth(): array
    {
        return (new SeoUrlTruthReadService($this->connectionName))->read();
    }

    /**
     * @return array<string, mixed>
     */
    public function issues(int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));
        $gscByUrl = $this->metricMap('seo_gsc_daily', ['clicks', 'impressions']);

        return [
            'total_count' => $this->table('seo_issue_queue')->count(),
            'aggregates' => [
                'issue_type' => $this->groupedCounts('seo_issue_queue', 'issue_type'),
                'severity' => $this->mappedGroupedCounts('seo_issue_queue', 'severity', fn (string $value): string => $this->mapSeverity($value)),
                'status' => $this->groupedCounts('seo_issue_queue', 'status'),
                'lifecycle_state' => $this->mappedGroupedCounts('seo_issue_queue', 'lifecycle_state', fn (string $value): string => $this->mapLifecycle($value)),
            ],
            'recent_rows' => $this->table('seo_issue_queue')
                ->select([
                    'issue_uid',
                    'issue_type',
                    'severity',
                    'source_system',
                    'source_engine',
                    'canonical_url',
                    'canonical_url_hash',
                    'locale',
                    'page_entity_type',
                    'status',
                    'lifecycle_state',
                    'acknowledged_at',
                    'resolved_at',
                    'ignored_at',
                    'detected_at',
                    'created_at',
                    'updated_at',
                    'summary',
                    'recommendation',
                    'metadata_json',
                ])
                ->orderByDesc('detected_at')
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get()
                ->map(fn (object $row): array => [
                    'issue_id' => (string) $row->issue_uid,
                    'issue_type' => (string) $row->issue_type,
                    'severity' => $this->mapSeverity((string) $row->severity),
                    'source_signal' => $this->sourceSignal((string) $row->source_system, isset($row->source_engine) ? (string) $row->source_engine : null),
                    'canonical_path' => $this->safePath(is_string($row->canonical_url ?? null) ? $row->canonical_url : null),
                    'locale' => isset($row->locale) ? (string) $row->locale : null,
                    'page_entity_type' => isset($row->page_entity_type) ? (string) $row->page_entity_type : null,
                    'status' => (string) $row->status,
                    'lifecycle_state' => $this->mapLifecycle((string) $row->lifecycle_state),
                    'acknowledged_at' => $this->normalizeTimestamp($row->acknowledged_at ?? null),
                    'resolved_at' => $this->normalizeTimestamp($row->resolved_at ?? null),
                    'ignored_at' => $this->normalizeTimestamp($row->ignored_at ?? null),
                    'detected_at' => $this->normalizeTimestamp($row->detected_at ?? null),
                    'first_detected_at' => $this->normalizeTimestamp($row->created_at ?? null),
                    'updated_at' => $this->normalizeTimestamp($row->updated_at ?? null),
                    'summary' => isset($row->summary) ? (string) $row->summary : null,
                    'recommendation' => isset($row->recommendation) ? (string) $row->recommendation : null,
                    'workflow' => $this->workflowMetadata($row->metadata_json ?? null),
                    'impact' => [
                        'affected_urls' => $row->canonical_url_hash === null ? 0 : 1,
                        'clicks' => (int) data_get($gscByUrl, ((string) ($row->canonical_url_hash ?? '')).'.clicks', 0),
                        'impressions' => (int) data_get($gscByUrl, ((string) ($row->canonical_url_hash ?? '')).'.impressions', 0),
                    ],
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string,string>  $filters
     * @return array<string,mixed>
     */
    public function issueClusters(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        return (new SeoIssueClusterReadService($this->connectionName))->read($filters, $page, $perPage);
    }

    /**
     * @param  array<string,string>  $filters
     * @return array<string,mixed>
     */
    public function issueClusterUrls(string $clusterUid, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        return (new SeoIssueClusterReadService($this->connectionName))->urls($clusterUid, $filters, $page, $perPage);
    }

    /**
     * @param  array<string,string>  $filters
     * @return array<string,mixed>
     */
    public function issueClusterExport(array $filters = []): array
    {
        return (new SeoIssueClusterReadService($this->connectionName))->export($filters);
    }

    /**
     * Read only real GSC rows. An empty result means the connector has not
     * produced data for the selected window; callers must not synthesize it.
     *
     * @param  array{days?:int,device?:string,country?:string,locale?:string,search_type?:string}  $filters
     * @return array<string, mixed>
     */
    public function searchPerformance(array $filters = []): array
    {
        $days = max(1, min((int) ($filters['days'] ?? 28), 90));
        $query = $this->table('seo_gsc_daily')
            ->where('report_date', '>=', now()->subDays($days - 1)->toDateString());

        foreach (['device', 'country', 'locale'] as $filter) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value !== '' && $value !== 'all') {
                $query->where($filter, $value);
            }
        }
        $searchType = trim((string) ($filters['search_type'] ?? 'all'));
        if ($searchType !== '' && $searchType !== 'all') {
            $query->where('search_type', $searchType);
        }

        $totals = (clone $query)
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN average_position_milli * impressions ELSE 0 END), 0) AS position_weight')
            ->selectRaw('COALESCE(SUM(CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN impressions ELSE 0 END), 0) AS position_impressions')
            ->first();
        $clicks = (int) ($totals->clicks ?? 0);
        $impressions = (int) ($totals->impressions ?? 0);
        $positionWeight = (int) ($totals->position_weight ?? 0);
        $positionImpressions = (int) ($totals->position_impressions ?? 0);

        $daily = (clone $query)
            ->select('report_date')
            ->selectRaw('SUM(clicks) AS clicks')
            ->selectRaw('SUM(impressions) AS impressions')
            ->groupBy('report_date')
            ->orderBy('report_date')
            ->get()
            ->map(static fn (object $row): array => [
                'report_date' => (string) $row->report_date,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
            ])
            ->all();

        $latestReportDate = (clone $query)->max('report_date');
        $updatedAt = (clone $query)->max('collected_at');
        $rows = (clone $query)
            ->select([
                'report_date',
                'canonical_url',
                'query_display_masked',
                'locale',
                'device',
                'country',
                'clicks',
                'impressions',
                'ctr_ppm',
                'average_position_milli',
                'collected_at',
            ])
            ->whereNotNull('query_display_masked')
            ->where('query_display_masked', '!=', '')
            ->orderByDesc('impressions')
            ->orderByDesc('report_date')
            ->limit(25)
            ->get();

        $syncState = $this->gscSyncState();
        $stale = is_string($latestReportDate)
            && $latestReportDate < now()->subDays(max(3, (int) config('seo_intel.gsc_data_quality.max_report_age_days', 10)))->toDateString();
        $dataAvailable = is_string($latestReportDate) && $latestReportDate !== '';
        $state = $dataAvailable
            ? ($stale ? 'stale' : 'connected')
            : (string) ($syncState['state'] ?? 'disconnected');

        return [
            'connected' => $state === 'connected',
            'source_connected' => $syncState['last_success_at'] !== null,
            'data_available' => $dataAvailable,
            'state' => $state,
            'failure_code' => $syncState['failure_code'] ?? null,
            'last_success_at' => $syncState['last_success_at'] ?? null,
            'last_attempt_at' => $syncState['last_attempt_at'] ?? null,
            'required_environment' => $state === 'disconnected' ? [
                'SEO_INTEL_ENABLED',
                'SEO_INTEL_WRITE_ENABLED',
                'SEO_INTEL_ALLOW_EXTERNAL_API_CALLS',
                'SEO_INTEL_GSC_ENABLED',
                'SEO_INTEL_GSC_LIVE_API_ENABLED',
                'SEO_INTEL_GSC_SYNC_ENABLED',
                'SEO_INTEL_GSC_PROPERTY_URL',
                'SEO_INTEL_GSC_AUTH_MODE',
                'SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON or SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH',
            ] : [],
            'source' => 'seo_intel.seo_gsc_daily',
            'window_days' => $days,
            'updated_at' => $this->normalizeTimestamp($updatedAt),
            'totals' => [
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr_percent' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'average_position' => $positionImpressions > 0
                    ? round(($positionWeight / $positionImpressions) / 1000, 2)
                    : null,
            ],
            'daily' => $daily,
            'query_page_rows' => $rows
                ->map(fn (object $row): array => [
                    'query' => (string) $row->query_display_masked,
                    'canonical_path' => $this->safePath(is_string($row->canonical_url ?? null) ? $row->canonical_url : null),
                    'locale' => is_string($row->locale ?? null) ? $row->locale : null,
                    'device' => is_string($row->device ?? null) ? $row->device : null,
                    'country' => is_string($row->country ?? null) ? $row->country : null,
                    'clicks' => (int) $row->clicks,
                    'impressions' => (int) $row->impressions,
                    'ctr_percent' => $row->ctr_ppm === null ? null : round(((int) $row->ctr_ppm) / 10000, 2),
                    'average_position' => $row->average_position_milli === null ? null : round(((int) $row->average_position_milli) / 1000, 2),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array{state:string,failure_code:?string,last_success_at:?string,last_attempt_at:?string} */
    private function gscSyncState(): array
    {
        $connectionName = $this->connectionName ?? (string) config('seo_intel.connection', 'seo_intel');
        if (! Schema::connection($connectionName)->hasTable('seo_gsc_sync_runs')) {
            return ['state' => 'disconnected', 'failure_code' => null, 'last_success_at' => null, 'last_attempt_at' => null];
        }

        $latest = $this->table('seo_gsc_sync_runs')->orderByDesc('started_at')->first();
        $lastSuccess = $this->table('seo_gsc_sync_runs')
            ->where('status', 'success')
            ->orderByDesc('finished_at')
            ->first();

        return [
            'state' => match ((string) ($latest->status ?? '')) {
                'quality_failed' => 'quality_failed',
                'failed' => 'sync_failed',
                'running' => 'syncing',
                'success' => 'empty',
                default => 'disconnected',
            },
            'failure_code' => isset($latest->failure_code) ? (string) $latest->failure_code : null,
            'last_success_at' => $this->normalizeTimestamp($lastSuccess->finished_at ?? null),
            'last_attempt_at' => $this->normalizeTimestamp($latest->finished_at ?? $latest->started_at ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function trends(int $limit = 30): array
    {
        $limit = max(1, min($limit, 90));

        return [
            'totals' => [
                'gsc_clicks' => $this->sumTableColumn('seo_gsc_daily', 'clicks'),
                'gsc_impressions' => $this->sumTableColumn('seo_gsc_daily', 'impressions'),
                'baidu_landing_events' => $this->sumTableColumn('seo_baidu_landing_daily', 'landing_event_count'),
                'start_attempts' => $this->sumTableColumn('seo_event_funnel_daily', 'start_attempt_count'),
                'view_results' => $this->sumTableColumn('seo_event_funnel_daily', 'view_result_count'),
                'purchase_successes' => $this->sumTableColumn('seo_event_funnel_daily', 'purchase_success_count'),
            ],
            'consent_distribution' => $this->table('seo_consent_daily')
                ->select('consent_state')
                ->selectRaw('SUM(event_count) AS aggregate_count')
                ->groupBy('consent_state')
                ->orderBy('consent_state')
                ->get()
                ->map(fn (object $row): array => [
                    'label' => $this->mapConsentState((string) $row->consent_state),
                    'count' => (int) ($row->aggregate_count ?? 0),
                ])
                ->all(),
            'recent_dates' => $this->table('seo_gsc_daily')
                ->select('report_date')
                ->selectRaw('SUM(clicks) AS clicks')
                ->selectRaw('SUM(impressions) AS impressions')
                ->groupBy('report_date')
                ->orderByDesc('report_date')
                ->limit($limit)
                ->get()
                ->map(static fn (object $row): array => [
                    'report_date' => (string) $row->report_date,
                    'clicks' => (int) ($row->clicks ?? 0),
                    'impressions' => (int) ($row->impressions ?? 0),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pagePerformance(int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));
        $gscByUrl = $this->metricMap('seo_gsc_daily', ['clicks', 'impressions']);
        $baiduByUrl = $this->metricMap('seo_baidu_landing_daily', ['landing_event_count', 'start_attempt_count', 'purchase_success_count']);
        $revenueByUrl = $this->metricMap('seo_revenue_daily', ['orders_count', 'purchase_count', 'revenue_cents']);
        $attributionByUrl = $this->metricMap('seo_landing_attribution_daily', ['first_touch_count', 'last_touch_count', 'cta_touch_count']);

        return [
            'total_count' => $this->table('seo_urls')->count(),
            'recent_rows' => $this->table('seo_urls')
                ->select([
                    'canonical_url_hash',
                    'canonical_url',
                    'locale',
                    'page_entity_type',
                    'cluster',
                    'source_authority',
                    'indexability_state',
                    'updated_at',
                ])
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get()
                ->map(fn (object $row): array => [
                    'canonical_url_hash' => (string) $row->canonical_url_hash,
                    'canonical_path' => $this->safePath((string) $row->canonical_url),
                    'locale' => (string) $row->locale,
                    'page_entity_type' => (string) $row->page_entity_type,
                    'cluster' => isset($row->cluster) ? (string) $row->cluster : null,
                    'source_authority' => (string) $row->source_authority,
                    'indexability_state' => (string) $row->indexability_state,
                    'metrics' => [
                        'gsc_clicks' => (int) data_get($gscByUrl, $row->canonical_url_hash.'.clicks', 0),
                        'gsc_impressions' => (int) data_get($gscByUrl, $row->canonical_url_hash.'.impressions', 0),
                        'baidu_landing_events' => (int) data_get($baiduByUrl, $row->canonical_url_hash.'.landing_event_count', 0),
                        'start_attempts' => (int) data_get($baiduByUrl, $row->canonical_url_hash.'.start_attempt_count', 0),
                        'purchase_successes' => (int) data_get($baiduByUrl, $row->canonical_url_hash.'.purchase_success_count', 0),
                        'orders' => (int) data_get($revenueByUrl, $row->canonical_url_hash.'.orders_count', 0),
                        'purchases' => (int) data_get($revenueByUrl, $row->canonical_url_hash.'.purchase_count', 0),
                        'revenue_cents' => (int) data_get($revenueByUrl, $row->canonical_url_hash.'.revenue_cents', 0),
                        'first_touches' => (int) data_get($attributionByUrl, $row->canonical_url_hash.'.first_touch_count', 0),
                        'last_touches' => (int) data_get($attributionByUrl, $row->canonical_url_hash.'.last_touch_count', 0),
                        'cta_touches' => (int) data_get($attributionByUrl, $row->canonical_url_hash.'.cta_touch_count', 0),
                    ],
                    'updated_at' => $this->normalizeTimestamp($row->updated_at ?? null),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function opportunityQueue(int $limit = 25): array
    {
        return (new SeoOpportunityQueueReadService)->read($limit);
    }

    /** @return array<string, mixed> */
    public function productionCloseout(): array
    {
        return (new GscProductionCloseoutReadService($this->connectionName))->read();
    }

    /** @return array<string, mixed> */
    public function technicalAudits(int $limit = 25): array
    {
        return (new SeoTechnicalAuditReadService)->read($limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function conversionFunnel(int $orgId, array $filters = [], int $limit = 25): array
    {
        return (new SeoConversionFunnelReadService)->read($orgId, $filters, $limit);
    }

    /**
     * @param  callable(string): string  $mapper
     * @return list<array{label:string,count:int}>
     */
    private function mappedGroupedCounts(string $table, string $column, callable $mapper): array
    {
        $counts = [];

        foreach ($this->groupedCounts($table, $column) as $row) {
            $label = $mapper($row['label']);
            $counts[$label] = ($counts[$label] ?? 0) + $row['count'];
        }

        ksort($counts);

        return array_map(
            static fn (string $label, int $count): array => ['label' => $label, 'count' => $count],
            array_keys($counts),
            array_values($counts)
        );
    }

    private function sumTableColumn(string $table, string $column): int
    {
        return (int) ($this->table($table)->sum($column) ?? 0);
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, array<string, int>>
     */
    private function metricMap(string $table, array $columns): array
    {
        $query = $this->table($table)->select('canonical_url_hash');
        foreach ($columns as $column) {
            $query->selectRaw(sprintf('SUM(%s) AS %s', $column, $column));
        }

        $metrics = [];
        foreach ($query->whereNotNull('canonical_url_hash')->groupBy('canonical_url_hash')->get() as $row) {
            $hash = (string) $row->canonical_url_hash;
            $metrics[$hash] = [];
            foreach ($columns as $column) {
                $metrics[$hash][$column] = (int) ($row->{$column} ?? 0);
            }
        }

        return $metrics;
    }

    private function mapSeverity(string $severity): string
    {
        return match ($severity) {
            'warning' => 'medium',
            default => $severity,
        };
    }

    private function mapLifecycle(string $state): string
    {
        return match ($state) {
            'acknowledged' => 'triaged',
            'resolved' => 'resolved_observed',
            'ignored' => 'suppressed',
            default => $state,
        };
    }

    private function mapConsentState(string $state): string
    {
        return match ($state) {
            'granted' => 'analytics_granted',
            'denied' => 'analytics_denied',
            'not_applicable' => 'not_applicable_backend_business_event',
            default => 'unknown',
        };
    }

    /** @return array<string, mixed> */
    private function workflowMetadata(mixed $metadata): array
    {
        $decoded = $this->decodeJson($metadata);
        $workflow = is_array($decoded['ops_workflow'] ?? null) ? $decoded['ops_workflow'] : [];

        return [
            'owner' => is_string($workflow['owner'] ?? null) ? $workflow['owner'] : null,
            'sla_due_at' => is_string($workflow['sla_due_at'] ?? null) ? $workflow['sla_due_at'] : null,
            'fixed_at' => is_string($workflow['fixed_at'] ?? null) ? $workflow['fixed_at'] : null,
            'verified_at' => is_string($workflow['verified_at'] ?? null) ? $workflow['verified_at'] : null,
            'verification_result' => is_string($workflow['verification_result'] ?? null) ? $workflow['verification_result'] : null,
            'ignore_reason' => is_string($workflow['ignore_reason'] ?? null) ? $workflow['ignore_reason'] : null,
            'ignored_until' => is_string($workflow['ignored_until'] ?? null) ? $workflow['ignored_until'] : null,
        ];
    }

    private function sourceSignal(string $sourceSystem, ?string $sourceEngine): string
    {
        if ($sourceEngine === null || trim($sourceEngine) === '') {
            return $sourceSystem;
        }

        return $sourceSystem.':'.$sourceEngine;
    }
}
