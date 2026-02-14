<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InsightsFeedbackAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_requires_fm_token(): void
    {
        config()->set('fap.features.insights', true);

        $insightId = $this->createAnonymousInsight('anon_feedback_owner');

        $response = $this->withHeaders([
            'X-FAP-Anon-Id' => 'anon_feedback_owner',
        ])->postJson("/api/v0.2/insights/{$insightId}/feedback", [
            'rating' => 4,
            'reason' => 'helpful',
            'comment' => 'no token should fail',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'ok' => false,
                'error_code' => 'UNAUTHENTICATED',
            ]);
    }

    public function test_feedback_accepts_anon_token_identity_without_anon_header(): void
    {
        config()->set('fap.features.insights', true);

        $insightId = $this->createAnonymousInsight('anon_feedback_owner');
        $token = $this->seedAnonToken('anon_feedback_owner');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.2/insights/{$insightId}/feedback", [
            'rating' => 5,
            'reason' => 'helpful',
            'comment' => 'token-backed anon feedback',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('ai_insight_feedback', [
            'insight_id' => $insightId,
            'rating' => 5,
            'reason' => 'helpful',
        ]);
    }

    public function test_feedback_rejects_mismatched_user_even_when_anon_matches(): void
    {
        config()->set('fap.features.insights', true);

        $ownerUserId = 21001;
        $attackerUserId = 21002;
        $sharedAnonId = 'anon_feedback_shared';
        $insightId = $this->createUserBoundInsight((string) $ownerUserId, $sharedAnonId);

        $this->createUser($ownerUserId);
        $this->createUser($attackerUserId);
        $token = $this->seedUserAnonToken($attackerUserId, $sharedAnonId);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson("/api/v0.2/insights/{$insightId}/feedback", [
            'rating' => 3,
            'reason' => 'helpful',
        ])->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->assertDatabaseMissing('ai_insight_feedback', [
            'insight_id' => $insightId,
            'rating' => 3,
            'reason' => 'helpful',
        ]);
    }

    public function test_feedback_duplicate_submission_is_idempotent(): void
    {
        config()->set('fap.features.insights', true);

        $insightId = $this->createAnonymousInsight('anon_feedback_owner');
        $token = $this->seedAnonToken('anon_feedback_owner');

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'feedback-idem-1',
        ])->postJson("/api/v0.2/insights/{$insightId}/feedback", [
            'rating' => 4,
            'reason' => 'helpful',
            'comment' => 'repeatable',
        ]);

        $first->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('idempotent', false);

        $second = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => 'feedback-idem-1',
        ])->postJson("/api/v0.2/insights/{$insightId}/feedback", [
            'rating' => 4,
            'reason' => 'helpful',
            'comment' => 'repeatable',
        ]);

        $second->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('id', (string) $first->json('id'));

        $this->assertSame(1, DB::table('ai_insight_feedback')->where('insight_id', $insightId)->count());
    }

    private function createAnonymousInsight(string $anonId): string
    {
        $insightId = (string) Str::uuid();

        DB::table('ai_insights')->insert([
            'id' => $insightId,
            'user_id' => null,
            'anon_id' => $anonId,
            'period_type' => 'week',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-07',
            'input_hash' => hash('sha256', 'feedback:' . $insightId),
            'prompt_version' => 'v1.0.0',
            'model' => 'mock-model',
            'provider' => 'mock',
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0,
            'status' => 'succeeded',
            'output_json' => null,
            'evidence_json' => null,
            'error_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $insightId;
    }

    private function createUserBoundInsight(string $userId, string $anonId): string
    {
        $insightId = (string) Str::uuid();

        DB::table('ai_insights')->insert([
            'id' => $insightId,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'period_type' => 'week',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-07',
            'input_hash' => hash('sha256', 'feedback_user:' . $insightId),
            'prompt_version' => 'v1.0.0',
            'model' => 'mock-model',
            'provider' => 'mock',
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0,
            'status' => 'succeeded',
            'output_json' => null,
            'evidence_json' => null,
            'error_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $insightId;
    }

    private function seedAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'anon_id' => $anonId,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function seedUserAnonToken(int $userId, string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'anon_id' => $anonId,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createUser(int $id): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'User ' . $id,
            'email' => 'insight_user_' . $id . '_' . Str::lower(Str::random(8)) . '@example.test',
            'password' => '$2y$12$5x7R5V8R7gUzKIiMzFxLDe0X58F3RDFo63eFUsVTNff7kwh28ykV6',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
