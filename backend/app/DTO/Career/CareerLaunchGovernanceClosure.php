<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerLaunchGovernanceClosure
{
    /**
     * @param  array<string, int|bool>  $trackingCounts
     * @param  array<string, int>  $summary
     * @param  list<CareerLaunchGovernanceClosureMember>  $members
     * @param  array<string, bool|string>  $publicStatement
     */
    public function __construct(
        public readonly string $governanceKind,
        public readonly string $governanceVersion,
        public readonly string $scope,
        public readonly array $trackingCounts,
        public readonly array $summary,
        public readonly array $members,
        public readonly array $publicStatement,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'governance_kind' => $this->governanceKind,
            'governance_version' => $this->governanceVersion,
            'scope' => $this->scope,
            'counts' => [
                'tracking_counts' => $this->trackingCounts,
                'summary' => $this->summary,
            ],
            'members' => array_map(
                static fn (CareerLaunchGovernanceClosureMember $member): array => $member->toArray(),
                $this->members,
            ),
            'public_statement' => $this->publicStatement,
        ];
    }
}
