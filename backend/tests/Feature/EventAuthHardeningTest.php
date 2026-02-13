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

    public function test_events_rejects_revoked_fm_token(): void
    {
        $token = $this->seedUserAndToken(3001, revokedAt: now()->subMinute()->toDateTimeString());

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/events', $this->eventPayload('evt_revoked'));

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
        ]);
        $this->assertSame(0, DB::table('events')->where('event_code', 'evt_revoked')->count());
    }

    public function test_events_rejects_expired_fm_token(): void
    {
        $token = $this->seedUserAndToken(3002, expiresAt: now()->subMinute()->toDateTimeString());

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/events', $this->eventPayload('evt_expired'));

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
        ]);
        $this->assertSame(0, DB::table('events')->where('event_code', 'evt_expired')->count());
    }

    public function test_events_bind_user_id_to_authenticated_token(): void
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

        $response->assertStatus(201)->assertJson([
            'ok' => true,
        ]);

        $row = DB::table('events')->where('id', (string) $response->json('id'))->first();
        $this->assertNotNull($row);
        $this->assertSame(3003, (int) ($row->user_id ?? 0));
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
        ?string $expiresAt = null
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
            'anon_id' => "event-auth-anon-{$userId}",
            'revoked_at' => $revokedAt,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
