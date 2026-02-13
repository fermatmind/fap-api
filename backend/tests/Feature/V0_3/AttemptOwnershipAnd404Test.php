<?php

namespace Tests\Feature\V0_3;

use App\Services\Auth\FmTokenService;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AttemptOwnershipAnd404Test extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
    }

    private function createUserWithToken(string $email): array
    {
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $issued = app(FmTokenService::class)->issueForUser((string) $userId);

        return [
            'user_id' => (int) $userId,
            'token' => (string) ($issued['token'] ?? ''),
        ];
    }

    private function createOrg(int $ownerUserId): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'name' => 'Org ' . Str::lower(Str::random(8)),
            'owner_user_id' => $ownerUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addMember(int $orgId, int $userId, string $role): void
    {
        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function defaultAnswers(): array
    {
        return [
            ['question_id' => 'SS-001', 'code' => '5'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];
    }

    private function createSubmittedAttempt(int $orgId, string $token, string $anonId): string
    {
        $this->ensureCreditWallet($orgId);

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => $anonId,
        ];

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ], $headers);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $submit = $this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->defaultAnswers(),
            'duration_ms' => 120000,
        ], $headers);
        $submit->assertStatus(200);

        return $attemptId;
    }

    private function ensureCreditWallet(int $orgId): void
    {
        DB::table('benefit_wallets')->upsert([
            [
                'org_id' => $orgId,
                'benefit_code' => 'MBTI_CREDIT',
                'balance' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['org_id', 'benefit_code'], ['balance', 'updated_at']);
    }

    private function assertUniform404(TestResponse $response): void
    {
        $response->assertStatus(404);

        $raw = (string) $response->getContent();
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        $lower = strtolower($raw);
        $this->assertStringNotContainsString('forbidden', $lower);
        $this->assertStringNotContainsString('unauthorized', $lower);
    }

    public function test_member_cannot_access_member_a_attempt_result_report_submit(): void
    {
        $this->seedScales();

        $owner = $this->createUserWithToken('owner_member_case@example.com');
        $memberA = $this->createUserWithToken('member_a_case@example.com');
        $memberB = $this->createUserWithToken('member_b_case@example.com');

        $orgId = $this->createOrg($owner['user_id']);
        $this->addMember($orgId, $owner['user_id'], 'owner');
        $this->addMember($orgId, $memberA['user_id'], 'member');
        $this->addMember($orgId, $memberB['user_id'], 'member');

        $attemptId = $this->createSubmittedAttempt($orgId, $memberA['token'], 'member-a-anon');

        $headersB = [
            'Authorization' => 'Bearer ' . $memberB['token'],
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => 'member-b-anon',
        ];

        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/result", $headersB));
        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/report", $headersB));
        $this->assertUniform404($this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->defaultAnswers(),
            'duration_ms' => 120000,
        ], $headersB));
    }

    public function test_viewer_cannot_access_member_a_attempt_result_report_submit(): void
    {
        $this->seedScales();

        $owner = $this->createUserWithToken('owner_viewer_case@example.com');
        $memberA = $this->createUserWithToken('member_a_viewer_case@example.com');
        $viewerB = $this->createUserWithToken('viewer_b_case@example.com');

        $orgId = $this->createOrg($owner['user_id']);
        $this->addMember($orgId, $owner['user_id'], 'owner');
        $this->addMember($orgId, $memberA['user_id'], 'member');
        $this->addMember($orgId, $viewerB['user_id'], 'viewer');

        $attemptId = $this->createSubmittedAttempt($orgId, $memberA['token'], 'member-a-viewer-anon');

        $headersB = [
            'Authorization' => 'Bearer ' . $viewerB['token'],
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => 'viewer-b-anon',
        ];

        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/result", $headersB));
        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/report", $headersB));
        $this->assertUniform404($this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->defaultAnswers(),
            'duration_ms' => 120000,
        ], $headersB));
    }

    public function test_member_access_nonexistent_attempt_returns_uniform_404(): void
    {
        $this->seedScales();

        $owner = $this->createUserWithToken('owner_nonexistent_case@example.com');
        $member = $this->createUserWithToken('member_nonexistent_case@example.com');

        $orgId = $this->createOrg($owner['user_id']);
        $this->addMember($orgId, $owner['user_id'], 'owner');
        $this->addMember($orgId, $member['user_id'], 'member');

        $missingAttemptId = (string) Str::uuid();
        $headers = [
            'Authorization' => 'Bearer ' . $member['token'],
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => 'member-nonexistent-anon',
        ];

        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$missingAttemptId}/result", $headers));
        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$missingAttemptId}/report", $headers));
        $this->assertUniform404($this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $missingAttemptId,
            'answers' => $this->defaultAnswers(),
            'duration_ms' => 120000,
        ], $headers));
    }

    public function test_admin_and_owner_without_identity_binding_get_uniform_404(): void
    {
        $this->seedScales();

        $owner = $this->createUserWithToken('owner_admin_case@example.com');
        $memberA = $this->createUserWithToken('member_a_admin_case@example.com');
        $admin = $this->createUserWithToken('admin_case@example.com');

        $orgId = $this->createOrg($owner['user_id']);
        $this->addMember($orgId, $owner['user_id'], 'owner');
        $this->addMember($orgId, $memberA['user_id'], 'member');
        $this->addMember($orgId, $admin['user_id'], 'admin');

        $attemptId = $this->createSubmittedAttempt($orgId, $memberA['token'], 'member-a-admin-anon');

        $ownerHeaders = [
            'Authorization' => 'Bearer ' . $owner['token'],
            'X-Org-Id' => (string) $orgId,
        ];
        $adminHeaders = [
            'Authorization' => 'Bearer ' . $admin['token'],
            'X-Org-Id' => (string) $orgId,
        ];

        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/result", $ownerHeaders));
        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/report", $ownerHeaders));
        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/result", $adminHeaders));
        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/report", $adminHeaders));
    }

    public function test_missing_token_or_cross_org_access_returns_404(): void
    {
        $this->seedScales();

        $owner = $this->createUserWithToken('owner_cross_org_case@example.com');
        $memberA = $this->createUserWithToken('member_a_cross_org_case@example.com');
        $memberB = $this->createUserWithToken('member_b_cross_org_case@example.com');

        $org1 = $this->createOrg($owner['user_id']);
        $this->addMember($org1, $owner['user_id'], 'owner');
        $this->addMember($org1, $memberA['user_id'], 'member');

        $org2 = $this->createOrg($memberB['user_id']);
        $this->addMember($org2, $memberB['user_id'], 'owner');

        $attemptId = $this->createSubmittedAttempt($org1, $memberA['token'], 'member-a-cross-org-anon');

        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/result", [
            'X-Org-Id' => (string) $org1,
        ]));

        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/result", [
            'Authorization' => 'Bearer ' . $memberB['token'],
            'X-Org-Id' => (string) $org2,
        ]));
    }
}
