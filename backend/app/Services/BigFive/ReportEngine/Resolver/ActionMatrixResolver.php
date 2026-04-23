<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\ActionRuleMatch;
use App\Services\BigFive\ReportEngine\Contracts\ReportContext;

final class ActionMatrixResolver
{
    private const SCENARIO_ORDER = ['workplace', 'relationships', 'stress_recovery', 'personal_growth'];

    private const BUCKET_ORDER = ['continue', 'start', 'stop', 'observe'];

    private const REPORT_RULE_CAP = 12;

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    public function resolve(ReportContext $context, array $registry): array
    {
        $selectedByScenario = [];
        foreach (self::SCENARIO_ORDER as $scenario) {
            $pack = $registry['action_rules'][$scenario] ?? null;
            if (! is_array($pack) || ! is_array($pack['rules'] ?? null)) {
                continue;
            }

            $selectedByScenario[$scenario] = [
                'scenario_key' => $scenario,
                'title' => (string) ($pack['scenario_title'] ?? $this->titleForScenario($scenario)),
                'selected_rules' => $this->selectScenarioRules($context, $scenario, $pack['rules']),
            ];
        }

        $selectedByScenario = $this->applyReportCap($selectedByScenario);

        return [
            'scenarios' => array_values($selectedByScenario),
            'top_priority_scenario' => $this->topPriorityScenario($selectedByScenario),
            'caps' => [
                'per_scenario_per_bucket' => 1,
                'per_scenario' => 4,
                'per_report' => self::REPORT_RULE_CAP,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rules
     * @return array<string,ActionRuleMatch|null>
     */
    private function selectScenarioRules(ReportContext $context, string $scenario, array $rules): array
    {
        $candidatesByBucket = array_fill_keys(self::BUCKET_ORDER, []);
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $match = $this->matchRule($context, $scenario, $rule);
            if (! $match instanceof ActionRuleMatch || ! array_key_exists($match->bucket, $candidatesByBucket)) {
                continue;
            }

            $candidatesByBucket[$match->bucket][] = $match;
        }

        $selected = [];
        foreach (self::BUCKET_ORDER as $bucket) {
            $candidates = $candidatesByBucket[$bucket];
            usort($candidates, fn (ActionRuleMatch $left, ActionRuleMatch $right): int => $this->compareMatches($left, $right));
            $selected[$bucket] = $candidates[0] ?? null;
        }

        return $selected;
    }

    /**
     * @param  array<string,mixed>  $rule
     */
    private function matchRule(ReportContext $context, string $scenario, array $rule): ?ActionRuleMatch
    {
        $traitCode = (string) ($rule['trait_code'] ?? '');
        $percentile = $context->domainPercentile($traitCode);
        if ($percentile < (int) ($rule['percentile_min'] ?? 0) || $percentile > (int) ($rule['percentile_max'] ?? 100)) {
            return null;
        }

        $scenarioTags = array_values(array_map('strval', is_array($rule['scenario_tags'] ?? null) ? $rule['scenario_tags'] : []));
        if (! in_array($scenario, $scenarioTags, true)) {
            return null;
        }

        return new ActionRuleMatch(
            ruleId: (string) ($rule['rule_id'] ?? ''),
            scenario: $scenario,
            traitCode: $traitCode,
            bucket: (string) ($rule['bucket'] ?? ''),
            scenarioTags: $scenarioTags,
            difficultyLevel: (string) ($rule['difficulty_level'] ?? ''),
            timeHorizon: (string) ($rule['time_horizon'] ?? ''),
            title: (string) ($rule['title'] ?? ''),
            body: (string) ($rule['body'] ?? ''),
            priorityWeight: (int) ($rule['priority_weight'] ?? 0),
        );
    }

    private function compareMatches(ActionRuleMatch $left, ActionRuleMatch $right): int
    {
        if ($left->priorityWeight !== $right->priorityWeight) {
            return $right->priorityWeight <=> $left->priorityWeight;
        }

        return $left->ruleId <=> $right->ruleId;
    }

    /**
     * @param  array<string,array{scenario_key:string,title:string,selected_rules:array<string,ActionRuleMatch|null>}>  $selectedByScenario
     * @return array<string,array{scenario_key:string,title:string,selected_rules:array<string,ActionRuleMatch|null>}>
     */
    private function applyReportCap(array $selectedByScenario): array
    {
        $count = 0;
        foreach ($this->scenarioPriorityOrder($selectedByScenario) as $scenario) {
            foreach (self::BUCKET_ORDER as $bucket) {
                if (($selectedByScenario[$scenario]['selected_rules'][$bucket] ?? null) === null) {
                    continue;
                }
                $count++;
                if ($count > self::REPORT_RULE_CAP) {
                    $selectedByScenario[$scenario]['selected_rules'][$bucket] = null;
                }
            }
        }

        return $selectedByScenario;
    }

    /**
     * @param  array<string,array{scenario_key:string,title:string,selected_rules:array<string,ActionRuleMatch|null>}>  $selectedByScenario
     * @return list<string>
     */
    private function scenarioPriorityOrder(array $selectedByScenario): array
    {
        $scenarios = array_keys($selectedByScenario);
        usort($scenarios, function (string $left, string $right) use ($selectedByScenario): int {
            $leftScore = $this->scenarioScore($selectedByScenario[$left]['selected_rules']);
            $rightScore = $this->scenarioScore($selectedByScenario[$right]['selected_rules']);
            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            return array_search($left, self::SCENARIO_ORDER, true) <=> array_search($right, self::SCENARIO_ORDER, true);
        });

        return $scenarios;
    }

    /**
     * @param  array<string,ActionRuleMatch|null>  $selectedRules
     */
    private function scenarioScore(array $selectedRules): int
    {
        $score = 0;
        $count = 0;
        foreach ($selectedRules as $rule) {
            if (! $rule instanceof ActionRuleMatch) {
                continue;
            }
            $score += $rule->priorityWeight;
            $count++;
        }

        if ($count === 0) {
            return 0;
        }

        return (int) round($score / $count);
    }

    /**
     * @param  array<string,array{scenario_key:string,title:string,selected_rules:array<string,ActionRuleMatch|null>}>  $selectedByScenario
     */
    private function topPriorityScenario(array $selectedByScenario): ?string
    {
        $priorityOrder = $this->scenarioPriorityOrder($selectedByScenario);
        $top = $priorityOrder[0] ?? null;
        if ($top === null || $this->scenarioScore($selectedByScenario[$top]['selected_rules']) === 0) {
            return null;
        }

        return $top;
    }

    private function titleForScenario(string $scenario): string
    {
        return match ($scenario) {
            'workplace' => '工作场景',
            'relationships' => '关系场景',
            'stress_recovery' => '压力恢复',
            'personal_growth' => '个人成长',
            default => $scenario,
        };
    }
}
