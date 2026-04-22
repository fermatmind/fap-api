<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\ActionRuleMatch;
use App\Services\BigFive\ReportEngine\Contracts\ReportContext;

final class ActionMatrixResolver
{
    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,list<ActionRuleMatch>>
     */
    public function resolve(ReportContext $context, array $registry): array
    {
        $matrix = [];
        foreach (($registry['action_rules'] ?? []) as $scenario => $pack) {
            if (! is_array($pack) || ! is_array($pack['rules'] ?? null)) {
                continue;
            }

            foreach ($pack['rules'] as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                $traitCode = (string) ($rule['trait_code'] ?? '');
                $percentile = $context->domainPercentile($traitCode);
                if ($percentile < (int) ($rule['percentile_min'] ?? 0) || $percentile > (int) ($rule['percentile_max'] ?? 100)) {
                    continue;
                }

                $matrix[(string) $scenario][] = new ActionRuleMatch(
                    ruleId: (string) ($rule['rule_id'] ?? ''),
                    scenario: (string) $scenario,
                    traitCode: $traitCode,
                    bucket: (string) ($rule['bucket'] ?? ''),
                    scenarioTags: array_values(array_map('strval', is_array($rule['scenario_tags'] ?? null) ? $rule['scenario_tags'] : [])),
                    difficultyLevel: (string) ($rule['difficulty_level'] ?? ''),
                    timeHorizon: (string) ($rule['time_horizon'] ?? ''),
                    title: (string) ($rule['title'] ?? ''),
                    body: (string) ($rule['body'] ?? ''),
                );
            }
        }

        return $matrix;
    }
}
