<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_signature_requires_timestamp_and_within_tolerance(): void
    {
        (new Pr19CommerceSeeder())->run();
        Log::spy();

        config([
            'services.billing.webhook_secret' => 'billing_secret',
            'services.billing.webhook_tolerance_seconds' => 300,
            'services.billing.allow_legacy_signature' => false,
        ]);

        $orderNo = 'ord_billing_1';
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
            'provider_event_id' => 'evt_bill_1',
            'order_no' => $orderNo,
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];
        $raw = $this->encodePayload($payload);

        $missingTimestampSignature = hash_hmac('sha256', $raw, 'billing_secret');
        $missingTimestamp = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORG_ID' => '0',
            'HTTP_X_WEBHOOK_SIGNATURE' => $missingTimestampSignature,
        ], $raw);
        $missingTimestamp->assertStatus(400);
        $this->assertSame(0, DB::table('payment_events')->count());

        $expiredTs = time() - 301;
        $expiredSig = $this->buildSignature('billing_secret', $raw, $expiredTs);
        $expired = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORG_ID' => '0',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $expiredTs,
            'HTTP_X_WEBHOOK_SIGNATURE' => $expiredSig,
        ], $raw);
        $expired->assertStatus(400);
        $this->assertSame(0, DB::table('payment_events')->count());

        $nowTs = time();
        $bad = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORG_ID' => '0',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $nowTs,
            'HTTP_X_WEBHOOK_SIGNATURE' => 'bad',
        ], $raw);
        $bad->assertStatus(400);
        $this->assertSame(0, DB::table('payment_events')->count());

        $okSig = $this->buildSignature('billing_secret', $raw, $nowTs);
        $ok = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORG_ID' => '0',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $nowTs,
            'HTTP_X_WEBHOOK_SIGNATURE' => $okSig,
        ], $raw);

        $ok->assertStatus(200);
        $ok->assertJson(['ok' => true]);
        $this->assertSame(1, DB::table('payment_events')->where('provider_event_id', 'evt_bill_1')->count());

        Log::shouldNotHaveReceived('error');
    }

    public function test_legacy_payload_only_signature_is_rejected_even_when_flag_enabled(): void
    {
        (new Pr19CommerceSeeder())->run();

        config([
            'services.billing.webhook_secret' => 'billing_secret_legacy_blocked',
            'services.billing.webhook_tolerance_seconds' => 300,
            'services.billing.allow_legacy_signature' => true,
        ]);

        $orderNo = 'ord_billing_legacy_blocked';
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
            'provider_event_id' => 'evt_bill_legacy_blocked',
            'order_no' => $orderNo,
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];
        $raw = $this->encodePayload($payload);

        $legacySignature = hash_hmac('sha256', $raw, 'billing_secret_legacy_blocked');
        $response = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORG_ID' => '0',
            'HTTP_X_WEBHOOK_SIGNATURE' => $legacySignature,
        ], $raw);

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
        $this->assertSame(0, DB::table('payment_events')->count());
    }

    private function buildSignature(string $secret, string $rawBody, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
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
