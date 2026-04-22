<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\FacetAnomalyMatch;
use App\Services\BigFive\ReportEngine\Contracts\ReportContext;

final class FacetPrecisionResolver
{
    /**
     * @param  array<string,mixed>  $registry
     * @return list<FacetAnomalyMatch>
     */
    public function resolve(ReportContext $context, array $registry): array
    {
        $matches = [];
        $rules = $registry['facet_precision']['N']['rules'] ?? [];
        if (! is_array($rules)) {
            return [];
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $facetCode = (string) ($rule['facet_code'] ?? '');
            $facet = is_array($context->facets[$facetCode] ?? null) ? $context->facets[$facetCode] : [];
            $domainCode = (string) ($facet['domain'] ?? 'N');
            $domainPercentile = $context->domainPercentile($domainCode);
            $facetPercentile = $context->facetPercentile($facetCode);
            $deltaAbs = abs($facetPercentile - $domainPercentile);
            $when = is_array($rule['when'] ?? null) ? $rule['when'] : [];

            if (! $this->passesThresholds($when, $domainPercentile, $facetPercentile, $deltaAbs)) {
                continue;
            }

            $matches[] = new FacetAnomalyMatch(
                ruleId: (string) ($rule['rule_id'] ?? ''),
                facetCode: $facetCode,
                anomalyType: (string) ($rule['anomaly_type'] ?? ''),
                domainPercentile: $domainPercentile,
                facetPercentile: $facetPercentile,
                deltaAbs: $deltaAbs,
                sectionTargets: array_values(array_map('strval', is_array($rule['section_targets'] ?? null) ? $rule['section_targets'] : [])),
                copy: is_array($rule['copy'] ?? null) ? $rule['copy'] : [],
            );
        }

        return $matches;
    }

    /**
     * @param  array<string,mixed>  $when
     */
    private function passesThresholds(array $when, int $domainPercentile, int $facetPercentile, int $deltaAbs): bool
    {
        if ($domainPercentile < (int) ($when['domain_percentile_min'] ?? 0)) {
            return false;
        }
        if (isset($when['facet_percentile_min']) && $facetPercentile < (int) $when['facet_percentile_min']) {
            return false;
        }
        if (isset($when['facet_percentile_max']) && $facetPercentile > (int) $when['facet_percentile_max']) {
            return false;
        }
        if ($deltaAbs < (int) ($when['delta_abs_min'] ?? 20)) {
            return false;
        }
        if (($when['cross_band_required'] ?? false) === true && $this->bandFor($domainPercentile) === $this->bandFor($facetPercentile)) {
            return false;
        }

        return true;
    }

    private function bandFor(int $percentile): string
    {
        return match (true) {
            $percentile <= 25 => 'low',
            $percentile <= 39 => 'low_mid',
            $percentile <= 59 => 'mid',
            $percentile <= 79 => 'high_mid',
            default => 'high',
        };
    }
}
