<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Services\SeoIntel\Detector\SeoDetectorRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyClassifier;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SeoDashboardApiReadService extends AbstractSeoDashboardReadService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return (new SeoDashboardOverviewReadService($this->connectionName))->read();
    }

    /** @return array<string,mixed> */
    public function technicalHealth(): array
    {
        return (new SeoTechnicalHealthReadService($this->connectionName))->read();
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
                    'detector' => $this->detectorMetadata(
                        $row->metadata_json ?? null,
                        (string) $row->status,
                        (string) $row->lifecycle_state,
                    ),
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
        $requestedDays = (int) ($filters['days'] ?? 28);
        $days = in_array($requestedDays, [7, 28, 90], true) ? $requestedDays : 28;
        $query = $this->table('seo_gsc_daily')
            ->where('report_date', '>=', now()->subDays($days - 1)->toDateString());
        if ($this->connection()->getSchemaBuilder()->hasColumn('seo_gsc_daily', 'mapping_state')) {
            $query->where('mapping_state', 'mapped');
        }

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
            && $latestReportDate < now((string) config('seo_intel.gsc_reporting_timezone', 'America/Los_Angeles'))
                ->subDays((int) config('seo_intel.gsc_backfill_lag_days', 3))
                ->toDateString();
        $dataAvailable = is_string($latestReportDate) && $latestReportDate !== '';
        $state = $dataAvailable
            ? ($stale ? 'stale' : 'connected')
            : (string) ($syncState['state'] ?? 'disconnected');
        $measurementHold = $state !== 'connected';

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
            'available_windows' => [7, 28, 90],
            'updated_at' => $this->normalizeTimestamp($updatedAt),
            'measurement_state' => $measurementHold ? 'MEASUREMENT_HOLD' : 'production_healthy',
            'measurement_hold_reason' => $measurementHold ? 'gsc_data_missing_or_beyond_reporting_lag' : null,
            'totals' => $measurementHold ? $this->nullGscMetrics() : $this->gscMetrics(
                $clicks,
                $impressions,
                $positionWeight,
                $positionImpressions,
            ),
            'breakdowns' => $measurementHold ? [
                'brand' => null,
                'page_family' => null,
                'locale' => null,
                'device' => null,
                'country' => null,
                'search_type' => null,
            ] : [
                'brand' => $this->gscDimensionBreakdown($query, 'query_type'),
                'page_family' => $this->gscPageFamilyBreakdown($query),
                'locale' => $this->gscDimensionBreakdown($query, 'locale'),
                'device' => $this->gscDimensionBreakdown($query, 'device'),
                'country' => $this->gscDimensionBreakdown($query, 'country'),
                'search_type' => $this->gscDimensionBreakdown($query, 'search_type'),
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

    /** @return array{clicks:?int,impressions:?int,ctr_percent:?float,average_position:?float} */
    private function nullGscMetrics(): array
    {
        return ['clicks' => null, 'impressions' => null, 'ctr_percent' => null, 'average_position' => null];
    }

    /** @return array{clicks:int,impressions:int,ctr_percent:?float,average_position:?float} */
    private function gscMetrics(int $clicks, int $impressions, int $positionWeight, int $positionImpressions): array
    {
        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr_percent' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'average_position' => $positionImpressions > 0
                ? round(($positionWeight / $positionImpressions) / 1000, 2)
                : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function gscDimensionBreakdown(\Illuminate\Database\Query\Builder $query, string $dimension): array
    {
        return (clone $query)
            ->selectRaw("COALESCE(NULLIF($dimension, ''), 'unknown') AS dimension")
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN average_position_milli * impressions ELSE 0 END), 0) AS position_weight')
            ->selectRaw('COALESCE(SUM(CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN impressions ELSE 0 END), 0) AS position_impressions')
            ->groupBy($dimension)
            ->orderByDesc('impressions')
            ->get()
            ->map(fn (object $row): array => [
                'dimension' => (string) $row->dimension,
                ...$this->gscMetrics(
                    (int) $row->clicks,
                    (int) $row->impressions,
                    (int) $row->position_weight,
                    (int) $row->position_impressions,
                ),
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function gscPageFamilyBreakdown(\Illuminate\Database\Query\Builder $query): array
    {
        $familySql = "CASE
            WHEN LOWER(canonical_url) LIKE '%/tests/%' OR LOWER(canonical_url) LIKE '%/tests' THEN 'tests'
            WHEN LOWER(canonical_url) LIKE '%/articles/%' OR LOWER(canonical_url) LIKE '%/topics/%' THEN 'articles_topics'
            WHEN LOWER(canonical_url) LIKE '%/career/%' OR LOWER(canonical_url) LIKE '%/career' THEN 'career'
            WHEN LOWER(canonical_url) LIKE '%/personality/%' OR LOWER(canonical_url) LIKE '%/personality' THEN 'personality'
            WHEN LOWER(canonical_url) LIKE '%/support/%' OR LOWER(canonical_url) LIKE '%/method%' THEN 'trust_method_help'
            ELSE 'other_public' END";

        return (clone $query)
            ->selectRaw("$familySql AS dimension")
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN average_position_milli * impressions ELSE 0 END), 0) AS position_weight')
            ->selectRaw('COALESCE(SUM(CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN impressions ELSE 0 END), 0) AS position_impressions')
            ->groupByRaw($familySql)
            ->orderByDesc('impressions')
            ->get()
            ->map(fn (object $row): array => [
                'dimension' => (string) $row->dimension,
                ...$this->gscMetrics(
                    (int) $row->clicks,
                    (int) $row->impressions,
                    (int) $row->position_weight,
                    (int) $row->position_impressions,
                ),
            ])->all();
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
        return (new SeoOpportunityQueueReadService($this->connectionName))->read($limit);
    }

    /** @return array<string, mixed> */
    public function productionCloseout(): array
    {
        return (new GscProductionCloseoutReadService($this->connectionName))->read();
    }

    /** @return array<string, mixed> */
    public function technicalAudits(int $limit = 25): array
    {
        return (new SeoTechnicalAuditReadService($this->connectionName))->read($limit);
    }

    /** @return array<string,mixed> */
    public function pageInspector(string $issueUid): array
    {
        $issue = $this->table('seo_issue_queue')->where('issue_uid', $issueUid)->first();
        if ($issue === null) {
            return ['state' => 'unavailable', 'unavailable_reason' => 'issue_not_found'];
        }

        $urlHash = trim((string) ($issue->canonical_url_hash ?? ''));
        $truth = $urlHash === '' ? null : $this->table('seo_urls')->where('canonical_url_hash', $urlHash)->first();
        $canonicalPath = $this->safePath(is_string($truth->canonical_url ?? $issue->canonical_url ?? null)
            ? (string) ($truth->canonical_url ?? $issue->canonical_url)
            : null);
        if ($canonicalPath === null || $this->isPrivatePath($canonicalPath) || (bool) ($truth->is_private_flow ?? false)) {
            return ['state' => 'unavailable', 'unavailable_reason' => 'private_or_unmapped_surface'];
        }

        $metadata = $this->decodeJson($truth->metadata_json ?? null);
        $entity = null;
        try {
            $entity = $this->table('seo_url_entities')
                ->where('locale', (string) ($truth->locale ?? $issue->locale ?? ''))
                ->where('page_entity_type', (string) ($truth->page_entity_type ?? $issue->page_entity_type ?? ''))
                ->where('entity_id_or_slug', (string) ($truth->entity_id_or_slug ?? ''))
                ->first();
        } catch (Throwable) {
            $entity = null;
        }

        $classification = (new PageFamilyClassifier)->classify([
            'canonical_url' => (string) ($truth->canonical_url ?? $issue->canonical_url ?? ''),
            'locale' => (string) ($truth->locale ?? $issue->locale ?? ''),
            'page_entity_type' => (string) ($truth->page_entity_type ?? $issue->page_entity_type ?? ''),
            'entity_source' => (string) ($entity->entity_source ?? $metadata['entity_source'] ?? ''),
            'source_authority' => (string) ($truth->source_authority ?? ''),
            'authority_status' => (string) ($entity->authority_status ?? $metadata['authority_status'] ?? ''),
            'indexability_state' => (string) ($truth->indexability_state ?? ''),
            'is_private_flow' => false,
        ]);
        $gsc = $urlHash === '' ? null : $this->table('seo_gsc_daily')
            ->where('canonical_url_hash', $urlHash)
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('MAX(report_date) AS last_report_date')
            ->first();

        return [
            'state' => 'connected',
            'source' => 'seo_intel.page_inspector',
            'observed_at' => now()->toAtomString(),
            'updated_at' => $this->normalizeTimestamp($truth->updated_at ?? $issue->updated_at ?? null),
            'unavailable_reason' => null,
            'canonical_path' => $canonicalPath,
            'family' => $classification['family_id'] ?? null,
            'family_state' => $classification['classification_status'] ?? 'unclassified',
            'family_risk_cap' => $classification['agent_risk_cap'] ?? 'L0',
            'locale' => (string) ($truth->locale ?? $issue->locale ?? ''),
            'entity_type' => (string) ($truth->page_entity_type ?? $issue->page_entity_type ?? ''),
            'authority' => (string) ($truth->source_authority ?? ''),
            'entity_source' => (string) ($entity->entity_source ?? $metadata['entity_source'] ?? ''),
            'publication_state' => (string) ($entity->authority_status ?? $metadata['authority_status'] ?? 'unavailable'),
            'indexability_state' => (string) ($truth->indexability_state ?? 'unavailable'),
            'canonical_state' => (string) ($metadata['canonical_state'] ?? 'authority_path_present'),
            'hreflang_state' => $metadata['hreflang_state'] ?? 'unavailable',
            'schema_state' => $metadata['schema_state'] ?? 'unavailable',
            'sitemap_eligible' => $metadata['sitemap_eligible'] ?? null,
            'llms_eligible' => $metadata['llms_eligible'] ?? null,
            'cms_revision' => $metadata['cms_revision'] ?? $metadata['revision_id'] ?? null,
            'cms_edit_url' => $this->safeAuthenticatedPath($metadata['cms_edit_url'] ?? null),
            'preview_url' => $this->safePublicPath($metadata['preview_url'] ?? null),
            'revert_state' => $metadata['revert_state'] ?? $metadata['rollback_state'] ?? 'unavailable',
            'gsc' => $gsc === null ? ['state' => 'unavailable', 'unavailable_reason' => 'no_url_truth_binding'] : [
                'state' => $gsc->last_report_date === null ? 'measurement_hold' : 'connected',
                'clicks' => $gsc->last_report_date === null ? null : (int) $gsc->clicks,
                'impressions' => $gsc->last_report_date === null ? null : (int) $gsc->impressions,
                'updated_at' => $this->normalizeTimestamp($gsc->last_report_date),
                'unavailable_reason' => $gsc->last_report_date === null ? 'no_gsc_rows' : null,
            ],
            'issue' => [
                'issue_uid' => (string) $issue->issue_uid,
                'type' => (string) $issue->issue_type,
                'status' => (string) $issue->status,
                'summary' => $issue->summary ?? null,
                'recommendation' => $issue->recommendation ?? null,
            ],
        ];
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

    /** @return array<string,mixed>|null */
    private function detectorMetadata(mixed $metadata, string $status, string $lifecycleState): ?array
    {
        $decoded = $this->decodeJson($metadata);
        $result = is_array($decoded['detector_result'] ?? null) ? $decoded['detector_result'] : [];
        $detectorId = is_string($result['detector'] ?? null) ? $result['detector'] : '';
        $definition = (new SeoDetectorRegistry)->detectors()[$detectorId] ?? null;
        if (! is_array($definition)) {
            return null;
        }

        $rootCause = is_string($result['root_cause_or_error_code'] ?? null)
            ? trim($result['root_cause_or_error_code'])
            : '';

        return [
            'id' => $detectorId,
            'version' => (string) ($result['detector_version'] ?? ''),
            'cluster_uid' => (string) ($result['cluster_uid'] ?? ''),
            'status' => $status,
            'lifecycle_state' => $lifecycleState,
            'evidence_state' => (string) ($result['evidence_state'] ?? 'insufficient_evidence'),
            'root_cause' => preg_match('/^[a-zA-Z0-9_.:-]{1,160}$/', $rootCause) === 1 ? $rootCause : 'unavailable',
            'impact' => [
                'affected_urls' => max(0, (int) ($result['affected_url_count'] ?? 0)),
            ],
            'revisions' => [
                'authority' => (string) ($result['authority_revision'] ?? ''),
                'url_truth' => (string) ($result['url_truth_revision'] ?? ''),
                'policy' => (string) ($result['policy_version'] ?? ''),
            ],
            'recovery_conditions' => array_values((array) ($definition['recovery_conditions'] ?? [])),
        ];
    }

    private function sourceSignal(string $sourceSystem, ?string $sourceEngine): string
    {
        if ($sourceEngine === null || trim($sourceEngine) === '') {
            return $sourceSystem;
        }

        return $sourceSystem.':'.$sourceEngine;
    }

    private function isPrivatePath(string $path): bool
    {
        return preg_match('#/(?:result|results|attempt|attempts|report|reports|history|share|shares|order|orders|payment|payments|token)(?:/|$)#i', $path) === 1;
    }

    private function safeAuthenticatedPath(mixed $value): ?string
    {
        $path = is_string($value) ? $this->safePath($value) : null;

        return is_string($path) && str_starts_with($path, '/ops/') ? $path : null;
    }

    private function safePublicPath(mixed $value): ?string
    {
        $path = is_string($value) ? $this->safePath($value) : null;

        if (! is_string($path) || $this->isPrivatePath($path)) {
            return null;
        }

        foreach (['/ops', '/api', '/admin'] as $protectedPrefix) {
            if ($path === $protectedPrefix || str_starts_with($path, $protectedPrefix.'/')) {
                return null;
            }
        }

        return $path;
    }
}
