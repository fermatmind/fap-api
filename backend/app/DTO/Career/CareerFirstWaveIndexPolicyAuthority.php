<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveIndexPolicyAuthority
{
    /**
     * @param  array{noindex:int,promotion_candidate:int,indexed:int,demoted:int}  $counts
     * @param  list<CareerFirstWaveIndexPolicyMember>  $members
     */
    public function __construct(
        public readonly string $scope,
        public readonly array $counts,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'policy_kind' => 'career_first_wave_index_policy_authority',
            'policy_version' => 'career.index_policy.first_wave.v1',
            'scope' => $this->scope,
            'counts' => $this->counts,
            'members' => array_map(
                static fn (CareerFirstWaveIndexPolicyMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
