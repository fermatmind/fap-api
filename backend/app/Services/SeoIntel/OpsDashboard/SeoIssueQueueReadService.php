<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

final class SeoIssueQueueReadService extends AbstractSeoDashboardReadService
{
    /**
     * @return array{
     *     total_count:int,
     *     aggregates:array{
     *         issue_type:list<array{label:string,count:int}>,
     *         severity:list<array{label:string,count:int}>,
     *         status:list<array{label:string,count:int}>
     *     },
     *     recent_rows:list<array{
     *         canonical_path:?string,
     *         locale:?string,
     *         page_entity_type:?string,
     *         issue_type:string,
     *         severity:string,
     *         source_system:string,
     *         source_engine:?string,
     *         status:string,
     *         lifecycle_state:string,
     *         detected_at:?string,
     *         updated_at:?string
     *     }>
     * }
     */
    public function read(int $limit = 10): array
    {
        return [
            'total_count' => $this->table('seo_issue_queue')->count(),
            'aggregates' => [
                'issue_type' => $this->groupedCounts('seo_issue_queue', 'issue_type'),
                'severity' => $this->groupedCounts('seo_issue_queue', 'severity'),
                'status' => $this->groupedCounts('seo_issue_queue', 'status'),
            ],
            'recent_rows' => $this->table('seo_issue_queue')
                ->select([
                    'canonical_url',
                    'locale',
                    'page_entity_type',
                    'issue_type',
                    'severity',
                    'source_system',
                    'source_engine',
                    'status',
                    'lifecycle_state',
                    'detected_at',
                    'updated_at',
                ])
                ->orderByDesc('detected_at')
                ->orderByDesc('updated_at')
                ->limit(max(1, min($limit, 50)))
                ->get()
                ->map(fn (object $row): array => [
                    'canonical_path' => $this->safePath(is_string($row->canonical_url ?? null) ? $row->canonical_url : null),
                    'locale' => isset($row->locale) ? (string) $row->locale : null,
                    'page_entity_type' => isset($row->page_entity_type) ? (string) $row->page_entity_type : null,
                    'issue_type' => (string) $row->issue_type,
                    'severity' => (string) $row->severity,
                    'source_system' => (string) $row->source_system,
                    'source_engine' => isset($row->source_engine) ? (string) $row->source_engine : null,
                    'status' => (string) $row->status,
                    'lifecycle_state' => (string) $row->lifecycle_state,
                    'detected_at' => $this->normalizeTimestamp($row->detected_at ?? null),
                    'updated_at' => $this->normalizeTimestamp($row->updated_at ?? null),
                ])
                ->all(),
        ];
    }
}
