<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career\Transition;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use App\Services\Career\Transition\CareerTransitionPathWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerTransitionPathWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_materializes_a_minimal_stable_upside_path_for_a_publish_ready_first_wave_snapshot(): void
    {
        $snapshot = $this->compiledFirstWaveSnapshot();
        $this->mockReadinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved');

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);

        $this->assertSame(1, $written);
        $path = TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->sole();
        $this->assertSame($snapshot->occupation_id, $path->from_occupation_id);
        $this->assertSame($snapshot->occupation_id, $path->to_occupation_id);
        $this->assertSame('stable_upside', $path->path_type);
        $this->assertSame([], $path->normalizedPathPayload()->toArray());
    }

    public function test_it_rewrites_stale_rows_for_the_same_snapshot_without_duplicates(): void
    {
        $snapshot = $this->compiledFirstWaveSnapshot();
        $this->mockReadinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved');

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $snapshot->occupation_id,
            'path_type' => 'bridge_path',
            'path_payload' => ['steps' => ['invalid legacy path']],
        ]);

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);

        $this->assertSame(1, $written);
        $this->assertSame(1, TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->count());
        $path = TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->sole();
        $this->assertSame('stable_upside', $path->path_type);
        $this->assertSame([], $path->normalizedPathPayload()->toArray());
    }

    public function test_it_rejects_snapshots_without_transition_claim_permission(): void
    {
        $snapshot = $this->compiledFirstWaveSnapshot();
        $payload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $payload['claim_permissions']['allow_transition_recommendation'] = false;
        $snapshot->forceFill(['snapshot_payload' => $payload])->save();
        $this->mockReadinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved');

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);

        $this->assertSame(0, $written);
        $this->assertSame(0, TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->count());
    }

    public function test_it_rejects_non_publish_ready_targets(): void
    {
        $snapshot = $this->compiledFirstWaveSnapshot();
        $this->mockReadinessRow($snapshot->occupation?->canonical_slug ?? '', 'partial_raw', false, 'noindex', 'pending');

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);

        $this->assertSame(0, $written);
        $this->assertSame(0, TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->count());
    }

    public function test_it_rejects_blocked_targets_including_override_eligible_and_not_safely_remediable(): void
    {
        $overrideSnapshot = $this->compiledFirstWaveSnapshot(['slug' => 'software-developers']);
        $this->mockReadinessRow($overrideSnapshot->occupation?->canonical_slug ?? '', 'blocked_override_eligible', false, 'noindex', 'approved');

        $overrideWritten = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($overrideSnapshot);

        $this->assertSame(0, $overrideWritten);
        $this->assertSame(0, TransitionPath::query()->where('recommendation_snapshot_id', $overrideSnapshot->id)->count());

        $blockedSnapshot = $this->compiledFirstWaveSnapshot(['slug' => 'marketing-managers']);
        $this->mockReadinessRow($blockedSnapshot->occupation?->canonical_slug ?? '', 'blocked_not_safely_remediable', false, 'noindex', 'pending');

        $blockedWritten = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($blockedSnapshot);

        $this->assertSame(0, $blockedWritten);
        $this->assertSame(0, TransitionPath::query()->where('recommendation_snapshot_id', $blockedSnapshot->id)->count());
    }

    /**
     * @param  array<string, mixed>  $scenarioOverrides
     */
    private function compiledFirstWaveSnapshot(array $scenarioOverrides = []): RecommendationSnapshot
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain($scenarioOverrides);

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'career_first_wave_fixture',
            'dataset_version' => 'fixture.v1',
            'dataset_checksum' => 'fixture-checksum',
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

    private function mockReadinessRow(
        string $canonicalSlug,
        string $status,
        bool $indexEligible,
        string $indexState,
        string $reviewerStatus,
    ): void {
        $lookup = Mockery::mock(CareerTransitionPreviewReadinessLookup::class);
        $lookup->shouldReceive('bySlug')
            ->with($canonicalSlug)
            ->andReturn([
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
            ]);
        $lookup->shouldReceive('bySlug')->andReturn(null);

        $this->app->instance(CareerTransitionPreviewReadinessLookup::class, $lookup);
    }
}
