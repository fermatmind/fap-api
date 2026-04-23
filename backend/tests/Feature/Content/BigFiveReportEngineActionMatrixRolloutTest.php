<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use Tests\TestCase;

final class BigFiveReportEngineActionMatrixRolloutTest extends TestCase
{
    public function test_action_matrix_payload_has_scenario_bucket_structure(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture('context_c_low_n_high'));

        $this->assertSame(['workplace', 'relationships', 'stress_recovery', 'personal_growth'], array_column($payload['action_matrix']['scenarios'], 'scenario_key'));
        $this->assertSame('stress_recovery', $payload['action_matrix']['top_priority_scenario']);
        $this->assertSame([
            'per_scenario_per_bucket' => 1,
            'per_scenario' => 4,
            'per_report' => 12,
        ], $payload['action_matrix']['caps']);

        $selectedCount = 0;
        foreach ($payload['action_matrix']['scenarios'] as $scenario) {
            $this->assertSame(['continue', 'start', 'stop', 'observe'], array_keys($scenario['selected_rules']));
            $scenarioCount = 0;
            foreach ($scenario['selected_rules'] as $rule) {
                if ($rule !== null) {
                    $scenarioCount++;
                    $selectedCount++;
                    foreach (['scenario_tags', 'bucket', 'difficulty_level', 'time_horizon', 'title', 'body'] as $field) {
                        $this->assertArrayHasKey($field, $rule);
                    }
                }
            }
            $this->assertLessThanOrEqual(4, $scenarioCount);
        }
        $this->assertLessThanOrEqual(12, $selectedCount);
    }

    public function test_action_plan_consumes_action_matrix_blocks(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture('context_c_low_n_high'));
        $actionPlan = collect($payload['sections'])->firstWhere('section_key', 'action_plan');
        $blocks = collect($actionPlan['blocks']);

        $this->assertSame('paragraph', $blocks[0]['kind']);
        $this->assertSame('callout', $blocks[1]['kind']);
        $this->assertGreaterThanOrEqual(3, $blocks->where('kind', 'bullets')->count());
        $this->assertSame('action_matrix_intro_v1', $blocks[0]['block_id']);
        $this->assertSame('action_matrix_top_priority_stress_recovery', $blocks[1]['block_id']);

        foreach ($blocks->where('kind', 'bullets') as $block) {
            $this->assertStringStartsWith('action_matrix_scenario_', $block['block_id']);
            $this->assertNotEmpty($block['resolved_copy']['items']);
            foreach ($block['resolved_copy']['items'] as $item) {
                $this->assertContains($item['bucket'], ['continue', 'start', 'stop', 'observe']);
                $this->assertNotSame('', $item['title']);
                $this->assertNotSame('', $item['body']);
            }
        }
    }

    public function test_balanced_profile_can_emit_minimal_action_matrix_without_generic_fillers(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture('context_balanced_minimal_actions'));

        $this->assertNull($payload['action_matrix']['top_priority_scenario']);
        foreach ($payload['action_matrix']['scenarios'] as $scenario) {
            foreach ($scenario['selected_rules'] as $rule) {
                $this->assertNull($rule);
            }
        }

        $actionPlan = collect($payload['sections'])->firstWhere('section_key', 'action_plan');
        $this->assertCount(1, $actionPlan['blocks']);
        $this->assertSame('action_matrix_intro_v1', $actionPlan['blocks'][0]['block_id']);
    }

    public function test_existing_synergy_and_facet_outputs_do_not_regress(): void
    {
        $payload = app(BigFiveReportEngine::class)->generateCanonicalNSlice();

        $this->assertSame('n_high_x_e_low', data_get($payload, 'engine_decisions.selected_synergies.0.synergy_id'));
        $this->assertContains('n1_high_spike', array_map(
            static fn (array $match): string => $match['rule_id'],
            $payload['engine_decisions']['facet_anomalies']
        ));
        $this->assertSame('stress_recovery', $payload['action_matrix']['top_priority_scenario']);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/big5_engine/action_contexts/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }
}
