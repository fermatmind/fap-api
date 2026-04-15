<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveLaunchReadinessAudit
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<CareerFirstWaveLaunchReadinessAuditMember>  $members
     */
    public function __construct(
        public readonly string $summaryVersion,
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
            'summary_kind' => 'career_first_wave_launch_readiness_audit',
            'summary_version' => $this->summaryVersion,
            'scope' => $this->scope,
            'counts' => $this->counts,
            'members' => array_map(
                static fn (CareerFirstWaveLaunchReadinessAuditMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
