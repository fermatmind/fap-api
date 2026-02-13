<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShareViewOrgIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_org_token_can_access_share_view(): void
    {
        $orgId = 7;
        $userId = $this->seedUser('share-view-owner@example.com');

        $attemptId = (string) Str::uuid();
        $shareId = bin2hex(random_bytes(16));

        $this->seedAttemptResultAndShare($attemptId, $shareId, $orgId, (string) $userId, 'anon_share_view_owner');
        $token = $this->issueToken((string) $userId, $orgId, 'anon_share_view_owner');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v0.2/share/' . $shareId);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('share_id', $shareId);
        $response->assertJsonPath('attempt_id', $attemptId);
    }

    public function test_cross_org_token_gets_404_on_share_view(): void
    {
        $orgA = 8;
        $orgB = 9;
        $userId = $this->seedUser('share-view-cross@example.com');

        $attemptId = (string) Str::uuid();
        $shareId = bin2hex(random_bytes(16));

        $this->seedAttemptResultAndShare($attemptId, $shareId, $orgA, (string) $userId, 'anon_share_view_cross');
        $token = $this->issueToken((string) $userId, $orgB, 'anon_share_view_cross');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v0.2/share/' . $shareId);

        $response->assertStatus(404);
    }

    private function seedUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'User ' . $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueToken(string $userId, int $orgId, string $anonId): string
    {
        $issued = app(FmTokenService::class)->issueForUser($userId, [
            'org_id' => $orgId,
            'anon_id' => $anonId,
        ]);

        return (string) ($issued['token'] ?? '');
    }

    private function seedAttemptResultAndShare(
        string $attemptId,
        string $shareId,
        int $orgId,
        string $userId,
        string $anonId,
    ): void {
        $now = now();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => $userId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 10,
            'answers_summary_json' => json_encode(['answered' => 10], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'web',
            'content_package_version' => 'v0.2.2',
            'started_at' => $now->copy()->subMinutes(3),
            'submitted_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'content_package_version' => 'v0.2.2',
            'type_code' => 'INTJ-A',
            'scores_json' => json_encode(['EI' => ['a' => 10, 'b' => 20, 'sum' => -10, 'total' => 30]], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode(['EI' => 33], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode(['EI' => 'clear'], JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode(['type_code' => 'INTJ-A', 'type_name' => 'Seeded'], JSON_UNESCAPED_UNICODE),
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'content_package_version' => 'v0.2.2',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
