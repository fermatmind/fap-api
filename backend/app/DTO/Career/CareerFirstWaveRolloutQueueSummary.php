<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveRolloutQueueSummary
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $queueItems
     */
    public function __construct(
        public readonly string $summaryVersion,
        public readonly string $scope,
        public readonly array $counts,
        public readonly array $queueItems,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary_kind' => 'career_first_wave_rollout_queue',
            'summary_version' => $this->summaryVersion,
            'scope' => $this->scope,
            'counts' => $this->counts,
            'queue_items' => $this->queueItems,
        ];
    }
}
