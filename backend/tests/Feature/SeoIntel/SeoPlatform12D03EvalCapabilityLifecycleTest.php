<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12MonthlyEvalCapabilityLifecycleEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use Tests\TestCase;

final class SeoPlatform12D03EvalCapabilityLifecycleTest extends TestCase
{
    public function test_detector_and_agent_evals_are_family_locale_stratified_with_95_percent_ci(): void
    {
        $artifact = app(Platform12MonthlyEvalCapabilityLifecycleEvaluator::class)->evaluate($this->evidence());

        $this->assertSame(['DETECTOR', 'AGENT'], array_column($artifact['evaluations'], 'eval_type'));
        $this->assertSame(['tests', 'career'], array_column($artifact['evaluations'], 'family'));
        $this->assertSame(['zh-CN', 'en'], array_column($artifact['evaluations'], 'locale'));
        $this->assertSame('STRATIFIED', $artifact['evaluations'][0]['sampling_method']);
        $this->assertSame('MEASURED', $artifact['evaluations'][0]['measurement_state']);
        $this->assertArrayHasKey('lower', $artifact['evaluations'][0]['confidence_interval_95']);
        $this->assertArrayHasKey('upper', $artifact['evaluations'][0]['confidence_interval_95']);
    }

    public function test_zero_and_insufficient_samples_are_not_measured(): void
    {
        $evidence = $this->evidence();
        $evidence['evaluations'][0]['sample_size'] = 0;
        $evidence['evaluations'][0]['success_count'] = 0;
        $evidence['evaluations'][1]['sample_size'] = 29;
        $evidence['evaluations'][1]['success_count'] = 20;

        $evaluations = app(Platform12MonthlyEvalCapabilityLifecycleEvaluator::class)->evaluate($evidence)['evaluations'];
        foreach ($evaluations as $evaluation) {
            $this->assertSame('NOT_MEASURED', $evaluation['measurement_state']);
            $this->assertNull($evaluation['success_rate']);
            $this->assertNull($evaluation['confidence_interval_95']);
        }
    }

    public function test_each_version_dimension_drift_enters_offline_eval_hold(): void
    {
        foreach (['role', 'prompt', 'model', 'tool', 'policy', 'schema', 'evidence', 'binding'] as $dimension) {
            $evidence = $this->evidence();
            $evidence['evaluations'][0]['version_vector'][$dimension] = str_repeat('f', 64);

            $artifact = app(Platform12MonthlyEvalCapabilityLifecycleEvaluator::class)->evaluate($evidence);

            $this->assertSame('HOLD', $artifact['state'], $dimension);
            $this->assertSame([$dimension], $artifact['evaluations'][0]['version_drift'], $dimension);
            $this->assertSame('OFFLINE_EVAL', $artifact['evaluations'][0]['required_next_state'], $dimension);
        }
    }

    public function test_capability_cannot_transition_directly_from_hold_to_active(): void
    {
        $artifact = app(Platform12MonthlyEvalCapabilityLifecycleEvaluator::class)->evaluate($this->evidence());
        $capability = $artifact['capabilities'][0];

        $this->assertSame('OFFLINE_EVAL', $capability['effective_candidate_state']);
        $this->assertSame('HOLD_TO_ACTIVE_BLOCKED', $capability['transition_state']);
        $this->assertFalse($capability['production_active']);
        $this->assertFalse($artifact['production_capability_activation_allowed']);
        $this->assertFalse($artifact['execution_allowed']);
    }

    public function test_all_team_invocation_is_zero_and_nonzero_input_fails_closed(): void
    {
        $artifact = app(Platform12MonthlyEvalCapabilityLifecycleEvaluator::class)->evaluate($this->evidence());
        $this->assertSame(0, $artifact['all_team_invocation']);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($artifact, 'artifact_hash'), $artifact['artifact_hash']);

        $evidence = $this->evidence();
        $evidence['all_team_invocation'] = 1;
        $held = app(Platform12MonthlyEvalCapabilityLifecycleEvaluator::class)->evaluate($evidence);
        $this->assertSame('HOLD', $held['state']);
        $this->assertSame([], $held['evaluations']);
        $this->assertSame(0, $held['all_team_invocation']);
    }

    public function test_catalog_declares_zero_budget_monthly_eval_without_runtime_registration(): void
    {
        $contracts = app(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $mission = collect($catalog['missions'])->firstWhere('mission_id', 'seo.platform12.monthly_eval_capability_lifecycle');

        $this->assertSame('monthly:01:04:20', $mission['natural_slot']);
        $this->assertSame(0, array_sum($mission['budgets']));
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertSame($catalog, app(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());
        $this->assertStringNotContainsString($mission['mission_id'], (string) file_get_contents(base_path('routes/console.php')));
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        $vector = array_fill_keys(['role', 'prompt', 'model', 'tool', 'policy', 'schema', 'evidence', 'binding'], str_repeat('a', 64));

        return [
            'evaluated_at' => '2026-10-01T04:20:00Z',
            'all_team_invocation' => 0,
            'evaluations' => [
                $this->evaluation('detector.url_truth', 'DETECTOR', 'tests', 'zh-CN', 100, 91, $vector),
                $this->evaluation('agent.opportunity_review', 'AGENT', 'career', 'en', 50, 42, $vector),
            ],
            'capabilities' => [[
                'capability_id' => 'seo.runtime_health_review',
                'current_state' => 'HOLD',
                'requested_state' => 'ACTIVE',
            ]],
        ];
    }

    /** @param array<string,string> $vector @return array<string,mixed> */
    private function evaluation(string $id, string $type, string $family, string $locale, int $sample, int $success, array $vector): array
    {
        return [
            'evaluation_id' => $id,
            'eval_type' => $type,
            'family' => $family,
            'locale' => $locale,
            'sample_size' => $sample,
            'sampling_method' => 'STRATIFIED',
            'success_count' => $success,
            'version_vector' => $vector,
            'previous_version_vector' => $vector,
        ];
    }
}
