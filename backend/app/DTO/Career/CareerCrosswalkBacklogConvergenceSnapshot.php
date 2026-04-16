<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerCrosswalkBacklogConvergenceSnapshot
{
    /**
     * @param  array<string, mixed>  $trackingCounts
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $aging
     * @param  array<string, mixed>  $patchCoverage
     * @param  list<CareerCrosswalkBacklogConvergenceMember>  $members
     * @param  array<string, mixed>  $convergencePolicy
     */
    public function __construct(
        public readonly string $authorityKind,
        public readonly string $authorityVersion,
        public readonly string $scope,
        public readonly array $trackingCounts,
        public readonly array $counts,
        public readonly array $aging,
        public readonly array $patchCoverage,
        public readonly array $members,
        public readonly array $convergencePolicy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authority_kind' => $this->authorityKind,
            'authority_version' => $this->authorityVersion,
            'scope' => $this->scope,
            'tracking_counts' => $this->trackingCounts,
            'counts' => $this->counts,
            'aging' => $this->aging,
            'patch_coverage' => $this->patchCoverage,
            'convergence_policy' => $this->convergencePolicy,
            'members' => array_map(
                static fn (CareerCrosswalkBacklogConvergenceMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
