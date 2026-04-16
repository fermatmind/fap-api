<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerProjectionTimelineEntry
{
    public function __construct(
        public readonly string $projectionUuid,
        public readonly string $recommendationSnapshotUuid,
        public readonly ?string $contextSnapshotUuid,
        public readonly ?string $feedbackUuid,
        public readonly string $entryKind,
        public readonly string $entryLabel,
        public readonly ?string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'projection_uuid' => $this->projectionUuid,
            'recommendation_snapshot_uuid' => $this->recommendationSnapshotUuid,
            'context_snapshot_uuid' => $this->contextSnapshotUuid,
            'feedback_uuid' => $this->feedbackUuid,
            'entry_kind' => $this->entryKind,
            'entry_label' => $this->entryLabel,
            'created_at' => $this->createdAt,
        ];
    }
}
