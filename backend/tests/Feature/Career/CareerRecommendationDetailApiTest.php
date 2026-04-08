<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationDetailApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_resource_backed_recommendation_detail_bundle_with_provenance_and_claims(): void
    {
        $chain = CareerFoundationFixture::seedTrustLimitedCrossMarketChain();
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-rec-api',
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

        $this->getJson('/api/v0.5/career/recommendations/mbti/intj')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_recommendation_detail')
            ->assertJsonPath('recommendation_subject_meta.type_code', 'INTJ-A')
            ->assertJsonPath('claim_permissions.allow_salary_comparison', false)
            ->assertJsonPath('seo_contract.canonical_path', '/career/recommendations/mbti/intj')
            ->assertJsonPath('seo_contract.index_eligible', false)
            ->assertJsonStructure([
                'identity',
                'recommendation_subject_meta',
                'supporting_truth_summary',
                'score_bundle' => ['fit_score'],
                'warnings',
                'claim_permissions',
                'integrity_summary',
                'trust_manifest',
                'seo_contract',
                'provenance_meta' => ['compiler_version', 'compile_refs'],
            ]);
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
}
