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

    public function test_event_payload_is_limited_before_store(): void
    {
        config()->set('fap.events.max_top_keys', 200);
        config()->set('fap.events.max_depth', 4);
        config()->set('fap.events.max_list_length', 50);
        config()->set('fap.events.max_string_length', 10);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
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

        $response->assertStatus(201)->assertJson([
            'ok' => true,
        ]);

        $row = DB::table('events')->where('id', (string) $response->json('id'))->first();
        $this->assertNotNull($row);

        $meta = $row->meta_json ?? null;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        $this->assertIsArray($meta);
        $this->assertSame(10, strlen((string) data_get($meta, 'props.long')));
    }

    public function test_event_raw_payload_bytes_too_large_returns_413_and_not_store(): void
    {
        config()->set('fap.events.max_payload_bytes', 256);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
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

        $response->assertStatus(413)->assertJson([
            'ok' => false,
            'error' => 'payload_too_large',
        ]);

        $this->assertSame(0, DB::table('events')->where('event_code', 'pr48_payload_too_large')->count());
    }
}
