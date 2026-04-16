<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWavePromotionCandidateAuthority
{
    /**
     * @param  array{auto_nominate:int,manual_review_only:int,not_eligible:int}  $counts
     * @param  list<CareerFirstWavePromotionCandidateMember>  $members
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
            'engine_kind' => 'career_first_wave_promotion_candidate_engine',
            'engine_version' => 'career.promotion_candidate.first_wave.v1',
            'scope' => $this->scope,
            'counts' => $this->counts,
            'members' => array_map(
                static fn (CareerFirstWavePromotionCandidateMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
