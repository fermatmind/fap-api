<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsEnneagramAttempts;
use Tests\TestCase;

final class EnneagramObservationAuthorizationTest extends TestCase
{
    use BuildsEnneagramAttempts;
    use RefreshDatabase;

    public function test_non_owner_cannot_read_or_mutate_observation_state(): void
    {
        (new ScaleRegistrySeeder)->run();

        $ownerAnonId = 'anon_observation_owner';
        $ownerToken = $this->issueAnonToken($ownerAnonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($ownerAnonId, $ownerToken);
        $this->patchEnneagramProjection($attemptId, 'enneagram_likert_105', 'clear');

        $otherAnonId = 'anon_observation_other';
        $otherToken = $this->issueAnonToken($otherAnonId);

        $show = $this->withHeaders([
            'Authorization' => 'Bearer '.$otherToken,
            'X-Anon-Id' => $otherAnonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation");
        $show->assertStatus(404);

        $day3 = $this->withHeaders([
            'Authorization' => 'Bearer '.$otherToken,
            'X-Anon-Id' => $otherAnonId,
        ])->postJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation/day3", [
            'more_like' => 'top1',
            'evidence_sentence' => 'unauthorized',
            'confidence_self_rating' => 3,
            'scene_type' => 'other',
        ]);
        $day3->assertStatus(404);
    }

    public function test_non_enneagram_attempt_is_rejected(): void
    {
        (new ScaleRegistrySeeder)->run();

        $anonId = 'anon_observation_wrong_scale';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($anonId, $token);

        DB::table('attempts')->where('id', $attemptId)->update(['scale_code' => 'MBTI']);
        DB::table('results')->where('attempt_id', $attemptId)->update(['scale_code' => 'MBTI']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation");

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'SCALE_NOT_SUPPORTED');
    }

    public function test_non_owner_does_not_learn_non_enneagram_scale_from_observation_endpoint(): void
    {
        (new ScaleRegistrySeeder)->run();

        $ownerAnonId = 'anon_observation_wrong_scale_owner';
        $ownerToken = $this->issueAnonToken($ownerAnonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($ownerAnonId, $ownerToken);

        DB::table('attempts')->where('id', $attemptId)->update(['scale_code' => 'MBTI']);
        DB::table('results')->where('attempt_id', $attemptId)->update(['scale_code' => 'MBTI']);

        $otherAnonId = 'anon_observation_wrong_scale_probe';
        $otherToken = $this->issueAnonToken($otherAnonId);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$otherToken,
            'X-Anon-Id' => $otherAnonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/enneagram/observation");

        $response->assertStatus(404);
        $this->assertStringNotContainsString('SCALE_NOT_SUPPORTED', (string) $response->getContent());
    }
}
