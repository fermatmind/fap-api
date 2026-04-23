<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ActionRuleMatch;
use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\ActionMatrixResolver;
use Tests\TestCase;

final class ActionMatrixResolverScenarioTest extends TestCase
{
    public function test_it_outputs_structured_scenario_matrix_for_canonical_profile(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $context = ReportContext::fromArray((array) data_get($registry, 'fixtures.canonical_n_slice_sensitive_independent'));

        $matrix = app(ActionMatrixResolver::class)->resolve($context, $registry);

        $this->assertSame(['workplace', 'relationships', 'stress_recovery', 'personal_growth'], array_column($matrix['scenarios'], 'scenario_key'));
        $this->assertSame('stress_recovery', $matrix['top_priority_scenario']);
        $this->assertSame(12, $this->selectedRuleCount($matrix));

        $workplace = $this->scenario($matrix, 'workplace');
        $this->assertSame('o_work_continue_frame_complexity_50_100', $workplace['selected_rules']['continue']->ruleId);
        $this->assertSame('c_work_start_20min_16_35', $workplace['selected_rules']['start']->ruleId);
        $this->assertSame('n_work_stop_hidden_load_60_79', $workplace['selected_rules']['stop']->ruleId);
        $this->assertSame('n_work_observe_trigger_meetings_60_100', $workplace['selected_rules']['observe']->ruleId);
    }

    /**
     * @param  array<string,mixed>  $matrix
     * @return array<string,mixed>
     */
    private function scenario(array $matrix, string $scenarioKey): array
    {
        foreach ($matrix['scenarios'] as $scenario) {
            if ($scenario['scenario_key'] === $scenarioKey) {
                return $scenario;
            }
        }

        $this->fail("Missing scenario {$scenarioKey}");
    }

    /**
     * @param  array<string,mixed>  $matrix
     */
    private function selectedRuleCount(array $matrix): int
    {
        $count = 0;
        foreach ($matrix['scenarios'] as $scenario) {
            foreach ($scenario['selected_rules'] as $rule) {
                if ($rule instanceof ActionRuleMatch) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
