<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerThresholdExperimentAuthority
{
    public function __construct(
        public readonly CareerThresholdExperimentSnapshot $snapshot,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authority_kind' => 'career_threshold_experiment_authority',
            'authority_version' => 'career.threshold_experiment.v1',
            ...$this->snapshot->toArray(),
        ];
    }
}
