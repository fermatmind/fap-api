<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RevokeSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_revoke_requires_fm_token_auth(): void
    {
        $response = $this->postJson('/api/v0.2/integrations/mock/revoke');

        $response->assertStatus(401)->assertJson([
            'ok' => false,
        ]);
    }

    public function test_revoke_rejects_token_without_bound_user_identity(): void
    {
        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => 'revoke-anon-only',
            'org_id' => 0,
            'role' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedIntegrationRecord(null, 'mock', 'connected');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/integrations/mock/revoke');

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
            'message' => 'missing_identity',
        ]);

        $row = DB::table('integrations')
            ->whereNull('user_id')
            ->where('provider', 'mock')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('connected', (string) $row->status);
        $this->assertNull($row->revoked_at);
    }

    public function test_revoke_updates_only_authenticated_user_record(): void
    {
        $this->seedUser(1001);
        $this->seedUser(1002);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => 1001,
            'anon_id' => 'revoke-anon-1001',
            'org_id' => 0,
            'role' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedIntegrationRecord(1001, 'mock', 'connected');
        $this->seedIntegrationRecord(1002, 'mock', 'connected');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/integrations/mock/revoke');

        $response->assertStatus(200)->assertJson([
            'ok' => true,
            'provider' => 'mock',
            'user_id' => '1001',
        ]);

        $mine = DB::table('integrations')
            ->where('user_id', 1001)
            ->where('provider', 'mock')
            ->first();
        $other = DB::table('integrations')
            ->where('user_id', 1002)
            ->where('provider', 'mock')
            ->first();

        $this->assertNotNull($mine);
        $this->assertSame('revoked', (string) $mine->status);
        $this->assertNotNull($mine->revoked_at);

        $this->assertNotNull($other);
        $this->assertSame('connected', (string) $other->status);
        $this->assertNull($other->revoked_at);
    }

    public function test_revoke_returns_404_for_unsupported_provider(): void
    {
        $this->seedUser(1101);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => 1101,
            'anon_id' => 'revoke-anon-1101',
            'org_id' => 0,
            'role' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedIntegrationRecord(1101, 'mock', 'connected');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v0.2/integrations/not_supported/revoke');

        $response->assertStatus(404)->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);

        $existing = DB::table('integrations')
            ->where('user_id', 1101)
            ->where('provider', 'mock')
            ->first();

        $this->assertNotNull($existing);
        $this->assertSame('connected', (string) $existing->status);
    }

    private function seedUser(int $userId): void
    {
        DB::table('users')->insert([
            'id' => $userId,
            'name' => "revoke-user-{$userId}",
            'email' => "revoke-user-{$userId}@example.com",
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedIntegrationRecord(?int $userId, string $provider, string $status): void
    {
        DB::table('integrations')->insert([
            'user_id' => $userId,
            'provider' => $provider,
            'external_user_id' => $userId !== null ? "ext_{$userId}" : 'ext_null',
            'status' => $status,
            'scopes_json' => json_encode(['mock_scope'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'consent_version' => 'v0.1',
            'connected_at' => now(),
            'revoked_at' => null,
            'ingest_key_hash' => hash('sha256', "{$provider}|{$userId}|".Str::uuid()),
            'webhook_last_event_id' => null,
            'webhook_last_timestamp' => null,
            'webhook_last_received_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
