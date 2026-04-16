<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFullDatasetAuthority
{
    /**
     * @param  array<string, mixed>  $trackingCounts
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $facetDistributions
     * @param  array<string, mixed>  $publication
     * @param  list<CareerFullDatasetMember>  $members
     */
    public function __construct(
        public readonly string $authorityKind,
        public readonly string $authorityVersion,
        public readonly string $datasetKey,
        public readonly string $datasetScope,
        public readonly string $memberKind,
        public readonly int $memberCount,
        public readonly array $trackingCounts,
        public readonly array $summary,
        public readonly array $facetDistributions,
        public readonly array $publication,
        public readonly array $members,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'authority_kind' => $this->authorityKind,
            'authority_version' => $this->authorityVersion,
            'dataset_key' => $this->datasetKey,
            'dataset_scope' => $this->datasetScope,
            'member_kind' => $this->memberKind,
            'member_count' => $this->memberCount,
            'tracking_counts' => $this->trackingCounts,
            'summary' => $this->summary,
            'facet_distributions' => $this->facetDistributions,
            'publication' => $this->publication,
            'members' => array_map(
                static fn (CareerFullDatasetMember $member): array => $member->toArray(),
                $this->members,
            ),
        ];
    }
}
