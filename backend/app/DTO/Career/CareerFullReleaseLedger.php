<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFullReleaseLedger
{
    /**
     * @param  array<string, mixed>  $trackingCounts
     * @param  array<string, int>  $releaseCounts
     * @param  array<string, int>  $opsHandoffCounts
     * @param  list<CareerFullReleaseLedgerMember>  $members
     */
    public function __construct(
        public readonly string $ledgerKind,
        public readonly string $ledgerVersion,
        public readonly string $scope,
        public readonly array $trackingCounts,
        public readonly array $releaseCounts,
        public readonly array $opsHandoffCounts,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ledger_kind' => $this->ledgerKind,
            'ledger_version' => $this->ledgerVersion,
            'scope' => $this->scope,
            'counts' => [
                'tracking_counts' => $this->trackingCounts,
                'release_counts' => $this->releaseCounts,
                'ops_handoff_counts' => $this->opsHandoffCounts,
            ],
            'members' => array_map(
                static fn (CareerFullReleaseLedgerMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
