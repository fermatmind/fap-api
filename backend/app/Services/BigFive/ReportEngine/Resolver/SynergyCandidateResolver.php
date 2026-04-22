<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Contracts\SynergyMatch;
use App\Services\BigFive\ReportEngine\Rules\RuleEvaluator;
use App\Services\BigFive\ReportEngine\Rules\WeightCalculator;

final class SynergyCandidateResolver
{
    public function __construct(
        private readonly RuleEvaluator $ruleEvaluator = new RuleEvaluator,
        private readonly WeightCalculator $weightCalculator = new WeightCalculator,
    ) {}

    /**
     * @param  array<string,mixed>  $registry
     * @return list<SynergyMatch>
     */
    public function collect(ReportContext $context, array $registry): array
    {
        $matches = [];
        foreach (($registry['synergies'] ?? []) as $synergyId => $synergy) {
            if (! is_array($synergy) || ! is_array($synergy['trigger'] ?? null)) {
                continue;
            }
            if (! $this->ruleEvaluator->evaluate($synergy['trigger'], $context)) {
                continue;
            }

            $matches[] = new SynergyMatch(
                synergyId: (string) ($synergy['synergy_id'] ?? $synergyId),
                title: (string) ($synergy['title'] ?? ''),
                priorityWeight: $this->weightCalculator->calculate(
                    (string) ($synergy['priority_weight_formula'] ?? $synergy['priority_weight'] ?? ''),
                    $context,
                    (float) ($synergy['priority_hint'] ?? 0),
                ),
                mutexGroup: (string) ($synergy['mutex_group'] ?? ''),
                mutualExcludes: array_values(array_map('strval', is_array($synergy['mutual_excludes'] ?? null) ? $synergy['mutual_excludes'] : [])),
                maxShow: max(1, (int) ($synergy['max_show'] ?? 1)),
                sectionTargets: array_values(is_array($synergy['section_targets'] ?? null) ? $synergy['section_targets'] : []),
                copy: is_array($synergy['copy'] ?? null) ? $synergy['copy'] : [],
            );
        }

        return $matches;
    }
}
