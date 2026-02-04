<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_signature_required_and_valid(): void
    {
        (new Pr19CommerceSeeder())->run();

        config(['services.billing.webhook_secret' => 'billing_secret']);

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

        $missing = $this->postJson('/api/v0.3/webhooks/payment/billing', $payload, [
            'X-Org-Id' => '0',
        ]);
        $missing->assertStatus(404);

        $bad = $this->postJson('/api/v0.3/webhooks/payment/billing', $payload, [
            'X-Org-Id' => '0',
            'X-Billing-Signature' => 'bad',
        ]);
        $bad->assertStatus(404);

        $raw = json_encode($payload);
        $signature = hash_hmac('sha256', $raw ?: '', 'billing_secret');

        $ok = $this->postJson('/api/v0.3/webhooks/payment/billing', $payload, [
            'X-Org-Id' => '0',
            'X-Billing-Signature' => $signature,
        ]);
        $ok->assertStatus(200);
        $ok->assertJson([
            'ok' => true,
        ]);
    }
}
