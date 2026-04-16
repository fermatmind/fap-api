<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerProjectionDeltaSummary
{
    /**
     * @param  array<string, mixed>  $scoreDeltas
     * @param  array<string, mixed>  $feedbackDeltas
     * @param  array<string, mixed>  $claimPermissionsChanged
     */
    public function __construct(
        public readonly bool $deltaAvailable,
        public readonly ?string $previousProjectionUuid,
        public readonly ?string $currentProjectionUuid,
        public readonly array $scoreDeltas,
        public readonly array $feedbackDeltas,
        public readonly bool $transitionChanged,
        public readonly bool $targetJobsChanged,
        public readonly array $claimPermissionsChanged,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'delta_available' => $this->deltaAvailable,
            'previous_projection_uuid' => $this->previousProjectionUuid,
            'current_projection_uuid' => $this->currentProjectionUuid,
            'score_deltas' => $this->scoreDeltas,
            'feedback_deltas' => $this->feedbackDeltas,
            'transition_changed' => $this->transitionChanged,
            'target_jobs_changed' => $this->targetJobsChanged,
            'claim_permissions_changed' => $this->claimPermissionsChanged,
        ];
    }
}
