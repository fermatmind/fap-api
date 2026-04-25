<?php

declare(strict_types=1);

namespace Tests\Feature\Attempts;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsEnneagramAttempts;
use Tests\TestCase;

final class EnneagramHistoryObservationSummaryTest extends TestCase
{
    use BuildsEnneagramAttempts;
    use RefreshDatabase;

    public function test_me_attempts_enneagram_includes_observation_summary_fields(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_history_observation';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($anonId, $token);
        $this->patchEnneagramProjection($attemptId, 'enneagram_likert_105', 'diffuse');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->postJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation/day7", [
            'final_resonance' => 'still_uncertain',
            'user_confirmed_type' => null,
            'wants_fc144' => false,
            'wants_retake_same_form' => false,
            'user_disagreed_reason' => null,
        ])->assertStatus(200);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson('/api/v0.3/me/attempts?scale=ENNEAGRAM');

        $response->assertStatus(200);
        $response->assertJsonPath('items.0.observation_status', 'resonance_feedback_submitted');
        $response->assertJsonPath('items.0.observation_completion_rate', 100);
        $response->assertJsonPath('items.0.day7_submitted', true);
        $response->assertJsonPath('items.0.observation_state_v1.version', 'enneagram_observation_state.v1');
        $response->assertJsonPath('history_compare.current_observation_state_v1.status', 'resonance_feedback_submitted');
        $response->assertJsonPath('history_compare.current_observation_state_v1.day7_submitted', true);
    }
}
