<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Commerce\PaymentWebhookProcessor;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class PaymentWebhookStripeSignatureTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_valid_stripe_signature_returns_200_and_calls_processor_once(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_test_valid',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $payload = [
            'provider_event_id' => 'evt_valid_signature',
            'amount_cents' => 199,
            'currency' => 'CNY',
        ];
        $rawBody = $this->encodePayload($payload);
        $signatureHeader = $this->buildStripeSignatureHeader('whsec_test_valid', $rawBody, time());

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')
            ->once()
            ->withArgs(function (
                string $provider,
                array $incomingPayload,
                int $orgId,
                ?string $userId,
                ?string $anonId,
                bool $signatureOk,
                array $payloadMeta
            ) use ($payload, $rawBody): bool {
                return $provider === 'stripe'
                    && $incomingPayload === $payload
                    && $orgId === 0
                    && $userId === null
                    && $anonId === null
                    && $signatureOk === true
                    && ($payloadMeta['size_bytes'] ?? null) === strlen($rawBody)
                    && ($payloadMeta['sha256'] ?? null) === hash('sha256', $rawBody)
                    && array_key_exists('s3_key', $payloadMeta);
            })
            ->andReturn([
                'ok' => true,
                'duplicate' => false,
                'order_no' => null,
                'provider_event_id' => 'evt_valid_signature',
            ]);
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            $rawBody,
        );

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'duplicate' => false,
            'provider_event_id' => 'evt_valid_signature',
        ]);
    }

    public function test_expired_timestamp_returns_404_and_processor_not_called(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_test_expired',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $payload = [
            'provider_event_id' => 'evt_expired_signature',
            'amount_cents' => 299,
            'currency' => 'USD',
        ];
        $rawBody = $this->encodePayload($payload);
        $signatureHeader = $this->buildStripeSignatureHeader(
            'whsec_test_expired',
            $rawBody,
            time() - 301,
        );

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')->never();
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signatureHeader,
            ],
            $rawBody,
        );

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error' => 'NOT_FOUND',
        ]);
    }

    public function test_missing_signature_header_returns_404(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_test_missing',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')->never();
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $rawBody = $this->encodePayload([
            'provider_event_id' => 'evt_missing_header',
            'amount_cents' => 399,
            'currency' => 'USD',
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
            $rawBody,
        );

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error' => 'NOT_FOUND',
        ]);
    }

    private function buildStripeSignatureHeader(string $secret, string $rawBody, int $timestamp): string
    {
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    private function encodePayload(array $payload): string
    {
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($rawBody)) {
            self::fail('json_encode payload failed.');
        }

        return $rawBody;
    }
}
