<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiValidationErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_validation_failure_returns_unified_contract(): void
    {
        $response = $this->postJson('/api/v0.3/orders', []);

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
}
