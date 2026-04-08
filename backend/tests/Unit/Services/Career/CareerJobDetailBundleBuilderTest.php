<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerJobDetailBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_explicit_job_detail_bundle_from_authority_rows(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-build-job',
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
        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);

        $bundle = app(CareerJobDetailBundleBuilder::class)->buildBySlug('backend-architect');

        $this->assertNotNull($bundle);
        $payload = $bundle?->toArray() ?? [];

        $this->assertSame('career.protocol.job_detail.v1', $payload['bundle_version']);
        $this->assertSame($chain['occupation']->id, data_get($payload, 'identity.occupation_uuid'));
        $this->assertSame($chain['occupation']->canonical_title_en, data_get($payload, 'titles.canonical_en'));
        $this->assertSame($chain['trustManifest']->content_version, data_get($payload, 'trust_manifest.content_version'));
        $this->assertArrayHasKey('fit_score', (array) data_get($payload, 'score_bundle'));
        $this->assertArrayHasKey('allow_strong_claim', (array) data_get($payload, 'claim_permissions'));
        $this->assertArrayHasKey('metadata_contract_version', (array) data_get($payload, 'seo_contract'));
        $this->assertArrayHasKey('compiler_version', (array) data_get($payload, 'provenance_meta'));
    }

    public function test_it_returns_null_when_only_mutable_occupation_exists_without_compiled_authority_snapshot(): void
    {
        CareerFoundationFixture::seedHighTrustCompleteChain();

        $bundle = app(CareerJobDetailBundleBuilder::class)->buildBySlug('backend-architect');

        $this->assertNull($bundle);
    }

    public function test_it_prefers_first_wave_materialized_compile_snapshots_over_later_non_public_compiles(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-wave']);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-wave',
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
        $personalProjection->projection_payload = ['materialization' => 'user_personalized'];
        $personalProjection->save();

        app(CareerRecommendationCompiler::class)->compile($personalProjection, $chain['occupation']);

        $bundle = app(CareerJobDetailBundleBuilder::class)->buildBySlug('backend-architect-wave');

        $this->assertNotNull($bundle);
        $this->assertSame(
            $compileRun->id,
            data_get($bundle?->toArray(), 'provenance_meta.compile_run_id')
        );
    }
}
