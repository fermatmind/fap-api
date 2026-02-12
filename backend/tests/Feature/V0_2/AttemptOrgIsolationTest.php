<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Models\Attempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptOrgIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_org0_attempt_is_readable_in_org0_and_me_attempts_is_scoped_to_org0(): void
    {
        $userId = '9101';
        $anonId = 'anon_org0_owner';

        $this->seedUser((int) $userId);
        $token = $this->seedFmToken($anonId, (int) $userId);

        $attemptOrg0 = $this->seedAttempt(
            orgId: 0,
            anonId: $anonId,
            userId: $userId
        );
        $attemptOrg1 = $this->seedAttempt(
            orgId: 1,
            anonId: $anonId,
            userId: $userId
        );

        $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.2/attempts/{$attemptOrg0}/stats")
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptOrg0);

        $resp = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v0.2/me/attempts');

        $resp->assertStatus(200);
        $resp->assertJsonPath('ok', true);

        $ids = array_map(static fn (array $row): string => (string) ($row['attempt_id'] ?? ''), (array) $resp->json('items', []));

        $this->assertContains($attemptOrg0, $ids);
        $this->assertNotContains($attemptOrg1, $ids);
    }

    public function test_org1_attempt_returns_404_when_read_from_org0_and_200_in_org1(): void
    {
        $userId = '9201';
        $anonId = 'anon_org1_owner';

        $attemptId = $this->seedAttempt(
            orgId: 1,
            anonId: $anonId,
            userId: $userId
        );

        $this->withHeaders([
            'X-Org-Id' => '0',
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.2/attempts/{$attemptId}/stats")
            ->assertStatus(404);

        $this->withHeaders([
            'X-Org-Id' => '1',
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.2/attempts/{$attemptId}/stats")
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptId);
    }

    public function test_lookup_ticket_and_device_return_404_when_org_mismatched(): void
    {
        $userId = '9301';
        $anonId = 'anon_lookup_owner';

        $this->seedUser((int) $userId);
        $token = $this->seedFmToken($anonId, (int) $userId);

        $ticketCode = 'FMT-' . strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8));
        $attemptId = $this->seedAttempt(
            orgId: 1,
            anonId: $anonId,
            userId: $userId,
            ticketCode: $ticketCode
        );

        $this->withHeader('X-Org-Id', '0')
            ->getJson("/api/v0.2/lookup/ticket/{$ticketCode}")
            ->assertStatus(404);

        $this->withHeader('X-Org-Id', '1')
            ->getJson("/api/v0.2/lookup/ticket/{$ticketCode}")
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptId);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Org-Id' => '0',
        ])->postJson('/api/v0.2/lookup/device', [
            'attempt_ids' => [$attemptId],
        ])->assertStatus(404);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Org-Id' => '1',
        ])->postJson('/api/v0.2/lookup/device', [
            'attempt_ids' => [$attemptId],
        ])->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items.0.attempt_id', $attemptId);
    }

    private function seedAttempt(int $orgId, string $anonId, string $userId, ?string $ticketCode = null): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'ticket_code' => $ticketCode,
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
            'anon_id' => $anonId,
            'user_id' => $userId,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
