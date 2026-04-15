<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveRolloutCohortListArtifact
{
    /**
     * @param  list<string>  $members
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $cohort,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_kind' => 'career_rollout_cohort_list',
            'artifact_version' => 'career.rollout_cohort_list.export.v1',
            'scope' => $this->scope,
            'cohort' => $this->cohort,
            'members' => $this->members,
        ];
    }
}
