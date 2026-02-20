<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IngestAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_without_auth_and_without_ingest_key_returns_401(): void
    {
        $response = $this->postJson('/api/v0.2/integrations/mock/ingest', $this->payload());

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
    }

    public function test_ingest_with_fm_token_uses_token_user_and_ignores_body_user_id(): void
    {
        DB::table('users')->insert([
            'id' => 1001,
            'name' => 'ingest-auth-user-1001',
            'email' => 'ingest-auth-1001@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => 1001,
            'anon_id' => 'ingest-auth-anon-1001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/integrations/mock/ingest', $this->payload([
            'user_id' => '42',
        ]));

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
        $this->assertSame(0, DB::table('ingest_batches')->count());
        $this->assertSame(0, DB::table('sleep_samples')->count());
    }

    public function test_ingest_with_valid_ingest_key_writes_mapped_user_id(): void
    {
        $ingestKey = 'ingest_key_mock_001';

        DB::table('integrations')->insert([
            'user_id' => 1001,
            'provider' => 'mock',
            'external_user_id' => 'ext_001',
            'ingest_key_hash' => hash('sha256', $ingestKey),
            'status' => 'connected',
            'scopes_json' => json_encode(['mock_scope'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'consent_version' => 'v0.1',
            'connected_at' => now(),
            'revoked_at' => null,
            'webhook_last_event_id' => null,
            'webhook_last_timestamp' => null,
            'webhook_last_received_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->payload([
            'user_id' => '42',
        ]);
        $eventId = 'evt_ingest_001';
        $response = $this->withHeaders([
            'X-Ingest-Key' => $ingestKey,
            'X-Ingest-Event-Id' => $eventId,
        ])->postJson('/api/v0.2/integrations/mock/ingest', $payload);

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
        $this->assertSame(0, DB::table('ingest_batches')->count());
    }

    public function test_ingest_with_invalid_ingest_key_returns_401(): void
    {
        DB::table('integrations')->insert([
            'user_id' => 1001,
            'provider' => 'mock',
            'external_user_id' => 'ext_001',
            'ingest_key_hash' => hash('sha256', 'ingest_key_mock_001'),
            'status' => 'connected',
            'scopes_json' => json_encode(['mock_scope'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'consent_version' => 'v0.1',
            'connected_at' => now(),
            'revoked_at' => null,
            'webhook_last_event_id' => null,
            'webhook_last_timestamp' => null,
            'webhook_last_received_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'X-Ingest-Key' => 'ingest_key_invalid',
            'X-Ingest-Event-Id' => 'evt_bad_key_001',
        ])->postJson('/api/v0.2/integrations/mock/ingest', $this->payload());

        $response->assertStatus(410)->assertJson([
            'ok' => false,
            'error_code' => 'API_VERSION_DEPRECATED',
        ]);
        $this->assertSame(0, DB::table('ingest_batches')->count());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'meta' => [
                'range_start' => '2026-02-10T00:00:00Z',
                'range_end' => '2026-02-10T01:00:00Z',
            ],
            'samples' => [
                [
                    'domain' => 'sleep',
                    'recorded_at' => '2026-02-10T00:00:00Z',
                    'value' => ['duration_minutes' => 420],
                    'external_id' => 'sleep_001',
                ],
            ],
        ], $overrides);
    }
}
