<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveDatasetCoverage
{
    /**
     * @param  list<string>  $includedRouteKinds
     */
    public function __construct(
        public readonly string $datasetScope,
        public readonly string $waveName,
        public readonly string $memberKind,
        public readonly int $memberCount,
        public readonly int $countExpected,
        public readonly int $countActual,
        public readonly array $includedRouteKinds,
        public readonly bool $familyHubsIncluded,
        public readonly string $selectionPolicyVersion,
        public readonly string $manifestVersion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataset_scope' => $this->datasetScope,
            'wave_name' => $this->waveName,
            'member_kind' => $this->memberKind,
            'member_count' => $this->memberCount,
            'count_expected' => $this->countExpected,
            'count_actual' => $this->countActual,
            'included_route_kinds' => $this->includedRouteKinds,
            'family_hubs_included' => $this->familyHubsIncluded,
            'selection_policy_version' => $this->selectionPolicyVersion,
            'manifest_version' => $this->manifestVersion,
        ];
    }
}
