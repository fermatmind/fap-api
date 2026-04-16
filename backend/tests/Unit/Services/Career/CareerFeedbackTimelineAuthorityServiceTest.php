<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Feedback\CareerFeedbackTimelineAuthorityService;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerFeedbackTimelineAuthorityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_timeline_and_appends_feedback_refresh_without_mutating_previous_snapshot(): void
    {
        $snapshot = $this->compileRecommendationChainBySlug('feedback-timeline-unit');
        $beforeTransitionPathCount = TransitionPath::query()->count();
        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $snapshot->occupation_id,
            'path_type' => 'bridge',
            'path_payload' => [
                'steps' => [],
            ],
        ]);
        $service = app(CareerFeedbackTimelineAuthorityService::class);

        $before = $service->buildForRecommendationSnapshot($snapshot);
        $this->assertSame('career_projection_timeline', data_get($before, 'projection_timeline.timeline_kind'));
        $this->assertGreaterThanOrEqual(1, count((array) data_get($before, 'projection_timeline.entries', [])));

        $beforeSnapshotCount = RecommendationSnapshot::query()->count();
        $afterSnapshot = $service->appendFeedbackRefresh($snapshot, [
            'subject_slug' => 'intj',
            'burnout_checkin' => 4,
            'career_satisfaction' => 2,
            'switch_urgency' => 5,
        ]);

        $this->assertNotSame($snapshot->id, $afterSnapshot->id);
        $this->assertSame($beforeSnapshotCount + 1, RecommendationSnapshot::query()->count());
        $this->assertSame($snapshot->id, RecommendationSnapshot::query()->find($snapshot->id)?->id);

        $after = $service->buildForRecommendationSnapshot($afterSnapshot);
        $this->assertSame(4, data_get($after, 'feedback_checkin.burnout_checkin'));
        $this->assertSame(2, data_get($after, 'feedback_checkin.career_satisfaction'));
        $this->assertSame(5, data_get($after, 'feedback_checkin.switch_urgency'));
        $this->assertSame(true, data_get($after, 'projection_delta_summary.delta_available'));
        $this->assertSame(false, data_get($after, 'projection_delta_summary.transition_changed'));
        $this->assertGreaterThanOrEqual(2, count((array) data_get($after, 'projection_timeline.entries', [])));
        $this->assertSame($beforeTransitionPathCount + 2, TransitionPath::query()->count());
    }

    private function compileRecommendationChainBySlug(string $slug): RecommendationSnapshot
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-feedback-timeline-'.$slug,
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
}
