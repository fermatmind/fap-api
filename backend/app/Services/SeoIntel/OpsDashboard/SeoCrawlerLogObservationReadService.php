<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

final class SeoCrawlerLogObservationReadService extends AbstractSeoDashboardReadService
{
    /**
     * @return array{
     *     total_count:int,
     *     total_hits:int,
     *     aggregates:array{
     *         bot_family:list<array{label:string,count:int}>,
     *         surface_family:list<array{label:string,count:int}>,
     *         route_family:list<array{label:string,count:int}>,
     *         http_status:list<array{label:string,count:int}>,
     *         query_risk_state:list<array{label:string,count:int}>,
     *         private_path_blocked:list<array{label:string,count:int}>
     *     },
     *     safety_counts:array{
     *         private_path_blocked_count:int,
     *         sensitive_query_count:int,
     *         api_or_ops_surface_count:int,
     *         unknown_bot_count:int
     *     },
     *     recent_rows:list<array{
     *         log_date:?string,
     *         host:string,
     *         surface_family:string,
     *         bot_family:string,
     *         bot_variant:string,
     *         bot_verification_state:string,
     *         route_family:string,
     *         page_entity_type:?string,
     *         canonical_path:?string,
     *         http_status:?int,
     *         method_bucket:string,
     *         query_present:bool,
     *         query_risk_state:string,
     *         private_path_blocked:bool,
     *         hit_count:int,
     *         first_seen_at:?string,
     *         last_seen_at:?string,
     *         source_log_family:string,
     *         privacy_transform_version:string,
     *         updated_at:?string
     *     }>
     * }
     */
    public function read(int $limit = 10): array
    {
        return [
            'total_count' => $this->table('seo_crawler_log_daily_aggregates')->count(),
            'total_hits' => (int) $this->table('seo_crawler_log_daily_aggregates')->sum('hit_count'),
            'aggregates' => [
                'bot_family' => $this->groupedCounts('seo_crawler_log_daily_aggregates', 'bot_family'),
                'surface_family' => $this->groupedCounts('seo_crawler_log_daily_aggregates', 'surface_family'),
                'route_family' => $this->groupedCounts('seo_crawler_log_daily_aggregates', 'route_family'),
                'http_status' => $this->groupedCounts('seo_crawler_log_daily_aggregates', 'http_status'),
                'query_risk_state' => $this->groupedCounts('seo_crawler_log_daily_aggregates', 'query_risk_state'),
                'private_path_blocked' => $this->groupedCounts('seo_crawler_log_daily_aggregates', 'private_path_blocked'),
            ],
            'safety_counts' => [
                'private_path_blocked_count' => $this->table('seo_crawler_log_daily_aggregates')
                    ->where('private_path_blocked', true)
                    ->count(),
                'sensitive_query_count' => $this->table('seo_crawler_log_daily_aggregates')
                    ->where('query_risk_state', 'sensitive_key_present')
                    ->count(),
                'api_or_ops_surface_count' => $this->table('seo_crawler_log_daily_aggregates')
                    ->whereIn('surface_family', ['api', 'ops'])
                    ->count(),
                'unknown_bot_count' => $this->table('seo_crawler_log_daily_aggregates')
                    ->where('bot_family', 'unknown_bot')
                    ->count(),
            ],
            'recent_rows' => $this->table('seo_crawler_log_daily_aggregates')
                ->select([
                    'log_date',
                    'host',
                    'surface_family',
                    'bot_family',
                    'bot_variant',
                    'bot_verification_state',
                    'route_family',
                    'page_entity_type',
                    'canonical_path',
                    'http_status',
                    'method_bucket',
                    'query_present',
                    'query_risk_state',
                    'private_path_blocked',
                    'hit_count',
                    'first_seen_at',
                    'last_seen_at',
                    'source_log_family',
                    'privacy_transform_version',
                    'updated_at',
                ])
                ->orderByDesc('last_seen_at')
                ->orderByDesc('updated_at')
                ->limit(max(1, min($limit, 50)))
                ->get()
                ->map(fn (object $row): array => [
                    'log_date' => $this->normalizeTimestamp($row->log_date ?? null),
                    'host' => (string) $row->host,
                    'surface_family' => (string) $row->surface_family,
                    'bot_family' => (string) $row->bot_family,
                    'bot_variant' => (string) $row->bot_variant,
                    'bot_verification_state' => (string) $row->bot_verification_state,
                    'route_family' => (string) $row->route_family,
                    'page_entity_type' => isset($row->page_entity_type) ? (string) $row->page_entity_type : null,
                    'canonical_path' => isset($row->canonical_path) ? (string) $row->canonical_path : null,
                    'http_status' => isset($row->http_status) ? (int) $row->http_status : null,
                    'method_bucket' => (string) $row->method_bucket,
                    'query_present' => (bool) $row->query_present,
                    'query_risk_state' => (string) $row->query_risk_state,
                    'private_path_blocked' => (bool) $row->private_path_blocked,
                    'hit_count' => (int) $row->hit_count,
                    'first_seen_at' => $this->normalizeTimestamp($row->first_seen_at ?? null),
                    'last_seen_at' => $this->normalizeTimestamp($row->last_seen_at ?? null),
                    'source_log_family' => (string) $row->source_log_family,
                    'privacy_transform_version' => (string) $row->privacy_transform_version,
                    'updated_at' => $this->normalizeTimestamp($row->updated_at ?? null),
                ])
                ->all(),
        ];
    }
}
