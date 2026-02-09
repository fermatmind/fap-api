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

class AttemptMemberViewerOwnershipTest extends TestCase
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

    private function startAttempt(int $orgId, string $token, string $anonId): string
    {
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

        return $attemptId;
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

    public function test_member_cannot_tamper_other_member_attempt_submit_or_progress(): void
    {
        $this->seedScales();

        $owner = $this->createUserWithToken('high3_owner@example.com');
        $memberA = $this->createUserWithToken('high3_member_a@example.com');
        $memberB = $this->createUserWithToken('high3_member_b@example.com');

        $orgId = $this->createOrg($owner['user_id']);
        $this->addMember($orgId, $owner['user_id'], 'owner');
        $this->addMember($orgId, $memberA['user_id'], 'member');
        $this->addMember($orgId, $memberB['user_id'], 'member');
        $this->ensureCreditWallet($orgId);

        $attemptId = $this->startAttempt($orgId, $memberA['token'], 'high3-member-a-anon');

        $headersB = [
            'Authorization' => 'Bearer ' . $memberB['token'],
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => 'high3-member-b-anon',
        ];

        $this->assertUniform404($this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->defaultAnswers(),
            'duration_ms' => 120000,
        ], $headersB));

        $this->assertUniform404($this->getJson("/api/v0.3/attempts/{$attemptId}/progress", $headersB));

        $this->assertUniform404($this->putJson("/api/v0.3/attempts/{$attemptId}/progress", [
            'seq' => 1,
            'cursor' => 'page-1',
            'duration_ms' => 1000,
            'answers' => [
                [
                    'question_id' => 'SS-001',
                    'question_type' => 'single_choice',
                    'question_index' => 0,
                    'code' => '5',
                ],
            ],
        ], $headersB));
    }
}
