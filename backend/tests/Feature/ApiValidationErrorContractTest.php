<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApiValidationErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_validation_failure_returns_unified_contract(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->issueAnonToken(),
        ])->postJson('/api/v0.3/orders', []);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonPath('message', 'The given data was invalid.');
        $response->assertJsonMissingPath('error');

        $details = $response->json('details.sku');
        $this->assertIsArray($details);
        $this->assertNotEmpty($details);

        $requestId = (string) $response->json('request_id', '');
        $this->assertNotSame('', $requestId);
    }

    private function issueAnonToken(): string
    {
        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => 'contract_anon_' . Str::random(8),
            'org_id' => 0,
            'role' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
