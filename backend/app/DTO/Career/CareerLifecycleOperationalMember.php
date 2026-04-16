<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerLifecycleOperationalMember
{
    public function __construct(
        public readonly string $memberKind,
        public readonly string $canonicalSlug,
        public readonly ?string $currentProjectionUuid,
        public readonly ?string $currentRecommendationSnapshotUuid,
        public readonly int $timelineEntryCount,
        public readonly ?string $latestFeedbackAt,
        public readonly bool $deltaAvailable,
        public readonly string $lifecycleState,
        public readonly string $closureState,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_kind' => $this->memberKind,
            'canonical_slug' => $this->canonicalSlug,
            'current_projection_uuid' => $this->currentProjectionUuid,
            'current_recommendation_snapshot_uuid' => $this->currentRecommendationSnapshotUuid,
            'timeline_entry_count' => $this->timelineEntryCount,
            'latest_feedback_at' => $this->latestFeedbackAt,
            'delta_available' => $this->deltaAvailable,
            'lifecycle_state' => $this->lifecycleState,
            'closure_state' => $this->closureState,
        ];
    }
}
