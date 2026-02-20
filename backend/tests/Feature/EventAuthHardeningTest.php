<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EventAuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_v02_events_rejects_revoked_fm_token_with_deprecated_contract(): void
    {
        $token = $this->seedUserAndToken(3001, revokedAt: now()->subMinute()->toDateTimeString());

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/events', $this->eventPayload('evt_revoked'));

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
        $this->assertSame(0, DB::table('events')->where('event_code', 'evt_revoked')->count());
    }

    public function test_v02_events_rejects_expired_fm_token_with_deprecated_contract(): void
    {
        $token = $this->seedUserAndToken(3002, expiresAt: now()->subMinute()->toDateTimeString());

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/events', $this->eventPayload('evt_expired'));

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
        $this->assertSame(0, DB::table('events')->where('event_code', 'evt_expired')->count());
    }

    public function test_v02_events_do_not_write_even_with_authenticated_token(): void
    {
        $token = $this->seedUserAndToken(3003);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/events', array_merge($this->eventPayload('evt_bound_user'), [
            'user_id' => 999999,
            'props' => [
                'user_id' => 999998,
            ],
        ]));

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
        $this->assertSame(0, DB::table('events')->where('event_code', 'evt_bound_user')->count());
    }

    public function test_v02_events_do_not_write_anon_binding_even_with_authenticated_token(): void
    {
        $token = $this->seedUserAndToken(3004, anonId: 'event-auth-token-anon');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/events', array_merge($this->eventPayload('evt_bound_anon'), [
            'anon_id' => 'forged-anon-id',
            'props' => [
                'anon_id' => 'props-forged-anon',
            ],
        ]));

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
        $this->assertSame(0, DB::table('events')->where('event_code', 'evt_bound_anon')->count());
    }

    public function test_v02_events_reject_cross_user_attempt_reference_with_deprecated_contract(): void
    {
        $ownerToken = $this->seedUserAndToken(3005, anonId: 'event-owner-anon');
        $attackerToken = $this->seedUserAndToken(3006, anonId: 'event-attacker-anon');

        $attemptId = (string) Str::uuid();
        $this->seedAttempt($attemptId, 0, 'event-owner-anon', 3005);

        $this->withHeaders([
            'Authorization' => "Bearer {$ownerToken}",
        ])->postJson('/api/v0.2/events', array_merge($this->eventPayload('evt_owner_write'), [
            'attempt_id' => $attemptId,
        ]))->assertStatus(410);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$attackerToken}",
        ])->postJson('/api/v0.2/events', array_merge($this->eventPayload('evt_cross_user_deny'), [
            'attempt_id' => $attemptId,
        ]));

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);

        $this->assertSame(0, DB::table('events')->where('event_code', 'evt_cross_user_deny')->count());
    }

    public function test_v02_events_use_deprecated_contract_when_token_is_org_bound(): void
    {
        $token = $this->seedUserAndToken(3007, orgId: 17);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Org-Id' => '999',
        ])->postJson('/api/v0.2/events', $this->eventPayload('evt_token_org_bound'));

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
        $this->assertSame(0, DB::table('events')->where('event_code', 'evt_token_org_bound')->count());
    }

    private function eventPayload(string $eventCode): array
    {
        return [
            'event_code' => $eventCode,
            'attempt_id' => (string) Str::uuid(),
            'anon_id' => 'event-auth-anon',
        ];
    }

    private function seedUserAndToken(
        int $userId,
        ?string $revokedAt = null,
        ?string $expiresAt = null,
        ?string $anonId = null,
        ?int $orgId = null
    ): string {
        DB::table('users')->insert([
            'id' => $userId,
            'name' => "event-auth-user-{$userId}",
            'email' => "event-auth-user-{$userId}@example.com",
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'anon_id' => $anonId ?? "event-auth-anon-{$userId}",
            'org_id' => $orgId ?? 0,
            'revoked_at' => $revokedAt,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function seedAttempt(string $attemptId, int $orgId, string $anonId, int $userId): void
    {
        $now = now();
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => (string) $userId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 10,
            'answers_summary_json' => json_encode(['answered' => 10], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'web',
            'content_package_version' => 'v0.2.2',
            'started_at' => $now->copy()->subMinutes(2),
            'submitted_at' => $now->copy()->subMinute(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
