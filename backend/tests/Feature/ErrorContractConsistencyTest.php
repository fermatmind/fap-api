<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ErrorContractConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_v03_payment_webhook_invalid_signature_returns_unified_error_contract(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_error_contract_test',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $raw = json_encode([
            'id' => 'evt_contract_invalid_sig',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_contract_invalid_sig',
                    'metadata' => ['order_no' => 'ord_contract_invalid_sig'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($raw)) {
            self::fail('Failed to encode webhook payload.');
        }

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $raw
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
        $this->assertUnifiedErrorContract($response);
    }

    public function test_v03_attempt_not_found_returns_resource_not_found_contract(): void
    {
        $attemptId = (string) Str::uuid();

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_contract_attempt_missing',
        ])->getJson("/api/v0.3/attempts/{$attemptId}");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $this->assertUnifiedErrorContract($response);
    }

    private function assertUnifiedErrorContract(TestResponse $response): void
    {
        $response->assertJsonPath('ok', false);
        $response->assertJsonMissingPath('error');

        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayNotHasKey('error', $json);

        $errorCode = (string) $response->json('error_code', '');
        $message = (string) $response->json('message', '');

        $this->assertNotSame('', trim($errorCode));
        $this->assertNotSame('', trim($message));
    }
}
