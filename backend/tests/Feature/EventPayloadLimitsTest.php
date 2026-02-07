<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventPayloadLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_endpoint_limits_payload_before_store(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'anon_id' => 'pr44-anon',
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('fap.events.max_top_keys', 200);
        config()->set('fap.events.max_depth', 2);
        config()->set('fap.events.max_list_length', 3);
        config()->set('fap.events.max_string_length', 10);

        $payload = [
            'event_code' => 'pr44_payload_limit',
            'attempt_id' => (string) Str::uuid(),
            'anon_id' => 'pr44-anon',
            'props' => [
                'long' => str_repeat('x', 64),
                'list' => [1, 2, 3, 4, 5],
            ],
            'meta_json' => [
                'deep' => [
                    'inner' => [
                        'tooDeep' => ['k' => 'v'],
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v0.2/events', $payload);

        $response->assertStatus(201)->assertJson(['ok' => true]);

        $row = DB::table('events')->where('event_code', 'pr44_payload_limit')->latest('created_at')->first();
        $this->assertNotNull($row);

        $meta = $row->meta_json ?? null;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('long', $meta);
        $this->assertArrayHasKey('list', $meta);
        $this->assertSame(10, mb_strlen((string) $meta['long'], 'UTF-8'));
        $this->assertCount(3, $meta['list']);
        $this->assertSame([], data_get($meta, 'deep.inner.tooDeep'));
    }
}
