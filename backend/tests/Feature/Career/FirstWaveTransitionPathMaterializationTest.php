<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\ProfileProjection;
use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use App\Services\Career\Transition\CareerTransitionPathWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class FirstWaveTransitionPathMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_materializes_publish_ready_transition_paths_from_the_first_wave_pipeline(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-materialization-source');
        $target = $this->createSameFamilyTarget($snapshot, 'transition-materialization-target', 'Transition Materialization Target');
        $this->mockReadinessSummary([
            $this->readinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved'),
            $this->readinessRow($target->canonical_slug, 'publish_ready', true, 'indexable', 'approved'),
        ]);

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);

        $this->assertSame(1, $written);
        $this->assertSame(1, TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->count());
        $path = TransitionPath::query()
            ->with(['recommendationSnapshot', 'fromOccupation', 'toOccupation'])
            ->where('recommendation_snapshot_id', $snapshot->id)
            ->sole();
        $this->assertSame('stable_upside', $path->path_type);
        $this->assertNotSame($path->from_occupation_id, $path->to_occupation_id);
        $this->assertSame($path->fromOccupation?->family_id, $path->toOccupation?->family_id);
        $this->assertSame($target->canonical_slug, $path->toOccupation?->canonical_slug);
        $this->assertSame(TransitionPathPayload::allowedStepLabels(), $path->normalizedPathPayload()->steps);
        $this->assertContains('same_family_target', $path->normalizedPathPayload()->rationaleCodes);
        $this->assertArrayNotHasKey('why_this_path', $path->normalizedPathPayload()->toArray());
        $this->assertArrayNotHasKey('bridge_steps_90d', $path->normalizedPathPayload()->toArray());
        $this->assertNotNull($path->recommendationSnapshot?->compile_run_id);
    }

    public function test_it_remains_rerun_safe_without_duplicate_or_stale_transition_rows(): void
    {
        $command = [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_publish_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--json' => true,
        ];

        $firstExitCode = Artisan::call('career:validate-first-wave-publish-ready', $command);
        $firstCount = TransitionPath::query()->count();
        $firstFingerprints = TransitionPath::query()
            ->with(['fromOccupation', 'toOccupation'])
            ->orderBy('recommendation_snapshot_id')
            ->get()
            ->map(static fn (TransitionPath $path): array => [
                'from_slug' => $path->fromOccupation?->canonical_slug,
                'to_slug' => $path->toOccupation?->canonical_slug,
                'path_type' => $path->path_type,
                'same_family' => $path->fromOccupation?->family_id === $path->toOccupation?->family_id,
            ])
            ->all();

        $secondExitCode = Artisan::call('career:validate-first-wave-publish-ready', $command);

        $this->assertSame(0, $firstExitCode);
        $this->assertSame(0, $secondExitCode);
        $this->assertSame($firstCount, TransitionPath::query()->count());
        $this->assertSame(
            TransitionPath::query()->count(),
            TransitionPath::query()->distinct('recommendation_snapshot_id')->count('recommendation_snapshot_id')
        );
        TransitionPath::query()
            ->orderBy('recommendation_snapshot_id')
            ->get()
            ->each(function (TransitionPath $path): void {
                $payload = $path->normalizedPathPayload()->toArray();
                $this->assertSame(TransitionPathPayload::allowedStepLabels(), $path->normalizedPathPayload()->steps);
                $this->assertContains('same_family_target', $path->normalizedPathPayload()->rationaleCodes);
                $this->assertArrayNotHasKey('why_this_path', $payload);
                $this->assertArrayNotHasKey('what_is_lost', $payload);
                $this->assertArrayNotHasKey('bridge_steps_90d', $payload);
            });
        $this->assertSame(
            $firstFingerprints,
            TransitionPath::query()
                ->with(['fromOccupation', 'toOccupation'])
                ->orderBy('recommendation_snapshot_id')
                ->get()
                ->map(static fn (TransitionPath $path): array => [
                    'from_slug' => $path->fromOccupation?->canonical_slug,
                    'to_slug' => $path->toOccupation?->canonical_slug,
                    'path_type' => $path->path_type,
                    'same_family' => $path->fromOccupation?->family_id === $path->toOccupation?->family_id,
                ])
                ->all()
        );
    }

    public function test_preview_api_can_read_production_written_transition_rows_with_expanded_public_contract(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-preview-source');
        $target = $this->createSameFamilyTarget($snapshot, 'registered-nurses', 'Registered Nurses');
        $this->mockReadinessSummary([
            $this->readinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved'),
            $this->readinessRow($target->canonical_slug, 'publish_ready', true, 'indexable', 'approved'),
        ]);

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);
        $this->assertSame(1, $written);

        $path = TransitionPath::query()
            ->with(['recommendationSnapshot.profileProjection', 'toOccupation'])
            ->where('recommendation_snapshot_id', $snapshot->id)
            ->sole();
        $projection = $path->recommendationSnapshot?->profileProjection;
        $this->assertInstanceOf(ProfileProjection::class, $projection);

        $projectionPayload = is_array($projection->projection_payload) ? $projection->projection_payload : [];
        $projection->forceFill([
            'projection_payload' => array_merge($projectionPayload, [
                'recommendation_subject_meta' => [
                    'type_code' => 'INTJ-A',
                    'canonical_type_code' => 'INTJ',
                    'display_title' => 'INTJ-A Career Match',
                    'public_route_slug' => 'intj',
                ],
            ]),
        ])->save();

        $response = $this->getJson('/api/v0.5/career/transition-preview?type=intj')
            ->assertOk()
            ->assertJsonPath('path_type', 'stable_upside')
            ->assertJsonPath('steps.0', TransitionPathPayload::STEP_SKILL_OVERLAP)
            ->assertJsonPath('steps.1', TransitionPathPayload::STEP_TASK_OVERLAP)
            ->assertJsonPath('steps.2', TransitionPathPayload::STEP_TOOL_OVERLAP)
            ->assertJsonPath('target_job.canonical_slug', $path->toOccupation?->canonical_slug)
            ->assertJsonPath('bridge_steps_90d.0.step_key', TransitionPathPayload::STEP_SKILL_OVERLAP)
            ->assertJsonPath('bridge_steps_90d.0.time_horizon', \App\Services\Career\Transition\CareerTransitionContractBuilder::TIME_HORIZON_DAYS_0_30);

        /** @var array<string, mixed> $payload */
        $payload = $response->json();
        $this->assertArrayHasKey('why_this_path', $payload);
        $this->assertIsString($payload['why_this_path']);
        $this->assertNotSame('', trim((string) $payload['why_this_path']));
        if (array_key_exists('tradeoff_codes', $payload)) {
            $this->assertArrayHasKey('what_is_lost', $payload);
            $this->assertIsString($payload['what_is_lost']);
            $this->assertNotSame('', trim((string) $payload['what_is_lost']));
        }

        $this->assertNotSame($path->from_occupation_id, $path->to_occupation_id);
    }

    private function compileRecommendationChain(string $slug): RecommendationSnapshot
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-transition-materialization-'.$slug,
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
                ['materialization' => 'career_first_wave'],
            ),
        ]);

        return app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);
    }

    private function createSameFamilyTarget(RecommendationSnapshot $snapshot, string $slug, string $title): Occupation
    {
        $source = $snapshot->occupation()->firstOrFail();

        return Occupation::query()->create([
            'family_id' => $source->family_id,
            'parent_id' => null,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => $source->truth_market,
            'display_market' => $source->display_market,
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => $title,
            'canonical_title_zh' => $title,
            'search_h1_zh' => $title,
            'structural_stability' => $source->structural_stability,
            'task_prototype_signature' => $source->task_prototype_signature,
            'market_semantics_gap' => $source->market_semantics_gap,
            'regulatory_divergence' => $source->regulatory_divergence,
            'toolchain_divergence' => $source->toolchain_divergence,
            'skill_gap_threshold' => $source->skill_gap_threshold,
            'trust_inheritance_scope' => $source->trust_inheritance_scope,
        ]);
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
