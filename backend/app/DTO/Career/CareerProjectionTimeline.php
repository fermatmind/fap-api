<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerProjectionTimeline
{
    /**
     * @param  list<CareerProjectionTimelineEntry>  $entries
     */
    public function __construct(
        public readonly string $timelineKind,
        public readonly string $timelineVersion,
        public readonly ?string $currentProjectionUuid,
        public readonly ?string $currentRecommendationSnapshotUuid,
        public readonly array $entries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timeline_kind' => $this->timelineKind,
            'timeline_version' => $this->timelineVersion,
            'current_projection_uuid' => $this->currentProjectionUuid,
            'current_recommendation_snapshot_uuid' => $this->currentRecommendationSnapshotUuid,
            'entries' => array_map(
                static fn (CareerProjectionTimelineEntry $entry): array => $entry->toArray(),
                $this->entries
            ),
        ];
    }
}
