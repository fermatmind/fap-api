<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Contracts;

final class FacetAnomalyMatch
{
    /**
     * @param  array<string,mixed>  $copy
     * @param  list<string>  $sectionTargets
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $facetCode,
        public readonly string $anomalyType,
        public readonly int $domainPercentile,
        public readonly int $facetPercentile,
        public readonly int $deltaAbs,
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
            'facet_code' => $this->facetCode,
            'anomaly_type' => $this->anomalyType,
            'domain_percentile' => $this->domainPercentile,
            'facet_percentile' => $this->facetPercentile,
            'delta_abs' => $this->deltaAbs,
            'section_targets' => $this->sectionTargets,
        ];
    }
}
