<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Services\Career\Bundles\CareerRecommendationDetailBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationDetailBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_recommendation_bundle_from_compiled_snapshot_and_subject_meta(): void
    {
        $this->compileRecommendationChain(CareerFoundationFixture::seedHighTrustCompleteChain());

        $bundle = app(CareerRecommendationDetailBundleBuilder::class)->buildByType('intj');

        $this->assertNotNull($bundle);
        $payload = $bundle?->toArray() ?? [];

        $this->assertSame('career.protocol.recommendation_detail.v1', $payload['bundle_version']);
        $this->assertSame('INTJ-A', data_get($payload, 'recommendation_subject_meta.type_code'));
        $this->assertArrayHasKey('fit_score', (array) data_get($payload, 'score_bundle'));
        $this->assertArrayHasKey('allow_strong_claim', (array) data_get($payload, 'claim_permissions'));
        $this->assertSame('career_recommendation_detail_bundle', data_get($payload, 'seo_contract.surface_type'));
        $this->assertSame('/career/recommendations/mbti/intj', data_get($payload, 'seo_contract.canonical_path'));
        $this->assertJsonStringEqualsJsonString(
            json_encode([
                'occupation_uuid',
                'canonical_slug',
                'title',
                'seo_contract',
                'trust_summary',
            ], JSON_THROW_ON_ERROR),
            json_encode(array_keys((array) data_get($payload, 'matched_jobs.0')), JSON_THROW_ON_ERROR),
        );
        $this->assertSame('backend-architect', data_get($payload, 'matched_jobs.0.canonical_slug'));
        $this->assertSame(true, data_get($payload, 'matched_jobs.0.seo_contract.index_eligible'));
        $this->assertSame('approved', data_get($payload, 'matched_jobs.0.trust_summary.reviewer_status'));
        $this->assertNull(data_get($payload, 'matched_jobs.0.summary'));
        $this->assertNull(data_get($payload, 'matched_jobs.0.fit_bucket'));
        $this->assertArrayHasKey('compile_refs', (array) data_get($payload, 'provenance_meta'));
    }

    public function test_it_returns_null_without_explicit_subject_meta(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation']);

        $bundle = app(CareerRecommendationDetailBundleBuilder::class)->buildByType('INTJ');

        $this->assertNull($bundle);
    }

    public function test_it_prefers_first_wave_materialized_subject_snapshots_over_later_non_public_compiles(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-rec-wave']);
        $chain['childProjection']->update([
            'projection_payload' => array_merge(
                is_array($chain['childProjection']->projection_payload) ? $chain['childProjection']->projection_payload : [],
                [
                    'recommendation_subject_meta' => [
                        'type_code' => 'INTJ-A',
                        'canonical_type_code' => 'INTJ',
                    ],
                    'materialization' => 'career_first_wave',
                ],
            ),
        ]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-rec-wave',
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
                ['materialization' => 'career_first_wave']
            ),
        ]);

        app(CareerRecommendationCompiler::class)->compile(
            $chain['childProjection'],
            $chain['occupation'],
            [
                'compile_run_id' => $compileRun->id,
                'trust_manifest_id' => $chain['trustManifest']->id,
                'index_state_id' => $chain['indexState']->id,
                'truth_metric_id' => $chain['truthMetric']->id,
                'import_run_id' => $importRun->id,
            ],
        );

        $personalContext = $chain['contextSnapshot']->replicate();
        $personalContext->compile_run_id = null;
        $personalContext->captured_at = now()->subMinute();
        $personalContext->context_payload = ['materialization' => 'user_personalized'];
        $personalContext->save();

        $personalProjection = $chain['childProjection']->replicate();
        $personalProjection->compile_run_id = null;
        $personalProjection->context_snapshot_id = $personalContext->id;
        $personalProjection->projection_payload = [
            'materialization' => 'user_personalized',
            'recommendation_subject_meta' => [
                'type_code' => 'INTJ-A',
                'canonical_type_code' => 'INTJ',
            ],
        ];
        $personalProjection->save();

        app(CareerRecommendationCompiler::class)->compile($personalProjection, $chain['occupation']);

        $bundle = app(CareerRecommendationDetailBundleBuilder::class)->buildByType('intj');

        $this->assertNotNull($bundle);
        $this->assertSame(
            $compileRun->id,
            data_get($bundle?->toArray(), 'provenance_meta.compile_run_id')
        );
    }

    public function test_it_builds_authority_owned_matched_jobs_with_compact_readiness_only(): void
    {
        $this->compileRecommendationChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-intj-match'])
        );
        $this->compileRecommendationChain(
            CareerFoundationFixture::seedTrustLimitedCrossMarketChain()
        );

        $payload = app(CareerRecommendationDetailBundleBuilder::class)->buildByType('intj')?->toArray() ?? [];

        $this->assertCount(2, (array) data_get($payload, 'matched_jobs'));
        $this->assertSame(
            ['backend-architect-cn-market', 'backend-architect-intj-match'],
            array_map(
                static fn (array $job): string => (string) ($job['canonical_slug'] ?? ''),
                (array) data_get($payload, 'matched_jobs')
            )
        );
        $this->assertSame(false, data_get($payload, 'matched_jobs.0.seo_contract.index_eligible'));
        $this->assertSame('trust_limited', data_get($payload, 'matched_jobs.0.seo_contract.index_state'));
        $this->assertSame(['fixture'], data_get($payload, 'matched_jobs.0.seo_contract.reason_codes'));
        $this->assertSame('pending', data_get($payload, 'matched_jobs.0.trust_summary.reviewer_status'));
        $this->assertSame(true, data_get($payload, 'matched_jobs.1.seo_contract.index_eligible'));
        $this->assertNull(data_get($payload, 'matched_jobs.0.score_bundle'));
        $this->assertNull(data_get($payload, 'matched_jobs.0.claim_permissions'));
    }

    /**
     * @param  array<string, mixed>  $chain
     */
    private function compileRecommendationChain(array $chain): void
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-build-rec-'.$chain['occupation']->canonical_slug,
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
                    ],
                ],
            ),
        ]);

        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);
    }
}
