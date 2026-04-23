<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Contracts;

final class FacetAnomalyMatch
{
    /**
     * @param  array<string,mixed>  $copy
     * @param  list<string>  $sectionTargets
     * @param  list<string>  $facetCodes
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $domainCode,
        public readonly string $facetCode,
        public readonly array $facetCodes,
        public readonly string $anomalyType,
        public readonly int $domainPercentile,
        public readonly int $facetPercentile,
        public readonly int $deltaAbs,
        public readonly string $domainBand,
        public readonly string $facetBand,
        public readonly int $priorityWeight,
        public readonly bool $isCompound,
        public readonly array $sectionTargets,
        public readonly array $copy,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'domain_code' => $this->domainCode,
            'facet_code' => $this->facetCode,
            'facet_codes' => $this->facetCodes,
            'anomaly_type' => $this->anomalyType,
            'domain_percentile' => $this->domainPercentile,
            'facet_percentile' => $this->facetPercentile,
            'delta_abs' => $this->deltaAbs,
            'domain_band' => $this->domainBand,
            'facet_band' => $this->facetBand,
            'priority_weight' => $this->priorityWeight,
            'is_compound' => $this->isCompound,
            'section_targets' => $this->sectionTargets,
        ];
    }
}
