<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12WeeklyEfficiencyEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use Tests\TestCase;

final class SeoPlatform12C03WeeklyEfficiencyTest extends TestCase
{
    public function test_routing_cost_time_and_locale_briefs_produce_read_only_artifact(): void
    {
        $artifact = app(Platform12WeeklyEfficiencyEvaluator::class)->evaluate($this->evidence());

        $this->assertSame('READY', $artifact['state']);
        $this->assertSame('OBSERVED', $artifact['routing']['routing_precision']['measurement_state']);
        $this->assertSame(95, $artifact['routing']['required_mode_recall']['numerator']);
        $this->assertSame(4200, $artifact['cost']['model_cost_microusd']);
        $this->assertSame(['zh-CN', 'en'], array_column($artifact['locale_briefs'], 'locale'));
        $this->assertNotSame($artifact['locale_briefs'][0]['evidence_refs'], $artifact['locale_briefs'][1]['evidence_refs']);
        $this->assertTrue($artifact['artifact_only']);
        $this->assertTrue($artifact['read_only']);
        $this->assertFalse($artifact['execution_allowed']);
        $this->assertArrayNotHasKey('weekly_cards', $artifact);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($artifact, 'artifact_hash'), $artifact['artifact_hash']);
    }

    public function test_zero_sample_metrics_are_not_measured_instead_of_zero_performance(): void
    {
        $evidence = $this->evidence();
        foreach ($evidence['routing'] as &$metric) {
            $metric = ['numerator' => 0, 'denominator' => 0];
        }
        unset($metric);

        $artifact = app(Platform12WeeklyEfficiencyEvaluator::class)->evaluate($evidence);

        $this->assertSame('READY', $artifact['state']);
        foreach ($artifact['routing'] as $metric) {
            $this->assertSame('NOT_MEASURED', $metric['measurement_state']);
            $this->assertNull($metric['numerator']);
            $this->assertSame(0, $metric['denominator']);
        }
    }

    public function test_routine_maintenance_time_remains_separate_from_non_routine_work(): void
    {
        $artifact = app(Platform12WeeklyEfficiencyEvaluator::class)->evaluate($this->evidence());

        $this->assertSame(40, $artifact['human_time']['routine_maintenance_minutes']);
        $this->assertSame(120, $artifact['human_time']['growth_project_minutes']);
        $this->assertSame(15, $artifact['human_time']['incident_minutes']);
        $this->assertSame(90, $artifact['human_time']['research_minutes']);
        $this->assertSame(30, $artifact['human_time']['outreach_minutes']);
        $this->assertTrue($artifact['routine_time_excludes_projects_incidents_research_outreach']);
    }

    public function test_over_budget_returns_backpressure_hold_without_generating_cards(): void
    {
        $evidence = $this->evidence();
        $evidence['budget']['used_microusd'] = 10001;

        $artifact = app(Platform12WeeklyEfficiencyEvaluator::class)->evaluate($evidence);

        $this->assertSame('BACKPRESSURE_HOLD', $artifact['state']);
        $this->assertTrue($artifact['budget']['backpressure']);
        $this->assertArrayNotHasKey('weekly_cards', $artifact);
    }

    public function test_invalid_ratio_or_private_fields_fail_closed_without_leaking(): void
    {
        $evidence = $this->evidence();
        $evidence['routing']['routing_precision'] = ['numerator' => 101, 'denominator' => 100];
        $evidence['user_id'] = 123;
        $evidence['private_result'] = 'private-copy';

        $artifact = app(Platform12WeeklyEfficiencyEvaluator::class)->evaluate($evidence);
        $encoded = json_encode($artifact, JSON_THROW_ON_ERROR);

        $this->assertSame('MEASUREMENT_HOLD', $artifact['state']);
        $this->assertSame('NOT_MEASURED', $artifact['routing']['routing_precision']['measurement_state']);
        $this->assertStringNotContainsString('user_id', $encoded);
        $this->assertStringNotContainsString('private-copy', $encoded);
    }

    public function test_catalog_declares_zero_budget_weekly_evaluator_without_registration(): void
    {
        $contracts = app(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $mission = collect($catalog['missions'])->firstWhere('mission_id', 'seo.platform12.weekly_routing_cost_time_locale_brief');

        $this->assertIsArray($mission);
        $this->assertSame('weekly:MON:03:30', $mission['natural_slot']);
        $this->assertSame(0, array_sum($mission['budgets']));
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertSame($catalog, app(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());
        $this->assertStringNotContainsString(
            'seo.platform12.weekly_routing_cost_time_locale_brief',
            (string) file_get_contents(base_path('routes/console.php')),
        );
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        return [
            'evaluated_at' => '2026-09-07T03:30:00Z',
            'routing' => [
                'routing_precision' => ['numerator' => 92, 'denominator' => 100],
                'routing_recall' => ['numerator' => 96, 'denominator' => 100],
                'required_mode_recall' => ['numerator' => 95, 'denominator' => 100],
                'unnecessary_mode_rate' => ['numerator' => 4, 'denominator' => 100],
                'all_team_invocation_rate' => ['numerator' => 2, 'denominator' => 100],
                'human_route_correction_rate' => ['numerator' => 3, 'denominator' => 100],
            ],
            'cost' => ['model_cost_microusd' => 4200, 'tool_cost_microusd' => 800],
            'budget' => ['limit_microusd' => 10000, 'used_microusd' => 5000],
            'human_time' => [
                'routine_maintenance_minutes' => 40,
                'growth_project_minutes' => 120,
                'incident_minutes' => 15,
                'research_minutes' => 90,
                'outreach_minutes' => 30,
            ],
            'locale_briefs' => [
                ['locale' => 'zh-CN', 'measurement_state' => 'OBSERVED', 'brief_code' => 'zh_cn.routing_stable', 'evidence_refs' => [str_repeat('a', 64)], 'unknowns' => []],
                ['locale' => 'en', 'measurement_state' => 'NOT_MEASURED', 'brief_code' => null, 'evidence_refs' => [], 'unknowns' => ['sample_zero']],
            ],
        ];
    }
}
