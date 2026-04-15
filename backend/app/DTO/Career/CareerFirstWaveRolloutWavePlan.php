<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveRolloutWavePlan
{
    /**
     * @param  array{stable:int,candidate:int,hold:int,blocked:int,manual_review_needed:int}  $counts
     * @param  array{stable:list<string>,candidate:list<string>,hold:list<string>,blocked:list<string>,manual_review_needed:list<string>}  $cohorts
     * @param  list<CareerFirstWaveRolloutWavePlanMember>  $members
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $counts,
        public readonly array $cohorts,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_kind' => 'career_first_wave_rollout_wave_plan',
            'plan_version' => 'career.rollout_wave_plan.first_wave.v1',
            'scope' => $this->scope,
            'counts' => $this->counts,
            'cohorts' => $this->cohorts,
            'members' => array_map(
                static fn (CareerFirstWaveRolloutWavePlanMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
