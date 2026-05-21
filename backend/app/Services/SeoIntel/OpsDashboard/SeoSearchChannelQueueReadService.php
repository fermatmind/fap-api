<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

final class SeoSearchChannelQueueReadService extends AbstractSeoDashboardReadService
{
    /**
     * @return array{
     *     total_counts:array{items:int,batches:int,events:int},
     *     aggregates:array{
     *         channel:list<array{label:string,count:int}>,
     *         approval_state:list<array{label:string,count:int}>,
     *         execution_state:list<array{label:string,count:int}>,
     *         event_type:list<array{event_type:string,count:int,latest_created_at:?string}>
     *     },
     *     recent_rows:list<array{
     *         canonical_path:?string,
     *         locale:string,
     *         page_entity_type:string,
     *         source_authority:string,
     *         channel:string,
     *         eligibility_state:string,
     *         approval_state:string,
     *         execution_state:string,
     *         indexability_state:string,
     *         claim_boundary_state:string,
     *         private_flow:bool,
     *         approved_at:?string,
     *         created_at:?string,
     *         updated_at:?string
     *     }>
     * }
     */
    public function read(int $limit = 10): array
    {
        return [
            'total_counts' => [
                'items' => $this->table('seo_search_channel_queue_items')->count(),
                'batches' => $this->table('seo_search_channel_queue_batches')->count(),
                'events' => $this->table('seo_search_channel_queue_events')->count(),
            ],
            'aggregates' => [
                'channel' => $this->groupedCounts('seo_search_channel_queue_items', 'channel'),
                'approval_state' => $this->groupedCounts('seo_search_channel_queue_items', 'approval_state'),
                'execution_state' => $this->groupedCounts('seo_search_channel_queue_items', 'execution_state'),
                'event_type' => $this->eventTypeSummary(),
            ],
            'recent_rows' => $this->table('seo_search_channel_queue_items')
                ->select([
                    'canonical_url',
                    'locale',
                    'page_entity_type',
                    'source_authority',
                    'channel',
                    'eligibility_state',
                    'approval_state',
                    'execution_state',
                    'indexability_state',
                    'claim_boundary_state',
                    'private_flow',
                    'approved_at',
                    'created_at',
                    'updated_at',
                ])
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit(max(1, min($limit, 50)))
                ->get()
                ->map(fn (object $row): array => [
                    'canonical_path' => $this->safePath(is_string($row->canonical_url ?? null) ? $row->canonical_url : null),
                    'locale' => (string) $row->locale,
                    'page_entity_type' => (string) $row->page_entity_type,
                    'source_authority' => (string) $row->source_authority,
                    'channel' => (string) $row->channel,
                    'eligibility_state' => (string) $row->eligibility_state,
                    'approval_state' => (string) $row->approval_state,
                    'execution_state' => (string) $row->execution_state,
                    'indexability_state' => (string) $row->indexability_state,
                    'claim_boundary_state' => (string) $row->claim_boundary_state,
                    'private_flow' => (bool) $row->private_flow,
                    'approved_at' => $this->normalizeTimestamp($row->approved_at ?? null),
                    'created_at' => $this->normalizeTimestamp($row->created_at ?? null),
                    'updated_at' => $this->normalizeTimestamp($row->updated_at ?? null),
                ])
                ->all(),
        ];
    }

    /**
     * @return list<array{event_type:string,count:int,latest_created_at:?string}>
     */
    private function eventTypeSummary(): array
    {
        return $this->table('seo_search_channel_queue_events')
            ->select('event_type')
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->selectRaw('MAX(created_at) AS latest_created_at')
            ->groupBy('event_type')
            ->orderBy('event_type')
            ->get()
            ->map(fn (object $row): array => [
                'event_type' => (string) $row->event_type,
                'count' => (int) ($row->aggregate_count ?? 0),
                'latest_created_at' => $this->normalizeTimestamp($row->latest_created_at ?? null),
            ])
            ->all();
    }
}
