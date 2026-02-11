<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingWebhookReplayToleranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_replay_tolerance_contract(): void
    {
        (new Pr19CommerceSeeder())->run();

        config([
            'services.billing.webhook_secret' => 'billing_secret_pr65',
            'services.billing.webhook_tolerance_seconds' => 300,
            'services.billing.allow_legacy_signature' => false,
        ]);

        $orderNo = 'ord_pr65_billing_1';
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
            'provider_event_id' => 'evt_pr65_bill_1',
            'order_no' => $orderNo,
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];
        $rawBody = $this->encodePayload($payload);

        $missingTimestamp = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORG_ID' => '0',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sig_without_timestamp',
        ], $rawBody);
        $missingTimestamp->assertStatus(400);

        $expiredTimestamp = time() - 301;
        $expiredSignature = $this->billingSignature('billing_secret_pr65', $rawBody, $expiredTimestamp);
        $expired = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORG_ID' => '0',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $expiredTimestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => $expiredSignature,
        ], $rawBody);
        $expired->assertStatus(400);

        $now = time();
        $mismatch = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORG_ID' => '0',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $now,
            'HTTP_X_WEBHOOK_SIGNATURE' => 'bad_signature',
        ], $rawBody);
        $mismatch->assertStatus(400);

        $validSignature = $this->billingSignature('billing_secret_pr65', $rawBody, $now);
        $ok = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORG_ID' => '0',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $now,
            'HTTP_X_WEBHOOK_SIGNATURE' => $validSignature,
        ], $rawBody);

        $ok->assertStatus(200);
        $ok->assertJson(['ok' => true]);
    }

    private function billingSignature(string $secret, string $rawBody, int $timestamp): string
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
