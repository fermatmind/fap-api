<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShareSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_access_own_attempt_share_returns_200(): void
    {
        $orgId = 1;
        $ownerAnon = 'anon_owner_' . Str::random(8);

        $attemptId = (string) Str::uuid();
        $this->seedAttemptAndResult($attemptId, $orgId, $ownerAnon, null);

        $token = $this->seedFmToken($ownerAnon);

        $resp = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.2/attempts/' . $attemptId . '/share');

        $resp->assertStatus(200);
        $resp->assertJson([
            'ok' => true,
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
        ]);

        $shareId = $resp->json('share_id');
        $this->assertIsString($shareId);
        $this->assertSame(32, strlen($shareId));
        $this->assertTrue(ctype_xdigit($shareId));
    }

    public function test_user_cannot_access_other_members_attempt_in_same_org_returns_404(): void
    {
        $orgId = 2;
        $ownerAnon = 'anon_owner_' . Str::random(8);
        $attackerAnon = 'anon_attacker_' . Str::random(8);

        $attemptId = (string) Str::uuid();
        $this->seedAttemptAndResult($attemptId, $orgId, $ownerAnon, null);

        $token = $this->seedFmToken($attackerAnon);

        $resp = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.2/attempts/' . $attemptId . '/share');

        $resp->assertStatus(404);
    }

    public function test_cross_org_access_returns_404(): void
    {
        $orgA = 10;
        $orgB = 11;

        $anonA = 'anon_a_' . Str::random(8);

        $attemptIdB = (string) Str::uuid();
        $this->seedAttemptAndResult($attemptIdB, $orgB, 'anon_b_' . Str::random(8), null);

        $token = $this->seedFmToken($anonA);

        $resp = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgA,
        ])->getJson('/api/v0.2/attempts/' . $attemptIdB . '/share');

        $resp->assertStatus(404);
    }

    private function seedFmToken(string $anonId, ?int $userId = null): string
    {
        $token = 'fm_' . (string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'anon_id' => $anonId,
            'user_id' => $userId,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function seedAttemptAndResult(string $attemptId, int $orgId, string $anonId, ?int $userId): void
    {
        $now = now();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => $userId ? (string) $userId : null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'question_count' => 10,
            'answers_summary_json' => json_encode(['answered' => 10], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'web',
            'content_package_version' => 'cp_v1',
            'started_at' => $now->copy()->subMinutes(3),
            'submitted_at' => $now->copy()->subMinutes(1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'content_package_version' => 'cp_v1',
            'type_code' => 'INTJ',
            'scores_json' => json_encode(['EI' => ['a' => 10, 'b' => 20, 'sum' => -10, 'total' => 30]], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode(['EI' => 33], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode(['EI' => 'clear'], JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode([
                'type_code' => 'INTJ',
                'type_name' => 'Architect',
            ], JSON_UNESCAPED_UNICODE),
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
