<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Commerce\PaymentWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class PaymentWebhookControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_invalid_signature_returns_400(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_fmb009',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with('stripe', Mockery::type('array'), false)
            ->andReturn([
                'ok' => false,
                'error_code' => 'INVALID_SIGNATURE',
                'message' => 'invalid signature',
                'status' => 400,
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->encode([
            'id' => 'evt_fmb009_invalid',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_fmb009_invalid',
                    'amount' => 4990,
                    'currency' => 'usd',
                    'metadata' => ['order_no' => 'ord_fmb009_invalid'],
                ],
            ],
        ]);

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
        $response->assertJsonMissingPath('error');
    }

    public function test_valid_signature_returns_200(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_fmb009',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->andReturn([
                'ok' => true,
                'provider_event_id' => 'evt_fmb009_valid',
                'order_no' => 'ord_fmb009_valid',
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->encode([
            'id' => 'evt_fmb009_valid',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_fmb009_valid',
                    'amount' => 4990,
                    'currency' => 'usd',
                    'metadata' => ['order_no' => 'ord_fmb009_valid'],
                ],
            ],
        ]);

        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$raw}", 'whsec_fmb009');

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}",
            ],
            $raw
        );

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonMissingPath('error');
    }

    private function encode(array $payload): string
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($raw)) {
            self::fail('json_encode payload failed.');
        }

        return $raw;
    }
}
