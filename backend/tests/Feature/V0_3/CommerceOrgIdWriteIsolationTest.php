<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommerceOrgIdWriteIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_order_rejects_client_tenant_identity_fields(): void
    {
        $response = $this->postJson('/api/v0.3/orders', [
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
}
