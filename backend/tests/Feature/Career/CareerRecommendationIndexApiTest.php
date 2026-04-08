<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationIndexApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_resource_backed_lightweight_recommendation_index(): void
    {
        $this->compileRecommendationChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-intj-index']),
            [
                'type_code' => 'INTJ-A',
                'canonical_type_code' => 'INTJ',
                'display_title' => 'INTJ-A Career Match',
                'public_route_slug' => 'intj',
            ]
        );
        $this->compileRecommendationChain(
            CareerFoundationFixture::seedTrustLimitedCrossMarketChain(),
            [
                'type_code' => 'ENFP-T',
                'canonical_type_code' => 'ENFP',
                'display_title' => 'ENFP-T Career Match',
                'public_route_slug' => 'enfp',
            ]
        );

        $this->getJson('/api/v0.5/career/recommendations/mbti')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_recommendation_index')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.recommendation_subject_meta.public_route_slug', 'intj')
            ->assertJsonPath('items.0.seo_contract.index_eligible', true)
            ->assertJsonStructure([
                'bundle_kind',
                'bundle_version',
                'items' => [[
                    'recommendation_subject_meta',
                    'score_summary',
                    'trust_summary',
                    'seo_contract' => ['canonical_path', 'index_state', 'index_eligible', 'reason_codes'],
                    'provenance_meta' => ['compiler_version', 'compile_run_id'],
                ]],
            ]);
    }

    public function test_it_returns_an_empty_index_when_no_public_safe_subjects_exist(): void
    {
        $this->compileRecommendationChain(
            CareerFoundationFixture::seedTrustLimitedCrossMarketChain(),
            [
                'type_code' => 'INTJ-A',
                'canonical_type_code' => 'INTJ',
                'display_title' => 'INTJ-A Career Match',
                'public_route_slug' => 'intj',
            ]
        );

        $this->getJson('/api/v0.5/career/recommendations/mbti')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_recommendation_index')
            ->assertJsonCount(0, 'items');
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $subjectMeta
     */
    private function compileRecommendationChain(array $chain, array $subjectMeta): void
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-rec-api-'.$chain['occupation']->canonical_slug,
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
                    'recommendation_subject_meta' => $subjectMeta,
                ]
            ),
        ]);

        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);
    }
}
