<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Commerce\PaymentWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class LemonSqueezyWebhookSignatureTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_invalid_signature_returns_400(): void
    {
        config([
            'payments.providers.lemonsqueezy.enabled' => true,
            'services.lemonsqueezy.webhook_secret' => 'ls_whsec_test',
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with('lemonsqueezy', Mockery::type('array'), false)
            ->andReturn([
                'ok' => false,
                'error_code' => 'INVALID_SIGNATURE',
                'message' => 'invalid signature',
                'status' => 400,
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->encodePayload([
            'meta' => [
                'event_name' => 'order_created',
                'custom_data' => [
                    'order_no' => 'ord_ls_invalid',
                    'amount_cents' => 4990,
                    'currency' => 'USD',
                ],
            ],
            'data' => [
                'id' => '10001',
                'attributes' => [
                    'currency' => 'USD',
                ],
            ],
        ]);

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/lemonsqueezy',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => 'invalid',
            ],
            $raw
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
    }

    public function test_valid_signature_returns_200(): void
    {
        config([
            'payments.providers.lemonsqueezy.enabled' => true,
            'services.lemonsqueezy.webhook_secret' => 'ls_whsec_test_valid',
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with('lemonsqueezy', Mockery::type('array'), true)
            ->andReturn([
                'ok' => true,
                'provider_event_id' => 'order_created:10002',
                'order_no' => 'ord_ls_valid',
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->encodePayload([
            'meta' => [
                'event_name' => 'order_created',
                'custom_data' => [
                    'order_no' => 'ord_ls_valid',
                    'amount_cents' => 4990,
                    'currency' => 'USD',
                ],
            ],
            'data' => [
                'id' => '10002',
                'attributes' => [
                    'currency' => 'USD',
                ],
            ],
        ]);

        $signature = hash_hmac('sha256', $raw, 'ls_whsec_test_valid');

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/lemonsqueezy',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => $signature,
            ],
            $raw
        );

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('order_no', 'ord_ls_valid');
    }

    private function encodePayload(array $payload): string
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($raw)) {
            self::fail('json_encode payload failed.');
        }

        return $raw;
    }
}
