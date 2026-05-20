<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Support\Facades\DB;

final class SearchChannelQueueWriteService
{
    public function __construct(private readonly SearchChannelQueueAuditLogger $events) {}

    /**
     * @param  list<array<string, mixed>>  $plannedItems
     * @return array<string, mixed>
     */
    public function write(array $plannedItems): array
    {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $now = now();
        $batchIds = [];
        $writtenItems = 0;

        foreach ($this->groupByChannel($plannedItems) as $channel => $items) {
            $batchId = $connection->table('seo_search_channel_queue_batches')->insertGetId([
                'channel' => $channel,
                'status' => 'dry_run',
                'item_count' => count($items),
                'dry_run_report' => json_encode([
                    'channel' => $channel,
                    'item_count' => count($items),
                    'no_live_submission' => true,
                    'external_calls_attempted' => false,
                ], JSON_THROW_ON_ERROR),
                'approval_note' => null,
                'created_by' => 'seo-intel:search-channel-queue',
                'approved_by' => null,
                'approved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $batchIds[] = $batchId;

            $this->events->log($connection, null, (int) $batchId, 'batch_dry_run_created', [
                'channel' => $channel,
                'item_count' => count($items),
            ]);

            foreach ($items as $item) {
                $connection->table('seo_search_channel_queue_items')->updateOrInsert(
                    ['idempotency_key' => $item['idempotency_key']],
                    [
                        'batch_id' => $batchId,
                        'canonical_url' => $item['canonical_url'],
                        'locale' => $item['locale'],
                        'page_entity_type' => $item['page_entity_type'],
                        'entity_type' => $item['entity_type'],
                        'entity_id' => $item['entity_id'],
                        'source_authority' => $item['source_authority'],
                        'source_table' => $item['source_table'],
                        'channel' => $item['channel'],
                        'eligibility_state' => $item['eligibility_state'],
                        'approval_state' => $item['approval_state'],
                        'execution_state' => $item['execution_state'],
                        'indexability_state' => $item['indexability_state'],
                        'claim_boundary_state' => $item['claim_boundary_state'],
                        'private_flow' => $item['private_flow'],
                        'reason_codes' => json_encode($item['reason_codes'], JSON_THROW_ON_ERROR),
                        'lastmod' => $item['lastmod'],
                        'content_hash' => $item['content_hash'],
                        'url_hash' => $item['url_hash'],
                        'approved_by' => null,
                        'approved_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                $queueItemId = $connection->table('seo_search_channel_queue_items')
                    ->where('idempotency_key', $item['idempotency_key'])
                    ->value('id');

                $this->events->log($connection, is_numeric($queueItemId) ? (int) $queueItemId : null, (int) $batchId, 'queue_item_planned', [
                    'channel' => $channel,
                    'url_hash' => $item['url_hash'],
                    'eligibility_state' => $item['eligibility_state'],
                    'approval_state' => $item['approval_state'],
                    'execution_state' => $item['execution_state'],
                ]);

                $writtenItems++;
            }
        }

        return [
            'batch_ids' => $batchIds,
            'written_items' => $writtenItems,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $plannedItems
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByChannel(array $plannedItems): array
    {
        $grouped = [];

        foreach ($plannedItems as $item) {
            $channel = (string) $item['channel'];
            $grouped[$channel] ??= [];
            $grouped[$channel][] = $item;
        }

        ksort($grouped);

        return $grouped;
    }
}
