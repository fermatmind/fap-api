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
     */
    public function __construct(
        public array $domainRouteBands,
        public string $combinationKey,
        public array $displayBandLabels,
        public string $qualityStatus,
        public string $normStatus,
        public array $facetRouteSignals = [],
        public array $suppressionHints = [],
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
        ];
    }
}
