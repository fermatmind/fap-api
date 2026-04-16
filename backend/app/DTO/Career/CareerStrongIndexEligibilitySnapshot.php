<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerStrongIndexEligibilitySnapshot
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<CareerStrongIndexEligibilityMember>  $members
     * @param  array<string, mixed>  $decisionPolicy
     */
    public function __construct(
        public readonly string $snapshotKind,
        public readonly string $snapshotVersion,
        public readonly string $scope,
        public readonly array $counts,
        public readonly array $members,
        public readonly array $decisionPolicy,
        public readonly bool $weakSignalGateDeferred,
        public readonly string $derivedSignalGateState,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshot_kind' => $this->snapshotKind,
            'snapshot_version' => $this->snapshotVersion,
            'scope' => $this->scope,
            'counts' => $this->counts,
            'decision_policy' => $this->decisionPolicy,
            'weak_signal_gate_deferred' => $this->weakSignalGateDeferred,
            'derived_signal_gate_state' => $this->derivedSignalGateState,
            'members' => array_map(
                static fn (CareerStrongIndexEligibilityMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
