<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

final class SeoDashboardOverviewReadService extends AbstractSeoDashboardReadService
{
    /**
     * @return array{
     *     heartbeat:list<array{key:string,label:string,value:int}>,
     *     safety:list<array{key:string,label:string,value:int,alert:bool}>
     * }
     */
    public function read(): array
    {
        $urlTruth = (new SeoUrlTruthReadService($this->connectionName))->read();
        $queue = (new SeoSearchChannelQueueReadService($this->connectionName))->read();

        return [
            'heartbeat' => [
                [
                    'key' => 'url_truth_total',
                    'label' => 'URL Truth URLs',
                    'value' => (int) $urlTruth['total_count'],
                ],
                [
                    'key' => 'url_entity_mapping_total',
                    'label' => 'URL Entities',
                    'value' => $this->table('seo_url_entities')->count(),
                ],
                [
                    'key' => 'issue_queue_total',
                    'label' => 'Issue Queue',
                    'value' => $this->table('seo_issue_queue')->count(),
                ],
                [
                    'key' => 'search_channel_queue_item_total',
                    'label' => 'Search Channel Queue Items',
                    'value' => (int) data_get($queue, 'total_counts.items', 0),
                ],
                [
                    'key' => 'search_channel_queue_batch_total',
                    'label' => 'Search Channel Queue Batches',
                    'value' => (int) data_get($queue, 'total_counts.batches', 0),
                ],
                [
                    'key' => 'search_channel_queue_event_total',
                    'label' => 'Search Channel Events',
                    'value' => (int) data_get($queue, 'total_counts.events', 0),
                ],
            ],
            'safety' => [
                $this->safetyCard('private_flow_count', 'Private-flow leaks', (int) data_get($urlTruth, 'safety_counts.private_flow_count', 0)),
                $this->safetyCard('forbidden_authority_count', 'Forbidden authority', (int) data_get($urlTruth, 'safety_counts.forbidden_authority_count', 0)),
                $this->safetyCard('claim_unsafe_count', 'Claim unsafe', (int) data_get($urlTruth, 'safety_counts.claim_unsafe_count', 0)),
            ],
        ];
    }

    /**
     * @return array{key:string,label:string,value:int,alert:bool}
     */
    private function safetyCard(string $key, string $label, int $value): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'alert' => $value > 0,
        ];
    }
}
