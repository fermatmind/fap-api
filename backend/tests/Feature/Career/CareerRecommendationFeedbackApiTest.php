<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerFeedbackRecord;
use App\Models\CareerImportRun;
use App\Models\ContextSnapshot;
use App\Models\ProfileProjection;
use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationFeedbackApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_public_feedback_without_materializing_new_snapshots(): void
    {
        $snapshot = $this->compileRecommendationChainBySlug('feedback-api-case');
        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $snapshot->occupation_id,
            'path_type' => 'bridge',
            'path_payload' => [
                'steps' => [],
            ],
        ]);
        $beforeSnapshotCount = RecommendationSnapshot::query()->count();
        $beforeProjectionCount = ProfileProjection::query()->count();
        $beforeContextCount = ContextSnapshot::query()->count();
        $beforePathCount = TransitionPath::query()->count();

        $response = $this->postJson('/api/v0.5/career/recommendations/mbti/intj/feedback', [
            'burnout_checkin' => 5,
            'career_satisfaction' => 2,
            'switch_urgency' => 4,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.feedback_checkin.burnout_checkin', 5)
            ->assertJsonPath('data.feedback_checkin.career_satisfaction', 2)
            ->assertJsonPath('data.feedback_checkin.switch_urgency', 4)
            ->assertJsonPath('data.projection_delta_summary.delta_available', false)
            ->assertJsonPath('data.projection_delta_summary.transition_changed', false);

        $this->assertSame($beforeSnapshotCount, RecommendationSnapshot::query()->count());
        $this->assertSame($beforeProjectionCount, ProfileProjection::query()->count());
        $this->assertSame($beforeContextCount, ContextSnapshot::query()->count());
        $this->assertSame($beforePathCount, TransitionPath::query()->count());
        $this->assertSame(1, CareerFeedbackRecord::query()->count());
        $this->assertNotNull(RecommendationSnapshot::query()->find($snapshot->id));

        $entries = (array) data_get($response->json(), 'data.projection_timeline.entries', []);
        $this->assertGreaterThanOrEqual(1, count($entries));
    }

    public function test_it_returns_not_found_when_feedback_target_type_is_unavailable(): void
    {
        $this->postJson('/api/v0.5/career/recommendations/mbti/intp/feedback', [
            'burnout_checkin' => 3,
            'career_satisfaction' => 3,
            'switch_urgency' => 3,
        ])->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_it_rejects_empty_malformed_or_internal_feedback_payloads_without_writes(): void
    {
        $this->compileRecommendationChainBySlug('feedback-validation-case');
        $beforeSnapshotCount = RecommendationSnapshot::query()->count();

        $this->postJson('/api/v0.5/career/recommendations/mbti/intj/feedback', [])
            ->assertStatus(422);

        $this->postJson('/api/v0.5/career/recommendations/mbti/intj/feedback', [
            'burnout_checkin' => 4,
            'snapshot_payload' => ['governance' => 'poison'],
        ])->assertStatus(422);

        $this->postJson('/api/v0.5/career/recommendations/mbti/intj/feedback', [
            'burnout_checkin' => str_repeat('5', 200),
        ])->assertStatus(422);

        $this->postJson('/api/v0.5/career/recommendations/mbti/not-a-type/feedback', [
            'burnout_checkin' => 4,
        ])->assertStatus(422);

        $this->assertSame($beforeSnapshotCount, RecommendationSnapshot::query()->count());
        $this->assertSame(0, CareerFeedbackRecord::query()->count());
    }

    private function compileRecommendationChainBySlug(string $slug): RecommendationSnapshot
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-recommendation-feedback-'.$slug,
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
