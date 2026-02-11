<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LegacyMbtiSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_true(): void
    {
        $response = $this->getJson('/api/v0.2/health');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
    }

    public function test_start_attempt_validation_uses_unified_error_contract(): void
    {
        $response = $this->postJson('/api/v0.2/attempts/start', []);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $response->assertJsonMissingPath('error');

        $this->assertNotSame('', (string) $response->json('message', ''));
        $this->assertIsArray($response->json('details'));
        $this->assertNotSame('', (string) $response->json('request_id', ''));
    }
}
