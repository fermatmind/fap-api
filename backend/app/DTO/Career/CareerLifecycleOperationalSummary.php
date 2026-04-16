<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerLifecycleOperationalSummary
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<CareerLifecycleOperationalMember>  $members
     */
    public function __construct(
        public readonly string $summaryKind,
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
            'summary_kind' => $this->summaryKind,
            'summary_version' => $this->summaryVersion,
            'scope' => $this->scope,
            'counts' => $this->counts,
            'members' => array_map(
                static fn (CareerLifecycleOperationalMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
