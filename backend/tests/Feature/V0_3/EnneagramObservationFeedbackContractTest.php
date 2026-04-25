<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsEnneagramAttempts;
use Tests\TestCase;

final class EnneagramObservationFeedbackContractTest extends TestCase
{
    use BuildsEnneagramAttempts;
    use RefreshDatabase;

    public function test_day3_and_day7_feedback_persist_without_overriding_primary_candidate(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_observation_feedback';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($anonId, $token);
        $this->patchEnneagramProjection($attemptId, 'enneagram_likert_105', 'clear', [
            'top_types' => [
                ['type_code' => 'T1'],
                ['type_code' => 'T2'],
                ['type_code' => 'T3'],
            ],
        ]);

        $day3 = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->postJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation/day3", [
            'more_like' => 'top1',
            'evidence_sentence' => '我在工作里更容易先守标准。',
            'confidence_self_rating' => 4,
            'scene_type' => 'work',
        ]);

        $day3->assertStatus(200);
        $day3->assertJsonPath('observation_state_v1.status', 'day3_feedback_submitted');
        $day3->assertJsonPath('observation_state_v1.observation_completion_rate', 50);
        $day3->assertJsonPath('observation_state_v1.day3_observation_feedback.more_like', 'top1');
        $this->assertDatabaseHas('events', [
            'event_code' => 'enneagram_day3_feedback_submitted',
            'attempt_id' => $attemptId,
        ]);

        $day7 = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->postJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation/day7", [
            'final_resonance' => 'top1',
            'user_confirmed_type' => '8',
            'wants_fc144' => false,
            'wants_retake_same_form' => false,
            'user_disagreed_reason' => null,
        ]);

        $day7->assertStatus(200);
        $day7->assertJsonPath('observation_state_v1.status', 'user_confirmed');
        $day7->assertJsonPath('observation_state_v1.user_confirmed_type', '8');
        $day7->assertJsonPath('observation_state_v1.suggested_next_action', 'no_action');
        $day7->assertJsonPath('observation_state_v1.observation_completion_rate', 100);
        $this->assertDatabaseHas('events', [
            'event_code' => 'enneagram_day7_feedback_submitted',
            'attempt_id' => $attemptId,
        ]);
        $this->assertDatabaseHas('events', [
            'event_code' => 'enneagram_resonance_feedback_submitted',
            'attempt_id' => $attemptId,
        ]);
        $this->assertDatabaseHas('events', [
            'event_code' => 'enneagram_user_confirmed_type',
            'attempt_id' => $attemptId,
        ]);

        $resultPayload = json_decode((string) DB::table('results')->where('attempt_id', $attemptId)->value('result_json'), true);
        $this->assertSame('T1', data_get($resultPayload, 'enneagram_public_projection_v2.top_types.0.type_code'));

        $show = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation");

        $show->assertStatus(200);
        $show->assertJsonPath('observation_state_v1.user_confirmed_type', '8');
        $show->assertJsonPath('observation_state_v1.day7_resonance_feedback.final_resonance', 'top1');
    }
}
