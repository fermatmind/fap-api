<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceOrgIdWriteIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_rejects_client_tenant_identity_fields(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->issueAnonToken(),
        ])->postJson('/api/v0.3/orders', [
            'sku' => 'MBTI_CREDIT',
            'org_id' => 999,
            'user_id' => 'attacker-user',
            'anon_id' => 'attacker-anon',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonMissingPath('error');

        $this->assertIsArray($response->json('details.org_id'));
        $this->assertIsArray($response->json('details.user_id'));
        $this->assertIsArray($response->json('details.anon_id'));
    }

    private function issueAnonToken(): string
    {
        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => 'isolation_anon_' . Str::random(8),
            'org_id' => 0,
            'role' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
