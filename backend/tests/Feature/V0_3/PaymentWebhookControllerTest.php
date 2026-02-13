<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Commerce\PaymentWebhookProcessor;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class PaymentWebhookControllerTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    public function test_unsigned_webhook_request_is_rejected_without_500(): void
    {
        $response = $this->postJson(
            route('api.v0_3.webhooks.payment', ['provider' => 'stripe']),
            []
        );

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertTrue($response->getStatusCode() >= 400 && $response->getStatusCode() < 500);
    }

    public function test_invalid_json_returns_400_and_processor_not_called(): void
    {
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
            ],
            '{"id":"evt_invalid_json"',
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'INVALID_JSON');
    }

    public function test_invalid_signature_returns_400_and_processor_not_called(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_sec002',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')->never();
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->encodePayload([
            'id' => 'evt_invalid_sig',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_invalid_sig',
                    'metadata' => ['order_no' => 'ord_invalid_sig'],
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
    }

    public function test_missing_event_id_returns_400_and_processor_not_called(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_sec002_missing_event',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')->never();
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->encodePayload([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'metadata' => ['order_no' => 'ord_missing_event'],
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
                'HTTP_STRIPE_SIGNATURE' => $this->buildStripeSignatureHeader('whsec_sec002_missing_event', $raw),
            ],
            $raw
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'MISSING_EVENT_ID');
    }

    public function test_processor_throwable_returns_500(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_sec002_500',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')
            ->once()
            ->andThrow(new \RuntimeException('boom'));
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $raw = $this->encodePayload([
            'id' => 'evt_processor_throws',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_processor_throws',
                    'metadata' => ['order_no' => 'ord_processor_throws'],
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
                'HTTP_STRIPE_SIGNATURE' => $this->buildStripeSignatureHeader('whsec_sec002_500', $raw),
            ],
            $raw
        );

        $response->assertStatus(500);
        $response->assertJsonPath('error_code', 'WEBHOOK_INTERNAL_ERROR');
    }

    public function test_valid_billing_signature_returns_200_and_writes_or_hits_idempotency(): void
    {
        (new Pr19CommerceSeeder())->run();

        config([
            'services.billing.webhook_secret' => 'billing_secret_sec002',
            'services.billing.webhook_tolerance_seconds' => 300,
            'services.billing.allow_legacy_signature' => false,
        ]);

        $orderNo = 'ord_sec002_webhook_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => null,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 4990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        $payload = [
            'provider_event_id' => 'evt_sec002_bill_1',
            'order_no' => $orderNo,
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];
        $raw = $this->encodePayload($payload);

        $first = $this->postSignedBilling($raw, 'billing_secret_sec002');
        $first->assertStatus(200);
        $first->assertJsonPath('ok', true);

        $second = $this->postSignedBilling($raw, 'billing_secret_sec002');
        $second->assertStatus(200);
        $second->assertJsonPath('ok', true);

        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_sec002_bill_1')
            ->count());
    }

    private function postSignedBilling(string $raw, string $secret): TestResponse
    {
        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$raw}", $secret);

        return $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/billing',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $ts,
                'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
            ],
            $raw
        );
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
