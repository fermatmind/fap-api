<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationDetailApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_resource_backed_recommendation_detail_bundle_with_provenance_and_claims(): void
    {
        $this->compileRecommendationChain(CareerFoundationFixture::seedTrustLimitedCrossMarketChain());

        $response = $this->getJson('/api/v0.5/career/recommendations/mbti/intj')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_recommendation_detail')
            ->assertJsonPath('recommendation_subject_meta.type_code', 'INTJ-A')
            ->assertJsonPath('claim_permissions.allow_salary_comparison', false)
            ->assertJsonPath('seo_contract.canonical_path', '/career/recommendations/mbti/intj')
            ->assertJsonPath('seo_contract.index_eligible', false)
            ->assertJsonPath('matched_jobs.0.canonical_slug', 'backend-architect-cn-market')
            ->assertJsonPath('matched_jobs.0.seo_contract.index_eligible', false)
            ->assertJsonPath('matched_jobs.0.seo_contract.index_state', 'trust_limited')
            ->assertJsonPath('matched_jobs.0.trust_summary.reviewer_status', 'pending')
            ->assertJsonStructure([
                'identity',
                'recommendation_subject_meta',
                'supporting_truth_summary',
                'score_bundle' => ['fit_score'],
                'white_box_scores' => [
                    'strain_score' => [
                        'score',
                        'integrity_state',
                        'degradation_factor',
                        'formula_breakdown',
                        'component_weights',
                        'penalties',
                        'warnings',
                    ],
                ],
                'warnings',
                'claim_permissions',
                'integrity_summary',
                'trust_manifest',
                'matched_jobs' => [[
                    'occupation_uuid',
                    'canonical_slug',
                    'title',
                    'seo_contract' => ['canonical_path', 'canonical_target', 'index_state', 'index_eligible', 'reason_codes'],
                    'trust_summary' => ['reviewer_status'],
                ]],
                'seo_contract',
                'provenance_meta' => ['compiler_version', 'compile_refs'],
            ])
            ->assertJsonPath('white_box_scores.strain_score.radar_dimensions.0.dimension', 'people_friction')
            ->assertJsonMissingPath('white_box_scores.strain_score.formula_ref')
            ->assertJsonMissingPath('white_box_scores.strain_score.critical_missing_fields');

        $this->assertIsString((string) $response->json('matched_jobs.0.occupation_uuid'));
        $this->assertNotSame('', (string) $response->json('matched_jobs.0.occupation_uuid'));
        $this->assertIsNumeric($response->json('white_box_scores.strain_score.score'));
        $this->assertIsString((string) $response->json('white_box_scores.strain_score.integrity_state'));
    }

    public function test_it_returns_authority_owned_matched_jobs_without_using_heavy_job_payload_fields(): void
    {
        $this->compileRecommendationChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-intj-api'])
        );
        $this->compileRecommendationChain(CareerFoundationFixture::seedTrustLimitedCrossMarketChain());

        $response = $this->getJson('/api/v0.5/career/recommendations/mbti/intj')
            ->assertOk();

        $matchedJobs = (array) $response->json('matched_jobs');

        $this->assertCount(2, $matchedJobs);
        $this->assertSame(
            ['backend-architect-cn-market', 'backend-architect-intj-api'],
            array_map(
                static fn (array $job): string => (string) ($job['canonical_slug'] ?? ''),
                $matchedJobs
            )
        );
        $this->assertArrayNotHasKey('summary', $matchedJobs[0]);
        $this->assertArrayNotHasKey('fit_bucket', $matchedJobs[0]);
        $this->assertArrayNotHasKey('score_bundle', $matchedJobs[0]);
    }

    public function test_it_additively_exposes_transition_path_contract_with_structured_bridge_and_tradeoff_fields(): void
    {
        $snapshot = $this->compileRecommendationChainBySlug('recommendation-transition-contract');
        $target = $this->seedTargetOccupation('registered-nurses', 'Registered Nurses');
        $this->mockReadinessSummary([
            $this->readinessRow('registered-nurses', 'publish_ready', true, 'indexable', 'approved'),
        ]);

        \App\Models\TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'stable_upside',
            'path_payload' => [
                'steps' => [
                    TransitionPathPayload::STEP_SKILL_OVERLAP,
                    TransitionPathPayload::STEP_TASK_OVERLAP,
                    TransitionPathPayload::STEP_TOOL_OVERLAP,
                ],
                'rationale_codes' => [
                    TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET,
                    TransitionPathPayload::RATIONALE_PUBLISH_READY_TARGET,
                ],
                'tradeoff_codes' => [
                    TransitionPathPayload::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED,
                ],
                'delta' => [
                    TransitionPathPayload::DELTA_ENTRY_EDUCATION => [
                        'source_value' => "Bachelor's degree",
                        'target_value' => "Master's degree",
                        'direction' => TransitionPathPayload::DELTA_DIRECTION_HIGHER,
                    ],
                ],
            ],
        ]);

        $response = $this->getJson('/api/v0.5/career/recommendations/mbti/intj')
            ->assertOk()
            ->assertJsonPath('transition_path.path_type', 'stable_upside')
            ->assertJsonPath('transition_path.target_job.canonical_slug', 'registered-nurses')
            ->assertJsonPath('transition_path.rationale_codes.0', TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET)
            ->assertJsonPath('transition_path.tradeoff_codes.0', TransitionPathPayload::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED)
            ->assertJsonPath('transition_path.bridge_steps_90d.0.step_key', TransitionPathPayload::STEP_SKILL_OVERLAP)
            ->assertJsonPath('transition_path.bridge_steps_90d.0.time_horizon', \App\Services\Career\Transition\CareerTransitionContractBuilder::TIME_HORIZON_DAYS_0_30)
            ->assertJsonStructure([
                'transition_path' => [
                    'path_type',
                    'steps',
                    'delta',
                    'target_job',
                    'score_summary',
                    'trust_summary',
                    'why_this_path',
                    'what_is_lost',
                    'bridge_steps_90d',
                    'rationale_codes',
                    'tradeoff_codes',
                ],
            ])
            ->assertJsonMissingPath('transition_path.bundle_kind')
            ->assertJsonMissingPath('transition_path.bundle_version')
            ->assertJsonMissingPath('transition_path.seo_contract')
            ->assertJsonMissingPath('transition_path.provenance_meta');

        $this->assertContains(
            data_get($response->json(), 'transition_path.bridge_steps_90d.0.time_horizon'),
            [
                \App\Services\Career\Transition\CareerTransitionContractBuilder::TIME_HORIZON_DAYS_0_30,
                \App\Services\Career\Transition\CareerTransitionContractBuilder::TIME_HORIZON_DAYS_31_60,
                \App\Services\Career\Transition\CareerTransitionContractBuilder::TIME_HORIZON_DAYS_61_90,
            ],
        );
    }

    public function test_it_returns_conservative_not_found_when_subject_meta_bundle_is_unavailable(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation']);

        $this->getJson('/api/v0.5/career/recommendations/mbti/intj')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    /**
     * @param  array<string, mixed>  $chain
     */
    private function compileRecommendationChain(array $chain): void
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-rec-api-'.$chain['occupation']->canonical_slug,
            'scope_mode' => 'first_wave_trust_inheritance',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);
        $compileRun = CareerCompileRun::query()->create([
            'import_run_id' => $importRun->id,
            'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'scope_mode' => 'first_wave_trust_inheritance',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(7),
        ]);
        $chain['contextSnapshot']->update([
            'compile_run_id' => $compileRun->id,
            'context_payload' => ['materialization' => 'career_first_wave'],
        ]);
        $chain['childProjection']->update([
            'compile_run_id' => $compileRun->id,
            'projection_payload' => array_merge(
                is_array($chain['childProjection']->projection_payload) ? $chain['childProjection']->projection_payload : [],
                [
                    'materialization' => 'career_first_wave',
                    'recommendation_subject_meta' => [
                        'type_code' => 'INTJ-A',
                        'canonical_type_code' => 'INTJ',
                        'display_title' => 'INTJ-A Career Match',
                    ],
                ],
            ),
        ]);

        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);
    }

    private function compileRecommendationChainBySlug(string $slug): RecommendationSnapshot
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-recommendation-transition-'.$slug,
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
            'context_payload' => ['materialization' => 'career_first_wave'],
        ]);
        $chain['childProjection']->update([
            'compile_run_id' => $compileRun->id,
            'projection_payload' => array_merge(
                is_array($chain['childProjection']->projection_payload) ? $chain['childProjection']->projection_payload : [],
                [
                    'materialization' => 'career_first_wave',
                    'recommendation_subject_meta' => [
                        'type_code' => 'INTJ-A',
                        'canonical_type_code' => 'INTJ',
                        'display_title' => 'INTJ-A Career Match',
                        'public_route_slug' => 'intj',
                    ],
                ],
            ),
        ]);

        return app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);
    }

    private function seedTargetOccupation(string $slug, string $title): Occupation
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);
        $chain['occupation']->update([
            'canonical_title_en' => $title,
            'canonical_title_zh' => $title,
        ]);

        return $chain['occupation']->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $occupations
     */
    private function mockReadinessSummary(array $occupations): void
    {
        $lookup = Mockery::mock(CareerTransitionPreviewReadinessLookup::class);
        foreach ($occupations as $row) {
            $lookup->shouldReceive('bySlug')
                ->with((string) ($row['canonical_slug'] ?? ''))
                ->andReturn($row);
        }
        $lookup->shouldReceive('bySlug')->andReturn(null);

        $this->app->instance(CareerTransitionPreviewReadinessLookup::class, $lookup);
    }

    /**
     * @return array<string, mixed>
     */
    private function readinessRow(
        string $canonicalSlug,
        string $status,
        bool $indexEligible,
        string $indexState,
        string $reviewerStatus,
    ): array {
        return [
            'occupation_uuid' => 'uuid-'.$canonicalSlug,
            'canonical_slug' => $canonicalSlug,
            'canonical_title_en' => $canonicalSlug,
            'status' => $status,
            'blocker_type' => null,
            'remediation_class' => null,
            'authority_override_supplied' => false,
            'review_required' => false,
            'crosswalk_mode' => 'exact',
            'reviewer_status' => $reviewerStatus,
            'index_state' => $indexState,
            'index_eligible' => $indexEligible,
            'reason_codes' => [$status],
        ];
    }
}
