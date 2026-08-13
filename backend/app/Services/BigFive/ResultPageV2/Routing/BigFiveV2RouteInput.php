<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Routing;

final readonly class BigFiveV2RouteInput
{
    /**
     * @param  array<string,int>  $domainRouteBands
     * @param  array<string,string>  $displayBandLabels
     * @param  list<array<string,mixed>>  $facetRouteSignals
     * @param  list<string>  $suppressionHints
     * @param  array<string,int>  $domainScores
     * @param  array<string,int>  $domainPercentiles
     * @param  list<string>  $qualityFlags
     */
    public function __construct(
        public array $domainRouteBands,
        public string $combinationKey,
        public array $displayBandLabels,
        public string $qualityStatus,
        public string $normStatus,
        public array $facetRouteSignals = [],
        public array $suppressionHints = [],
        public array $domainScores = [],
        public array $domainPercentiles = [],
        public array $qualityFlags = [],
        public string $normGroupId = '',
        public string $normVersion = '',
        public bool $percentileDisplayEligible = false,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'domain_route_bands' => $this->domainRouteBands,
            'combination_key' => $this->combinationKey,
            'display_band_labels' => $this->displayBandLabels,
            'quality_status' => $this->qualityStatus,
            'norm_status' => $this->normStatus,
            'facet_route_signals' => $this->facetRouteSignals,
            'suppression_hints' => $this->suppressionHints,
            'domain_scores' => $this->domainScores,
            'domain_percentiles' => $this->domainPercentiles,
            'quality_flags' => $this->qualityFlags,
            'norm_group_id' => $this->normGroupId,
            'norm_version' => $this->normVersion,
            'percentile_display_eligible' => $this->percentileDisplayEligible,
        ];
    }
}
