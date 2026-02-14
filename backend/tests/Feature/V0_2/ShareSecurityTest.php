<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShareSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_a_access_own_attempt_returns_200(): void
    {
        $orgId = 1;
        $userA = $this->seedUser('share-a@example.com');
        $attemptId = (string) Str::uuid();
        $anonA = 'anon_a_' . Str::random(8);
        $existingShareId = bin2hex(random_bytes(16));

        $this->seedAttemptAndResult($attemptId, $orgId, $anonA, $userA);
        $this->seedShare($existingShareId, $attemptId, $anonA);
        $this->seedScaleRegistry($orgId, 'MBTI');
        $this->seedBenefitGrant($orgId, $attemptId, (string) $userA, $anonA);

        $tokenA = $this->issueTokenForUser($userA, $orgId);

        $resp = $this->withHeaders($this->authHeaders($tokenA, $orgId))
            ->getJson('/api/v0.2/attempts/' . $attemptId . '/share');

        $resp->assertStatus(200);
        $resp->assertJsonPath('ok', true);
        $resp->assertJsonPath('attempt_id', $attemptId);
        $resp->assertJsonPath('org_id', $orgId);
        $resp->assertJsonPath('share_id', $existingShareId);
    }

    public function test_user_a_access_same_org_user_b_attempt_returns_404(): void
    {
        $orgId = 2;
        $userA = $this->seedUser('share-org-a@example.com');
        $userB = $this->seedUser('share-org-b@example.com');

        $attemptId = (string) Str::uuid();
        $this->seedAttemptAndResult($attemptId, $orgId, 'anon_b_' . Str::random(8), $userB);

        $tokenA = $this->issueTokenForUser($userA, $orgId);

        $resp = $this->withHeaders($this->authHeaders($tokenA, $orgId))
            ->getJson('/api/v0.2/attempts/' . $attemptId . '/share');

        $resp->assertStatus(404);
    }

    public function test_user_a_access_cross_org_attempt_returns_404(): void
    {
        $orgA = 10;
        $orgB = 11;
        $userA = $this->seedUser('share-cross-a@example.com');
        $userB = $this->seedUser('share-cross-b@example.com');

        $attemptIdB = (string) Str::uuid();
        $this->seedAttemptAndResult($attemptIdB, $orgB, 'anon_cross_b_' . Str::random(8), $userB);

        $tokenA = $this->issueTokenForUser($userA, $orgA);

        $resp = $this->withHeaders($this->authHeaders($tokenA, $orgA))
            ->getJson('/api/v0.2/attempts/' . $attemptIdB . '/share');

        $resp->assertStatus(404);
    }

    public function test_share_endpoint_without_token_returns_401(): void
    {
        $orgId = 20;
        $userA = $this->seedUser('share-no-token@example.com');
        $attemptId = (string) Str::uuid();
        $this->seedAttemptAndResult($attemptId, $orgId, 'anon_no_token_' . Str::random(8), $userA);

        $resp = $this->withHeaders([
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.2/attempts/' . $attemptId . '/share');

        $resp->assertStatus(401);
        $resp->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    private function authHeaders(string $token, int $orgId): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'X-FM-TOKEN' => $token,
            'X-Org-Id' => (string) $orgId,
        ];
    }

    private function issueTokenForUser(int $userId, int $orgId = 0): string
    {
        $issued = app(FmTokenService::class)->issueForUser((string) $userId, [
            'org_id' => $orgId,
        ]);

        return (string) ($issued['token'] ?? '');
    }

    private function seedUser(string $email): int
    {
        $now = now();

        return (int) DB::table('users')->insertGetId([
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedAttemptAndResult(string $attemptId, int $orgId, string $anonId, int $userId): void
    {
        $now = now();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => (string) $userId,
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

    private function seedShare(string $shareId, string $attemptId, string $anonId): void
    {
        $now = now();

        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'content_package_version' => 'cp_v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedScaleRegistry(int $orgId, string $scaleCode): void
    {
        DB::table('scales_registry')->insert([
            'code' => $scaleCode,
            'org_id' => $orgId,
            'primary_slug' => 'mbti-test',
            'slugs_json' => json_encode(['mbti-test'], JSON_UNESCAPED_UNICODE),
            'driver_type' => 'mbti',
            'commercial_json' => json_encode([
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',
            ], JSON_UNESCAPED_UNICODE),
            'is_public' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBenefitGrant(int $orgId, string $attemptId, string $userId, string $benefitRef): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => $userId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'status' => 'active',
            'benefit_type' => 'report_unlock',
            'benefit_ref' => $benefitRef,
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
