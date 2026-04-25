<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsEnneagramAttempts;
use Tests\TestCase;

final class EnneagramObservationStateContractTest extends TestCase
{
    use BuildsEnneagramAttempts;
    use RefreshDatabase;

    public function test_owner_can_assign_and_read_observation_state_for_completed_enneagram_attempt(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_observation_assign';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($anonId, $token);
        $this->patchEnneagramProjection($attemptId, 'enneagram_likert_105', 'close_call');

        $assign = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->postJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation/assign");

        $assign->assertStatus(200);
        $assign->assertJsonPath('ok', true);
        $assign->assertJsonPath('observation_state_v1.version', 'enneagram_observation_state.v1');
        $assign->assertJsonPath('observation_state_v1.status', 'observation_assigned');
        $assign->assertJsonPath('observation_state_v1.suggested_next_action', 'do_fc144');
        $this->assertCount(7, (array) $assign->json('observation_state_v1.tasks'));

        $show = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation");

        $show->assertStatus(200);
        $show->assertJsonPath('observation_state_v1.attempt_id', $attemptId);
        $show->assertJsonPath('observation_state_v1.status', 'observation_assigned');
        $show->assertJsonPath('observation_state_v1.close_call_pair.pair_key', 'T4_T5');
    }

    public function test_diffuse_and_low_quality_attempts_get_scope_appropriate_default_actions(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_observation_scope_defaults';
        $token = $this->issueAnonToken($anonId);

        $diffuseAttemptId = $this->createSubmittedEnneagramAttempt($anonId, $token, 'enneagram_likert_105', 106000);
        $this->patchEnneagramProjection($diffuseAttemptId, 'enneagram_likert_105', 'diffuse');

        $lowQualityAttemptId = $this->createSubmittedEnneagramAttempt($anonId, $token, 'enneagram_likert_105', 107000);
        $this->patchEnneagramProjection($lowQualityAttemptId, 'enneagram_likert_105', 'low_quality');

        $diffuse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->postJson("/api/v0.3/attempts/{$diffuseAttemptId}/enneagram/observation/assign");
        $diffuse->assertStatus(200);
        $diffuse->assertJsonPath('observation_state_v1.suggested_next_action', 'read_top3');

        $lowQuality = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->postJson("/api/v0.3/attempts/{$lowQualityAttemptId}/enneagram/observation/assign");
        $lowQuality->assertStatus(200);
        $lowQuality->assertJsonPath('observation_state_v1.suggested_next_action', 'retest_same_form');
    }
}
