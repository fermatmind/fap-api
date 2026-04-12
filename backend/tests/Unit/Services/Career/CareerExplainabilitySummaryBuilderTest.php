<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\Explainability\CareerExplainabilitySummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerExplainabilitySummaryBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_machine_safe_job_explainability_payload(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        $this->compileChain($chain, [
            'materialization' => 'career_first_wave',
        ]);

        $summary = app(CareerExplainabilitySummaryBuilder::class)->buildForJobSlug('backend-architect');

        $this->assertNotNull($summary);
        $payload = $summary->toArray();

        $this->assertSame('career_explainability', $payload['summary_kind']);
        $this->assertSame('career.explainability.v1', $payload['summary_version']);
        $this->assertSame('job', $payload['subject_kind']);
        $this->assertSame('backend-architect', data_get($payload, 'subject_identity.canonical_slug'));
        $this->assertArrayHasKey('fit_score', (array) data_get($payload, 'score_bundle'));
        $this->assertSame([
            'value',
            'integrity_state',
            'critical_missing_fields',
            'confidence_cap',
            'formula_version',
            'components',
            'penalties',
            'degradation_factor',
        ], array_keys((array) data_get($payload, 'score_bundle.fit_score')));
        $this->assertArrayHasKey('warnings', $payload);
        $this->assertArrayHasKey('claim_permissions', $payload);
        $this->assertArrayHasKey('integrity_summary', $payload);
        $this->assertSame([
            'integrity_state',
            'confidence_cap',
            'degradation_factor',
            'formula_version',
            'axes',
        ], array_keys((array) data_get($payload, 'strain_radar')));
        $this->assertSame([
            'people_friction',
            'context_switch_load',
            'political_load',
            'uncertainty_load',
            'low_autonomy_trap',
            'repetition_mismatch',
        ], array_keys((array) data_get($payload, 'strain_radar.axes')));
        $this->assertNull(data_get($payload, 'strain_radar.axes.environment_fit'));
        $this->assertNull(data_get($payload, 'strain_radar.axes.environment_mismatch'));
        $this->assertArrayNotHasKey('why_this_path', $payload);
    }

    public function test_it_builds_a_machine_safe_recommendation_explainability_payload(): void
    {
        $chain = CareerFoundationFixture::seedTrustLimitedCrossMarketChain();
        $this->compileChain($chain, [
            'materialization' => 'career_first_wave',
            'recommendation_subject_meta' => [
                'type_code' => 'INTJ-A',
                'canonical_type_code' => 'INTJ',
                'display_title' => 'INTJ-A Career Match',
                'public_route_slug' => 'intj',
            ],
        ]);

        $summary = app(CareerExplainabilitySummaryBuilder::class)->buildForRecommendationType('intj');

        $this->assertNotNull($summary);
        $payload = $summary->toArray();

        $this->assertSame('recommendation', $payload['subject_kind']);
        $this->assertSame('intj', data_get($payload, 'subject_identity.public_route_slug'));
        $this->assertSame('INTJ-A', data_get($payload, 'subject_identity.type'));
        $this->assertSame('INTJ', data_get($payload, 'subject_identity.canonical_type_code'));
        $this->assertFalse((bool) data_get($payload, 'claim_permissions.allow_salary_comparison'));
        $this->assertContains('cross_market_mismatch', (array) data_get($payload, 'warnings.amber_flags'));
        $this->assertArrayHasKey('strain_score', (array) data_get($payload, 'score_bundle'));
        $this->assertSame('career.strain_v1.2', data_get($payload, 'strain_radar.formula_version'));
        $this->assertSame(
            ['people_friction', 'context_switch_load', 'political_load', 'uncertainty_load', 'low_autonomy_trap', 'repetition_mismatch'],
            array_keys((array) data_get($payload, 'strain_radar.axes'))
        );
        $this->assertNull(data_get($payload, 'bridge_steps_90d'));
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $projectionPayload
     */
    private function compileChain(array $chain, array $projectionPayload): void
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-explainability-'.$chain['occupation']->canonical_slug,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);
        $compileRun = CareerCompileRun::query()->create([
            'import_run_id' => $importRun->id,
            'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(7),
        ]);

        $chain['contextSnapshot']->update([
            'compile_run_id' => $compileRun->id,
            'context_payload' => ['materialization' => $projectionPayload['materialization'] ?? 'career_first_wave'],
        ]);
        $chain['childProjection']->update([
            'compile_run_id' => $compileRun->id,
            'projection_payload' => array_merge(
                is_array($chain['childProjection']->projection_payload) ? $chain['childProjection']->projection_payload : [],
                $projectionPayload,
            ),
        ]);

        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);
    }
}
