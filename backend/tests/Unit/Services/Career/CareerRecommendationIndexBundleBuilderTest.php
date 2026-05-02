<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Services\Career\Bundles\CareerRecommendationIndexBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationIndexBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_groups_recommendation_subjects_deterministically_by_public_route_slug(): void
    {
        $older = $this->compileRecommendationChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-intj-older']),
            [
                'type_code' => 'INTJ-A',
                'canonical_type_code' => 'INTJ',
                'display_title' => 'INTJ-A Career Match',
                'public_route_slug' => 'intj',
            ],
            now()->subMinutes(9)
        );
        $newer = $this->compileRecommendationChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-intj-newer']),
            [
                'type_code' => 'INTJ-A',
                'canonical_type_code' => 'INTJ',
                'display_title' => 'INTJ-A Career Match',
                'public_route_slug' => 'intj',
            ],
            now()->subMinutes(1)
        );

        $items = app(CareerRecommendationIndexBundleBuilder::class)->build(includeNonIndexable: true);

        $this->assertCount(1, $items);
        $payload = $items[0]->toArray();

        $this->assertSame('intj', data_get($payload, 'recommendation_subject_meta.public_route_slug'));
        $this->assertSame($newer['compileRun']->id, data_get($payload, 'provenance_meta.compile_run_id'));
        $this->assertNotSame($older['compileRun']->id, data_get($payload, 'provenance_meta.compile_run_id'));
    }

    public function test_it_excludes_non_indexable_recommendation_subjects_by_default(): void
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

        $items = app(CareerRecommendationIndexBundleBuilder::class)->build();

        $this->assertSame([], $items);
    }

    public function test_it_ignores_newer_job_list_compile_runs_without_recommendation_subjects(): void
    {
        $recommendationRun = $this->compileRecommendationChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-intj-index']),
            [
                'type_code' => 'INTJ-A',
                'canonical_type_code' => 'INTJ',
                'display_title' => 'INTJ-A Career Match',
                'public_route_slug' => 'intj',
            ],
            now()->subMinutes(10)
        );
        $jobListRun = $this->compileRecommendationChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-job-shadow']),
            null,
            now()->subMinute()
        );

        $items = app(CareerRecommendationIndexBundleBuilder::class)->build(includeNonIndexable: true);

        $this->assertCount(1, $items);
        $payload = $items[0]->toArray();

        $this->assertSame('intj', data_get($payload, 'recommendation_subject_meta.public_route_slug'));
        $this->assertSame($recommendationRun['compileRun']->id, data_get($payload, 'provenance_meta.compile_run_id'));
        $this->assertNotSame($jobListRun['compileRun']->id, data_get($payload, 'provenance_meta.compile_run_id'));
    }

    /**
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>|null  $subjectMeta
     * @return array<string, mixed>
     */
    private function compileRecommendationChain(array $chain, ?array $subjectMeta, ?\Illuminate\Support\Carbon $compiledAt = null): array
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-rec-index-'.$chain['occupation']->canonical_slug,
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
            'captured_at' => $compiledAt ?? now()->subMinutes(2),
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
}
