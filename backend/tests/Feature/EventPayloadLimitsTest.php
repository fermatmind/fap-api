<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EventPayloadLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_v02_event_payload_endpoint_returns_deprecated_contract_before_store(): void
    {
        config()->set('fap.events.max_top_keys', 200);
        config()->set('fap.events.max_depth', 4);
        config()->set('fap.events.max_list_length', 50);
        config()->set('fap.events.max_string_length', 10);

        DB::table('users')->insert([
            'id' => 1001,
            'name' => 'event-limits-user-1001',
            'email' => 'event-limits-1001@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'anon_id' => 'pr44-event-limits-anon',
            'user_id' => 1001,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/events', [
            'event_code' => 'pr44_payload_limit',
            'attempt_id' => (string) Str::uuid(),
            'anon_id' => 'pr44-event-limits-anon',
            'meta_json' => [
                'props' => [
                    'long' => str_repeat('x', 32),
                ],
            ],
        ]);

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
        $this->assertSame(0, DB::table('events')->where('event_code', 'pr44_payload_limit')->count());
    }

    public function test_v02_event_raw_payload_bytes_returns_deprecated_contract_and_not_store(): void
    {
        config()->set('fap.events.max_payload_bytes', 256);

        DB::table('users')->insert([
            'id' => 2001,
            'name' => 'event-limits-user-2001',
            'email' => 'event-limits-2001@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'anon_id' => 'pr48-event-bytes-anon',
            'user_id' => 2001,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rawBody = json_encode([
            'event_code' => 'pr48_payload_too_large',
            'attempt_id' => (string) Str::uuid(),
            'anon_id' => 'pr48-event-bytes-anon',
            'meta_json' => [
                'blob' => str_repeat('x', 2000),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($rawBody);
        self::assertGreaterThan(256, strlen($rawBody));

        $response = $this->call(
            'POST',
            '/api/v0.2/events',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => "Bearer {$token}",
            ],
            $rawBody
        );

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);

        $this->assertSame(0, DB::table('events')->where('event_code', 'pr48_payload_too_large')->count());
    }
}
