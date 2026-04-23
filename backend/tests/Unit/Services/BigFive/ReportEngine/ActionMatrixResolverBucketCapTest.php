<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ActionRuleMatch;
use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Resolver\ActionMatrixResolver;
use Tests\TestCase;

final class ActionMatrixResolverBucketCapTest extends TestCase
{
    public function test_each_scenario_bucket_keeps_only_one_rule_and_report_cap_is_twelve(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $context = ReportContext::fromArray($this->fixture('context_e_high_a_low'));

        $matrix = app(ActionMatrixResolver::class)->resolve($context, $registry);

        $selectedCount = 0;
        foreach ($matrix['scenarios'] as $scenario) {
            $this->assertSame(['continue', 'start', 'stop', 'observe'], array_keys($scenario['selected_rules']));
            $scenarioCount = 0;
            foreach ($scenario['selected_rules'] as $rule) {
                if ($rule instanceof ActionRuleMatch) {
                    $scenarioCount++;
                    $selectedCount++;
                }
            }
            $this->assertLessThanOrEqual(4, $scenarioCount);
        }

        $this->assertLessThanOrEqual(12, $selectedCount);
        $relationships = collect($matrix['scenarios'])->firstWhere('scenario_key', 'relationships');
        $this->assertSame('a_rel_start_boundary_sentence_00_35', $relationships['selected_rules']['start']->ruleId);
        $this->assertSame('e_rel_stop_speed_as_pressure_70_100', $relationships['selected_rules']['stop']->ruleId);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/big5_engine/action_contexts/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }
}
