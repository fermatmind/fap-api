<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Commerce\PaymentWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WebhookStatusPropagationTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    #[DataProvider('errorStatuses')]
    public function test_controller_uses_processor_status_for_error_responses(int $status): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_sec002_status_propagation',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')
            ->once()
            ->andReturn([
                'ok' => false,
                'error' => 'X',
                'status' => $status,
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->encodePayload([
            'id' => "evt_status_{$status}",
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => "pi_status_{$status}",
                    'metadata' => ['order_no' => "ord_status_{$status}"],
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
                'HTTP_STRIPE_SIGNATURE' => $this->buildStripeSignatureHeader('whsec_sec002_status_propagation', $raw),
            ],
            $raw
        );

        $response->assertStatus($status);
    }

    public static function errorStatuses(): array
    {
        return [
            [400],
            [404],
            [413],
            [500],
        ];
    }

    public function test_controller_falls_back_to_200_when_processor_status_is_out_of_range(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_sec002_status_fallback',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')
            ->once()
            ->andReturn([
                'ok' => false,
                'error' => 'X',
                'status' => 700,
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->encodePayload([
            'id' => 'evt_status_out_of_range',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_status_out_of_range',
                    'metadata' => ['order_no' => 'ord_status_out_of_range'],
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
                'HTTP_STRIPE_SIGNATURE' => $this->buildStripeSignatureHeader('whsec_sec002_status_fallback', $raw),
            ],
            $raw
        );

        $response->assertStatus(200);
    }

    private function buildStripeSignatureHeader(string $secret, string $raw): string
    {
        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$raw}", $secret);

        return "t={$ts},v1={$sig}";
    }

    private function encodePayload(array $payload): string
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($raw)) {
            self::fail('json_encode payload failed.');
        }

        return $raw;
    }
}
