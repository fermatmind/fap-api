<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

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
                    'locale',
                    'page_entity_type',
                    'status',
                    'lifecycle_state',
                    'detected_at',
                    'updated_at',
                    'summary',
                    'recommendation',
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
                    'detected_at' => $this->normalizeTimestamp($row->detected_at ?? null),
                    'updated_at' => $this->normalizeTimestamp($row->updated_at ?? null),
                    'summary' => isset($row->summary) ? (string) $row->summary : null,
                    'recommendation' => isset($row->recommendation) ? (string) $row->recommendation : null,
                ])
                ->all(),
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

    private function sourceSignal(string $sourceSystem, ?string $sourceEngine): string
    {
        if ($sourceEngine === null || trim($sourceEngine) === '') {
            return $sourceSystem;
        }

        return $sourceSystem.':'.$sourceEngine;
    }
}
