<?php

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

class WebhookProviderMismatchTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_billing_webhook_is_ignored_when_order_provider_is_stripe(): void
    {
        $orderNo = 'ord_provider_mismatch_1';

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

        $response = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_provider_mismatch_1',
            'order_no' => $orderNo,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'external_trade_no' => 'trade_provider_mismatch_1',
            'event_type' => 'payment_succeeded',
        ], [
            'X-Org-Id' => '0',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'ignored' => true,
            'order_no' => $orderNo,
            'provider_event_id' => 'evt_provider_mismatch_1',
        ]);

        $this->assertSame('created', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));
        $this->assertSame('stripe', (string) DB::table('orders')->where('order_no', $orderNo)->value('provider'));
        $this->assertSame('rejected', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_provider_mismatch_1')
            ->value('status'));
        $this->assertSame('PROVIDER_MISMATCH', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_provider_mismatch_1')
            ->value('reason'));
        $this->assertSame(0, DB::table('benefit_grants')->count());
        $this->assertSame(0, DB::table('report_snapshots')->count());
    }
}
