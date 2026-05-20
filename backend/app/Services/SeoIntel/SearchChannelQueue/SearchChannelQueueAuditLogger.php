<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\SearchChannelQueue;

use Illuminate\Database\ConnectionInterface;

final class SearchChannelQueueAuditLogger
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(
        ConnectionInterface $connection,
        ?int $queueItemId,
        ?int $batchId,
        string $eventType,
        array $payload = [],
        string $actorType = 'system',
        ?string $actorId = null,
    ): void {
        $connection->table('seo_search_channel_queue_events')->insert([
            'queue_item_id' => $queueItemId,
            'batch_id' => $batchId,
            'event_type' => $eventType,
            'event_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
    }
}
