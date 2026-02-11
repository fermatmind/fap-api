<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WebhookPayloadSizeLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_oversized_webhook_payload_returns_413_and_no_payment_event_is_written(): void
    {
        $rawBody = json_encode([
            'id' => 'evt_payload_too_large_1',
            'order_no' => 'ord_payload_too_large_1',
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
        ]);

        $this->assertSame(0, DB::table('payment_events')->count());
    }

    public function test_normal_webhook_writes_payload_forensics_without_full_payload_json(): void
    {
        (new Pr19CommerceSeeder())->run();
        config([
            'services.stripe.webhook_secret' => 'whsec_payload_limit',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $orderNo = 'ord_payload_limit_ok_1';
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

        $eventId = 'evt_payload_limit_ok_1';
        $payload = [
            'id' => $eventId,
            'type' => 'payment_intent.succeeded',
            'order_no' => $orderNo,
            'amount' => 4990,
            'currency' => 'USD',
            'nested' => [
                'secret' => [
                    'token' => 'sensitive-token',
                    'blob' => str_repeat('y', 512),
                ],
            ],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($rawBody);
        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$rawBody}", 'whsec_payload_limit');

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

        $response->assertStatus(200);
        $response->assertJson([
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
        $this->assertSame('', (string) ($row->payload_s3_key ?? ''));

        $payloadJsonRaw = (string) ($row->payload_json ?? '');
        $summary = json_decode($payloadJsonRaw, true);
        $this->assertIsArray($summary);
        $this->assertSame($eventId, $summary['provider_event_id'] ?? null);
        $this->assertSame($orderNo, $summary['order_no'] ?? null);
        $this->assertSame(4990, (int) ($summary['amount_cents'] ?? -1));
        $this->assertSame('USD', $summary['currency'] ?? null);
        $this->assertSame('payment_intent.succeeded', $summary['event_type'] ?? null);
        $this->assertArrayHasKey('raw_sha256', $summary);
        $this->assertArrayHasKey('raw_bytes', $summary);
        $this->assertArrayNotHasKey('nested', $summary);

        $this->assertStringNotContainsString('sensitive-token', $payloadJsonRaw);
        $this->assertStringNotContainsString('sensitive-token', (string) ($row->payload_excerpt ?? ''));
    }
}
