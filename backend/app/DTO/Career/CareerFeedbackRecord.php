<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFeedbackRecord
{
    public function __construct(
        public readonly string $feedbackUuid,
        public readonly string $subjectKind,
        public readonly ?string $subjectSlug,
        public readonly ?int $burnoutCheckin,
        public readonly ?int $careerSatisfaction,
        public readonly ?int $switchUrgency,
        public readonly ?string $contextSnapshotUuid,
        public readonly ?string $projectionUuid,
        public readonly ?string $recommendationSnapshotUuid,
        public readonly ?string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'feedback_uuid' => $this->feedbackUuid,
            'subject_kind' => $this->subjectKind,
            'subject_slug' => $this->subjectSlug,
            'burnout_checkin' => $this->burnoutCheckin,
            'career_satisfaction' => $this->careerSatisfaction,
            'switch_urgency' => $this->switchUrgency,
            'context_snapshot_uuid' => $this->contextSnapshotUuid,
            'projection_uuid' => $this->projectionUuid,
            'recommendation_snapshot_uuid' => $this->recommendationSnapshotUuid,
            'created_at' => $this->createdAt,
        ];
    }
}
