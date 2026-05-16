<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\Bundles\CareerJobListBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobListBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture,
        );
    }

    public function test_it_returns_only_first_wave_indexable_jobs_by_default(): void
    {
        $indexable = $this->compileJobChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-index'])
        );
        $this->compileJobChain(
            CareerFoundationFixture::seedMissingTruthChain()
        );

        $items = app(CareerJobListBundleBuilder::class)->build();

        $this->assertCount(1, $items);
        $payload = $items[0]->toArray();

        $this->assertSame('backend-architect-index', data_get($payload, 'identity.canonical_slug'));
        $this->assertTrue((bool) data_get($payload, 'seo_contract.index_eligible'));
        $this->assertSame(
            $indexable['compileRun']->id,
            data_get($payload, 'provenance_meta.compile_run_id')
        );
    }

    public function test_it_can_include_non_indexable_jobs_when_explicitly_requested(): void
    {
        $this->compileJobChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-visible']),
            now()->subMinutes(5)
        );
        $this->compileJobChain(
            CareerFoundationFixture::seedTrustLimitedCrossMarketChain(),
            now()->subMinute()
        );

        $items = app(CareerJobListBundleBuilder::class)->build(includeNonIndexable: true);
        $payloads = array_map(static fn ($item): array => $item->toArray(), $items);

        $this->assertCount(1, $payloads);
        $this->assertSame('backend-architect-cn-market', data_get($payloads[0], 'identity.canonical_slug'));
        $this->assertFalse((bool) data_get($payloads[0], 'seo_contract.index_eligible'));
    }

    public function test_it_does_not_resurrect_older_indexable_snapshot_when_current_run_withdraws_job(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-stable']);
        $this->compileJobChain($chain, now()->subMinutes(5));

        $chain['occupation']->indexStates()->create([
            'index_state' => 'trust_limited',
            'index_eligible' => false,
            'canonical_path' => '/career/jobs/backend-architect-stable',
            'canonical_target' => null,
            'reason_codes' => ['trust_limited'],
            'changed_at' => now()->subSeconds(30),
        ]);

        $this->compileJobChain($chain, now()->subMinute());

        $items = app(CareerJobListBundleBuilder::class)->build();

        $this->assertSame([], $items);
    }

    public function test_it_excludes_dataset_rows_when_runtime_projection_marks_them_not_visible(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(datasetVisible: [
                'backend-architect-hidden' => false,
            ]),
        );

        $this->compileJobChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-hidden'])
        );

        $items = app(CareerJobListBundleBuilder::class)->build(includeNonIndexable: true);

        $this->assertSame([], $items);
    }

    public function test_it_includes_runtime_projection_detail_jobs_missing_from_legacy_compile_sources(): void
    {
        $occupation = $this->createRuntimeProjectionOccupation('runtime-public-specialist');
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(items: [
                'runtime-public-specialist' => [
                    'slug' => 'runtime-public-specialist',
                    'canonical_path' => '/career/jobs/runtime-public-specialist',
                    'dataset_visible' => true,
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                    'reason_codes' => ['validated_display_asset_backed_release'],
                ],
            ]),
        );

        $items = app(CareerJobListBundleBuilder::class)->build();
        $payloads = array_map(static fn ($item): array => $item->toArray(), $items);

        $this->assertCount(1, $payloads);
        $this->assertSame($occupation->canonical_slug, data_get($payloads[0], 'identity.canonical_slug'));
        $this->assertSame('Runtime Public Specialist', data_get($payloads[0], 'titles.canonical_en'));
        $this->assertSame('运行时公开专家', data_get($payloads[0], 'titles.canonical_zh'));
        $this->assertSame('indexable', data_get($payloads[0], 'seo_contract.index_state'));
        $this->assertTrue((bool) data_get($payloads[0], 'seo_contract.index_eligible'));
        $this->assertContains('runtime_publish_projection', data_get($payloads[0], 'seo_contract.reason_codes'));
    }

    public function test_it_can_include_current_non_indexable_job_when_explicitly_requested(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-stable']);
        $older = $this->compileJobChain($chain, now()->subMinutes(5));

        $chain['occupation']->indexStates()->create([
            'index_state' => 'trust_limited',
            'index_eligible' => false,
            'canonical_path' => '/career/jobs/backend-architect-stable',
            'canonical_target' => null,
            'reason_codes' => ['trust_limited'],
            'changed_at' => now()->subSeconds(30),
        ]);

        $current = $this->compileJobChain($chain, now()->subMinute());

        $items = app(CareerJobListBundleBuilder::class)->build(includeNonIndexable: true);

        $this->assertCount(1, $items);
        $payload = $items[0]->toArray();

        $this->assertSame('backend-architect-stable', data_get($payload, 'identity.canonical_slug'));
        $this->assertFalse((bool) data_get($payload, 'seo_contract.index_eligible'));
        $this->assertSame($current['compileRun']->id, data_get($payload, 'provenance_meta.compile_run_id'));
        $this->assertNotSame($older['compileRun']->id, data_get($payload, 'provenance_meta.compile_run_id'));
    }

    public function test_it_ignores_newer_recommendation_subject_compile_runs(): void
    {
        $jobRun = $this->compileJobChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-public-index']),
            now()->subMinutes(10)
        );
        $recommendationRun = $this->compileJobChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-recommendation-shadow']),
            now()->subMinute(),
            [
                'type_code' => 'INTJ-A',
                'canonical_type_code' => 'INTJ',
                'display_title' => 'INTJ-A Career Match',
                'public_route_slug' => 'intj',
            ]
        );

        $items = app(CareerJobListBundleBuilder::class)->build(includeNonIndexable: true);
        $payloads = array_map(static fn ($item): array => $item->toArray(), $items);

        $this->assertCount(1, $payloads);
        $this->assertSame('backend-architect-public-index', data_get($payloads[0], 'identity.canonical_slug'));
        $this->assertSame($jobRun['compileRun']->id, data_get($payloads[0], 'provenance_meta.compile_run_id'));
        $this->assertNotSame($recommendationRun['compileRun']->id, data_get($payloads[0], 'provenance_meta.compile_run_id'));
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>|null  $subjectMeta
     * @return array<string, mixed>
     */
    private function compileJobChain(array $chain, ?\Illuminate\Support\Carbon $compiledAt = null, ?array $subjectMeta = null): array
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-job-list-'.$chain['occupation']->canonical_slug,
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
            'started_at' => ($compiledAt ?? now())->copy()->subMinute(),
            'finished_at' => $compiledAt ?? now(),
        ]);

        $chain['contextSnapshot']->update([
            'compile_run_id' => $compileRun->id,
            'context_payload' => ['materialization' => 'career_first_wave'],
        ]);
        $chain['childProjection']->update([
            'compile_run_id' => $compileRun->id,
            'projection_payload' => array_merge(
                is_array($chain['childProjection']->projection_payload) ? $chain['childProjection']->projection_payload : [],
                array_filter([
                    'materialization' => 'career_first_wave',
                    'recommendation_subject_meta' => $subjectMeta,
                ], static fn (mixed $value): bool => $value !== null)
            ),
        ]);

        $snapshot = app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);

        if ($compiledAt !== null) {
            $snapshot->forceFill(['compiled_at' => $compiledAt])->save();
        }

        return [
            'importRun' => $importRun,
            'compileRun' => $compileRun,
            'snapshot' => $snapshot,
        ] + $chain;
    }

    private function createRuntimeProjectionOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'runtime-public-family',
            'title_en' => 'Runtime Public Family',
            'title_zh' => '运行时公开职业族',
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'occupation',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => 'Runtime Public Specialist',
            'canonical_title_zh' => '运行时公开专家',
            'search_h1_zh' => '运行时公开专家',
        ]);
    }
}
