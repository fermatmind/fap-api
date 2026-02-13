<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FmTokenOrgOverrideIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fm_token_org_cannot_be_overridden_by_header_org_id(): void
    {
        $userId = 9901;
        $anonId = 'anon_token_org_override';
        $this->seedUser($userId);
        $token = $this->seedFmToken($userId, $anonId, 0);

        $attemptOrg0 = $this->seedAttempt(0, $anonId, (string) $userId);
        $attemptOrg1 = $this->seedAttempt(1, $anonId, (string) $userId);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Org-Id' => '1',
        ])->getJson('/api/v0.2/me/attempts');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $items = (array) $response->json('items', []);
        $ids = array_map(
            static fn (array $row): string => (string) ($row['attempt_id'] ?? ''),
            $items
        );

        $this->assertContains($attemptOrg0, $ids);
        $this->assertNotContains($attemptOrg1, $ids);
    }

    private function seedUser(int $id): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => "user_{$id}",
            'email' => "user_{$id}@example.test",
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFmToken(int $userId, string $anonId, int $orgId): string
    {
        $token = 'fm_' . (string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'anon_id' => $anonId,
            'org_id' => $orgId,
            'role' => 'public',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function seedAttempt(int $orgId, string $anonId, string $userId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'ticket_code' => 'FMT-' . strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => $userId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinutes(2),
            'submitted_at' => now()->subMinute(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.2.2',
            'scoring_spec_version' => '2026.01',
            'calculation_snapshot_json' => [
                'stats' => ['score' => 42],
                'norm' => ['version' => 'test'],
            ],
        ]);

        return $attemptId;
    }
}
