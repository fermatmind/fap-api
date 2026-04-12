<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerExplainabilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_job_explainability_payload_with_structured_score_breakdown(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        $this->compileChain($chain, [
            'materialization' => 'career_first_wave',
        ]);

        $response = $this->getJson('/api/v0.5/career/jobs/backend-architect/explainability')
            ->assertOk()
            ->assertJsonPath('summary_kind', 'career_explainability')
            ->assertJsonPath('summary_version', 'career.explainability.v1')
            ->assertJsonPath('subject_kind', 'job')
            ->assertJsonPath('subject_identity.canonical_slug', 'backend-architect')
            ->assertJsonStructure([
                'subject_identity' => ['occupation_uuid', 'canonical_slug', 'canonical_title_en'],
                'score_bundle' => [
                    'fit_score' => [
                        'value',
                        'integrity_state',
                        'critical_missing_fields',
                        'confidence_cap',
                        'formula_version',
                        'components',
                        'penalties',
                        'degradation_factor',
                    ],
                ],
                'strain_radar' => [
                    'integrity_state',
                    'confidence_cap',
                    'degradation_factor',
                    'formula_version',
                    'axes' => [
                        'people_friction' => ['value'],
                        'context_switch_load' => ['value'],
                        'political_load' => ['value'],
                        'uncertainty_load' => ['value'],
                        'low_autonomy_trap' => ['value'],
                        'repetition_mismatch' => ['value'],
                    ],
                ],
                'warnings',
                'claim_permissions',
                'integrity_summary',
            ]);

        $this->assertSame([
            'people_friction',
            'context_switch_load',
            'political_load',
            'uncertainty_load',
            'low_autonomy_trap',
            'repetition_mismatch',
        ], array_keys((array) data_get($response->json(), 'strain_radar.axes')));
        $this->assertNull(data_get($response->json(), 'strain_radar.axes.environment_fit'));
        $this->assertArrayNotHasKey('why_this_is_right_for_you', $response->json());
    }

    public function test_it_returns_a_recommendation_explainability_payload_with_public_safe_radar_only(): void
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

        $response = $this->getJson('/api/v0.5/career/recommendations/mbti/intj/explainability')
            ->assertOk()
            ->assertJsonPath('subject_kind', 'recommendation')
            ->assertJsonPath('subject_identity.public_route_slug', 'intj')
            ->assertJsonPath('subject_identity.type', 'INTJ-A')
            ->assertJsonPath('claim_permissions.allow_salary_comparison', false)
            ->assertJsonPath('warnings.amber_flags.0', 'cross_market_mismatch');

        $payload = $response->json();

        $this->assertSame([
            'people_friction',
            'context_switch_load',
            'political_load',
            'uncertainty_load',
            'low_autonomy_trap',
            'repetition_mismatch',
        ], array_keys((array) data_get($payload, 'strain_radar.axes')));
        $this->assertArrayNotHasKey('people_friction', $payload);
        $this->assertArrayNotHasKey('bridge_steps_90d', $payload);
    }

    public function test_it_keeps_strain_radar_machine_safe_for_missing_truth_cases(): void
    {
        $chain = CareerFoundationFixture::seedMissingTruthChain();
        $this->compileChain($chain, [
            'materialization' => 'career_first_wave',
        ]);

        $response = $this->getJson('/api/v0.5/career/jobs/backend-architect-missing-truth/explainability')
            ->assertOk()
            ->assertJsonPath('score_bundle.strain_score.integrity_state', 'restricted')
            ->assertJsonPath('strain_radar.integrity_state', 'restricted');

        $payload = $response->json();

        $this->assertSame([
            'people_friction',
            'context_switch_load',
            'political_load',
            'uncertainty_load',
            'low_autonomy_trap',
            'repetition_mismatch',
        ], array_keys((array) data_get($payload, 'strain_radar.axes')));
        $this->assertNull(data_get($payload, 'strain_radar.axes.environment_fit'));
        $this->assertNull(data_get($payload, 'strain_radar.axes.weights'));
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
            'dataset_checksum' => 'checksum-explainability-api-'.$chain['occupation']->canonical_slug,
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
