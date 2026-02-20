<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaginationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_attempts_returns_items_meta_links_and_no_legacy_pagination_field(): void
    {
        $userId = 8401;
        $anonId = 'anon_pagination_contract';

        $this->seedUser($userId);
        $token = $this->seedFmToken($anonId, $userId);
        $attemptId = $this->seedAttempt(0, $anonId, (string) $userId);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v0.3/me/attempts');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonMissingPath('pagination');

        $payload = (array) $response->json();
        $this->assertArrayHasKey('items', $payload);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('links', $payload);
        $this->assertIsArray($payload['items']);
        $this->assertIsArray($payload['meta']);
        $this->assertIsArray($payload['links']);

        $this->assertArrayHasKey('current_page', $payload['meta']);
        $this->assertArrayHasKey('per_page', $payload['meta']);
        $this->assertArrayHasKey('total', $payload['meta']);
        $this->assertArrayHasKey('last_page', $payload['meta']);

        $this->assertArrayHasKey('first', $payload['links']);
        $this->assertArrayHasKey('last', $payload['links']);
        $this->assertArrayHasKey('prev', $payload['links']);
        $this->assertArrayHasKey('next', $payload['links']);

        $firstAttemptId = (string) (($payload['items'][0]['attempt_id'] ?? ''));
        $this->assertSame($attemptId, $firstAttemptId);
    }

    private function seedAttempt(int $orgId, string $anonId, string $userId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
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

    private function seedFmToken(string $anonId, int $userId): string
    {
        $token = 'fm_' . (string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'anon_id' => $anonId,
            'user_id' => $userId,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
