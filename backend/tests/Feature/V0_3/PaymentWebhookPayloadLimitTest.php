<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentWebhookPayloadLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_over_256kb_returns_413_and_writes_no_payment_event(): void
    {
        $eventId = 'evt_payload_limit_413_v03';
        $rawBody = json_encode([
            'id' => $eventId,
            'order_no' => 'ord_payload_limit_413_v03',
            'blob' => str_repeat('x', 270000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($rawBody);
        self::assertGreaterThan(262144, strlen($rawBody));

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
            $rawBody
        );

        $response->assertStatus(413)->assertJson([
            'ok' => false,
            'error_code' => 'PAYLOAD_TOO_LARGE',
            'message' => 'payload too large',
        ]);

        $this->assertSame(0, DB::table('payment_events')->where('provider_event_id', $eventId)->count());
    }

    public function test_small_payload_persists_digest_and_small_summary_json(): void
    {
        (new Pr19CommerceSeeder())->run();
        Storage::fake('s3');
        config([
            'services.stripe.webhook_secret' => 'whsec_payload_limit_v03',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $orderNo = 'ord_payload_limit_ok_v03';
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
            'provider' => 'stripe',
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

        $eventId = 'evt_payload_limit_ok_v03';
        $payload = [
            'id' => $eventId,
            'type' => 'payment_intent.succeeded',
            'order_no' => $orderNo,
            'data' => [
                'object' => [
                    'id' => 'pi_payload_limit_ok_v03',
                    'amount' => 4990,
                    'currency' => 'usd',
                    'metadata' => ['order_no' => $orderNo],
                ],
            ],
            'nested' => [
                'secret' => [
                    'token' => 'sensitive-token-v03',
                    'blob' => str_repeat('y', 512),
                ],
            ],
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($rawBody);
        self::assertLessThan(262144, strlen($rawBody));
        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$rawBody}", 'whsec_payload_limit_v03');

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
            $rawBody
        );

        $response->assertStatus(200)->assertJson([
            'ok' => true,
            'provider_event_id' => $eventId,
            'order_no' => $orderNo,
        ]);

        $row = DB::table('payment_events')
            ->where('provider', 'stripe')
            ->where('provider_event_id', $eventId)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(strlen($rawBody), (int) ($row->payload_size_bytes ?? -1));
        $this->assertSame(hash('sha256', $rawBody), (string) ($row->payload_sha256 ?? ''));

        $payloadJsonRaw = (string) ($row->payload_json ?? '');
        $summary = json_decode($payloadJsonRaw, true);

        $this->assertIsArray($summary);
        $this->assertSame($eventId, $summary['provider_event_id'] ?? null);
        $this->assertSame($orderNo, $summary['order_no'] ?? null);
        $this->assertSame('payment_intent.succeeded', $summary['event_type'] ?? null);
        $this->assertSame(4990, (int) ($summary['amount_cents'] ?? -1));
        $this->assertSame('USD', $summary['currency'] ?? null);
        $this->assertSame('pi_payload_limit_ok_v03', $summary['external_trade_no'] ?? null);
        $this->assertSame(hash('sha256', $rawBody), $summary['raw_sha256'] ?? null);
        $this->assertSame(strlen($rawBody), (int) ($summary['raw_bytes'] ?? -1));

        $this->assertLessThan(4096, strlen($payloadJsonRaw));
        $this->assertStringNotContainsString('sensitive-token-v03', $payloadJsonRaw);
    }
}
