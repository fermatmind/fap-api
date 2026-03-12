<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
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
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

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
        $processor->shouldReceive('process')
            ->once()
            ->with('stripe', [], false)
            ->andReturn([
                'ok' => false,
                'error_code' => 'PAYLOAD_INVALID',
                'message' => 'provider_event_id and order_no are required.',
                'status' => 400,
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
            ],
            '{"id":"evt_invalid_json"',
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'PAYLOAD_INVALID');
        $response->assertJsonMissingPath('error');
        $this->assertNoLegacyErrorKey($response);
    }

    public function test_invalid_signature_returns_400_and_processor_not_called(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_sec002',
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
        $response->assertJsonMissingPath('error');
        $this->assertNoLegacyErrorKey($response);
    }

    public function test_missing_event_id_returns_400_from_processor_contract(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_sec002_missing_event',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with('stripe', Mockery::type('array'), true)
            ->andReturn([
                'ok' => false,
                'error_code' => 'PAYLOAD_INVALID',
                'message' => 'provider_event_id and order_no are required.',
                'status' => 400,
            ]);
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
        $response->assertJsonPath('error_code', 'PAYLOAD_INVALID');
        $response->assertJsonMissingPath('error');
        $this->assertNoLegacyErrorKey($response);
    }

    public function test_processor_throwable_returns_500(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_sec002_500',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('process')
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
        $response->assertJsonMissingPath('error');
        $this->assertNoLegacyErrorKey($response);
    }

    public function test_valid_billing_signature_returns_200_and_writes_or_hits_idempotency(): void
    {
        (new Pr19CommerceSeeder)->run();

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
        $first->assertJsonMissingPath('error');
        $this->assertNoLegacyErrorKey($first);

        $second = $this->postSignedBilling($raw, 'billing_secret_sec002');
        $second->assertStatus(200);
        $second->assertJsonPath('ok', true);
        $second->assertJsonMissingPath('error');
        $this->assertNoLegacyErrorKey($second);

        $this->assertSame('fulfilled', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));
        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_sec002_bill_1')
            ->count());
        $this->assertSame(0, DB::table('email_outbox')->count());
    }

    public function test_valid_billing_signature_queues_payment_success_email_when_delivery_context_exists(): void
    {
        (new Pr19CommerceSeeder)->run();

        config([
            'services.billing.webhook_secret' => 'billing_secret_email_queue',
            'services.billing.webhook_tolerance_seconds' => 300,
            'services.billing.allow_legacy_signature' => false,
        ]);

        $userId = random_int(100000, 999999);
        $attemptId = (string) Str::uuid();
        $orderNo = 'ord_sec002_email_queue_1';

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Webhook Email User',
            'email' => 'webhook-queue@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Attempt::query()->create([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'user_id' => (string) $userId,
            'anon_id' => 'anon_webhook_email_queue',
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'en',
            'question_count' => 120,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
        ]);

        Result::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => ['domains_mean' => ['O' => 3.0, 'C' => 3.0, 'E' => 3.0, 'A' => 3.0, 'N' => 3.0]],
            'scores_pct' => ['O' => 50, 'C' => 50, 'E' => 50, 'A' => 50, 'N' => 50],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'normed_json' => [
                    'norms' => ['status' => 'CALIBRATED'],
                    'quality' => ['level' => 'A'],
                ],
            ],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => (string) $userId,
            'anon_id' => 'anon_webhook_email_queue',
            'sku' => 'SKU_BIG5_FULL_REPORT_299',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 299,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 299,
            'amount_refunded' => 0,
            'item_sku' => 'SKU_BIG5_FULL_REPORT_299',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'meta_json' => json_encode([
                'attribution' => [
                    'share_id' => 'share_webhook_queue',
                    'utm' => [
                        'source' => 'share',
                        'medium' => 'organic',
                        'campaign' => 'pr09c',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $payload = [
            'provider_event_id' => 'evt_sec002_bill_email_queue',
            'order_no' => $orderNo,
            'amount_cents' => 299,
            'currency' => 'CNY',
        ];
        $raw = $this->encodePayload($payload);

        $response = $this->postSignedBilling($raw, 'billing_secret_email_queue');
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonMissingPath('error');
        $this->assertNoLegacyErrorKey($response);

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'payment_success')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('pending', (string) ($row->status ?? ''));
        $this->assertSame('share_webhook_queue', (string) data_get(json_decode((string) ($row->payload_json ?? '{}'), true), 'attribution.share_id'));
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
        if (! is_string($raw)) {
            self::fail('json_encode payload failed.');
        }

        return $raw;
    }

    private function assertNoLegacyErrorKey(TestResponse $response): void
    {
        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayNotHasKey('error', $json);
    }
}
