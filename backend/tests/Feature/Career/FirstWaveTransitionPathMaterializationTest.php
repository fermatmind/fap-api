<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Models\ProfileProjection;
use App\Models\TransitionPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FirstWaveTransitionPathMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_materializes_publish_ready_transition_paths_from_the_first_wave_pipeline(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_publish_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertGreaterThan(0, TransitionPath::query()->count());
        $this->assertSame(
            TransitionPath::query()->count(),
            TransitionPath::query()->distinct('recommendation_snapshot_id')->count('recommendation_snapshot_id')
        );

        $path = TransitionPath::query()->with(['recommendationSnapshot', 'fromOccupation', 'toOccupation'])->latest('created_at')->firstOrFail();
        $this->assertSame('stable_upside', $path->path_type);
        $this->assertSame($path->from_occupation_id, $path->to_occupation_id);
        $this->assertSame([
            'steps' => TransitionPathPayload::allowedStepLabels(),
        ], $path->normalizedPathPayload()->toArray());
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
            ->orderBy('recommendation_snapshot_id')
            ->get()
            ->map(static fn (TransitionPath $path): array => [
                'snapshot_id' => $path->recommendation_snapshot_id,
                'path_type' => $path->path_type,
                'from' => $path->from_occupation_id,
                'to' => $path->to_occupation_id,
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
        $this->assertSame(
            array_fill(0, TransitionPath::query()->count(), [
                'steps' => TransitionPathPayload::allowedStepLabels(),
            ]),
            TransitionPath::query()
                ->orderBy('recommendation_snapshot_id')
                ->get()
                ->map(static fn (TransitionPath $path): array => $path->normalizedPathPayload()->toArray())
                ->all()
        );
        $this->assertNotSame($firstFingerprints, TransitionPath::query()
            ->orderBy('recommendation_snapshot_id')
            ->get()
            ->map(static fn (TransitionPath $path): array => [
                'snapshot_id' => $path->recommendation_snapshot_id,
                'path_type' => $path->path_type,
                'from' => $path->from_occupation_id,
                'to' => $path->to_occupation_id,
            ])
            ->all());
    }

    public function test_preview_api_can_read_production_written_transition_rows_without_expanding_public_contract(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_publish_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $path = TransitionPath::query()->with(['recommendationSnapshot.profileProjection', 'toOccupation'])->latest('created_at')->firstOrFail();
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

        $this->getJson('/api/v0.5/career/transition-preview?type=intj')
            ->assertOk()
            ->assertJsonPath('path_type', 'stable_upside')
            ->assertJsonPath('target_job.canonical_slug', $path->toOccupation?->canonical_slug)
            ->assertJsonMissingPath('steps')
            ->assertJsonMissingPath('why_this_path')
            ->assertJsonMissingPath('what_is_lost')
            ->assertJsonMissingPath('bridge_steps_90d');
    }
}
