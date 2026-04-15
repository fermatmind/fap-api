<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveRolloutBundleArtifact
{
    /**
     * @param  array{stable:int,candidate:int,hold:int,blocked:int,manual_review_needed:int}  $counts
     * @param  array{stable:list<string>,candidate:list<string>,hold:list<string>,blocked:list<string>}  $cohorts
     * @param  array{manual_review_needed:list<string>}  $advisory
     * @param  list<array<string, mixed>>  $members
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $counts,
        public readonly array $cohorts,
        public readonly array $advisory,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_kind' => 'career_rollout_bundle',
            'artifact_version' => 'career.rollout_bundle.export.v1',
            'scope' => $this->scope,
            'counts' => $this->counts,
            'cohorts' => $this->cohorts,
            'advisory' => $this->advisory,
            'members' => $this->members,
        ];
    }
}
