<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\FacetAnomalyMatch;
use App\Services\BigFive\ReportEngine\Contracts\ReportContext;

final class FacetPrecisionResolver
{
    private const TRAIT_CODES = ['O', 'C', 'E', 'A', 'N'];

    /**
     * @param  array<string,mixed>  $registry
     * @return list<FacetAnomalyMatch>
     */
    public function resolve(ReportContext $context, array $registry): array
    {
        $candidates = [];

        foreach (self::TRAIT_CODES as $traitCode) {
            $rules = $registry['facet_precision'][$traitCode]['rules'] ?? [];
            if (! is_array($rules)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $match = $this->matchRule($context, $traitCode, $rule);
                if ($match !== null) {
                    $candidates[] = $match;
                }
            }
        }

        usort($candidates, static function (FacetAnomalyMatch $a, FacetAnomalyMatch $b): int {
            $rankA = [$a->isCompound ? 1 : 0, $a->priorityWeight, $a->deltaAbs];
            $rankB = [$b->isCompound ? 1 : 0, $b->priorityWeight, $b->deltaAbs];
            if ($rankA === $rankB) {
                return $a->ruleId <=> $b->ruleId;
            }

            return $rankB <=> $rankA;
        });

        $selected = [];
        $perDomain = [];
        foreach ($candidates as $candidate) {
            $domainCount = $perDomain[$candidate->domainCode] ?? 0;
            if ($domainCount >= 2 || count($selected) >= 6) {
                continue;
            }

            $selected[] = $candidate;
            $perDomain[$candidate->domainCode] = $domainCount + 1;
        }

        return $selected;
    }

    /**
     * @param  array<string,mixed>  $rule
     */
    private function matchRule(ReportContext $context, string $traitCode, array $rule): ?FacetAnomalyMatch
    {
        $facetCodes = $this->facetCodes($rule);
        if ($facetCodes === []) {
            return null;
        }

        $when = is_array($rule['when'] ?? null) ? $rule['when'] : [];
        $domainPercentile = $context->domainPercentile($traitCode);
        if (! $this->passesDomainConstraints($when, $domainPercentile)) {
            return null;
        }

        $bestFacetCode = '';
        $bestFacetPercentile = 0;
        $bestDeltaAbs = 0;
        foreach ($facetCodes as $facetCode) {
            if (! isset($context->facets[$facetCode])) {
                return null;
            }
            $facetWhen = $this->facetWhen($when, $facetCode);
            $facetPercentile = $context->facetPercentile($facetCode);
            $deltaAbs = abs($facetPercentile - $domainPercentile);
            if (! $this->passesFacetThresholds($facetWhen, $domainPercentile, $facetPercentile, $deltaAbs)) {
                return null;
            }
            if ($deltaAbs > $bestDeltaAbs) {
                $bestFacetCode = $facetCode;
                $bestFacetPercentile = $facetPercentile;
                $bestDeltaAbs = $deltaAbs;
            }
        }
        if (! $this->passesAdditionalFacetConstraints($context, $when, $traitCode, $facetCodes, $domainPercentile)) {
            return null;
        }

        $primaryFacetCode = $bestFacetCode !== '' ? $bestFacetCode : $facetCodes[0];
        $primaryPercentile = $bestFacetPercentile !== 0 ? $bestFacetPercentile : $context->facetPercentile($primaryFacetCode);
        $deltaAbs = $bestDeltaAbs !== 0 ? $bestDeltaAbs : abs($primaryPercentile - $domainPercentile);

        return new FacetAnomalyMatch(
            ruleId: (string) ($rule['rule_id'] ?? ''),
            domainCode: $traitCode,
            facetCode: $primaryFacetCode,
            facetCodes: $facetCodes,
            anomalyType: (string) ($rule['anomaly_type'] ?? ''),
            domainPercentile: $domainPercentile,
            facetPercentile: $primaryPercentile,
            deltaAbs: $deltaAbs,
            domainBand: $this->bandFor($domainPercentile),
            facetBand: $this->bandFor($primaryPercentile),
            priorityWeight: (int) ($rule['priority_weight'] ?? 100),
            isCompound: count($facetCodes) > 1 || str_contains((string) ($rule['anomaly_type'] ?? ''), 'compound'),
            sectionTargets: array_values(array_map('strval', is_array($rule['section_targets'] ?? null) ? $rule['section_targets'] : [])),
            copy: is_array($rule['copy'] ?? null) ? $rule['copy'] : [],
        );
    }

    /**
     * @param  array<string,mixed>  $rule
     * @return list<string>
     */
    private function facetCodes(array $rule): array
    {
        if (isset($rule['facet_code'])) {
            return [(string) $rule['facet_code']];
        }

        if (! is_array($rule['facet_codes_all'] ?? null)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $rule['facet_codes_all'])));
    }

    /**
     * @param  array<string,mixed>  $when
     */
    private function passesDomainConstraints(array $when, int $domainPercentile): bool
    {
        if (isset($when['domain_percentile_min']) && $domainPercentile < (int) $when['domain_percentile_min']) {
            return false;
        }
        if (isset($when['domain_percentile_max']) && $domainPercentile > (int) $when['domain_percentile_max']) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $when
     */
    private function passesFacetThresholds(array $when, int $domainPercentile, int $facetPercentile, int $deltaAbs): bool
    {
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

    /**
     * @param  array<string,mixed>  $when
     * @return array<string,mixed>
     */
    private function facetWhen(array $when, string $facetCode): array
    {
        $facetOverrides = is_array($when['facets'][$facetCode] ?? null) ? $when['facets'][$facetCode] : [];

        return array_merge($when, $facetOverrides);
    }

    /**
     * @param  array<string,mixed>  $when
     * @param  list<string>  $primaryFacetCodes
     */
    private function passesAdditionalFacetConstraints(ReportContext $context, array $when, string $traitCode, array $primaryFacetCodes, int $domainPercentile): bool
    {
        $facets = is_array($when['facets'] ?? null) ? $when['facets'] : [];
        foreach ($facets as $facetCode => $facetWhen) {
            $facetCode = (string) $facetCode;
            if (in_array($facetCode, $primaryFacetCodes, true)) {
                continue;
            }
            if (! str_starts_with($facetCode, $traitCode) || ! is_array($facetWhen)) {
                return false;
            }
            if (! isset($context->facets[$facetCode])) {
                return false;
            }
            $facetPercentile = $context->facetPercentile($facetCode);
            $deltaAbs = abs($facetPercentile - $domainPercentile);
            if (! $this->passesFacetThresholds(array_merge($when, $facetWhen), $domainPercentile, $facetPercentile, $deltaAbs)) {
                return false;
            }
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
