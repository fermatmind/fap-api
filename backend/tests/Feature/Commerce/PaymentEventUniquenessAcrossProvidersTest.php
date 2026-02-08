<?php

namespace Tests\Feature\Commerce;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentEventUniquenessAcrossProvidersTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_provider_event_id_can_exist_across_providers_and_duplicate_path_stays_provider_scoped(): void
    {
        (new Pr19CommerceSeeder())->run();

        config([
            'services.stripe.webhook_secret' => '',
        ]);

        $sharedEventId = 'evt_pr65_shared';

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => 'ord_pr65_stub',
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => null,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'stub',
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

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => 'ord_pr65_stripe',
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

        $stubFirst = $this->postJson('/api/v0.3/webhooks/payment/stub', [
            'provider_event_id' => $sharedEventId,
            'order_no' => 'ord_pr65_stub',
            'external_trade_no' => 'trade_pr65_stub',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ], [
            'X-Org-Id' => '0',
        ]);
        $stubFirst->assertStatus(200);
        $stubFirst->assertJson(['ok' => true]);

        $stripeFirst = $this->postJson('/api/v0.3/webhooks/payment/stripe', [
            'id' => $sharedEventId,
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_pr65_stripe',
                    'amount' => 4990,
                    'currency' => 'usd',
                    'created' => time(),
                    'metadata' => [
                        'order_no' => 'ord_pr65_stripe',
                    ],
                ],
            ],
        ], [
            'X-Org-Id' => '0',
        ]);
        $stripeFirst->assertStatus(200);
        $stripeFirst->assertJson(['ok' => true]);

        $stubDuplicate = $this->postJson('/api/v0.3/webhooks/payment/stub', [
            'provider_event_id' => $sharedEventId,
            'order_no' => 'ord_pr65_stub',
            'external_trade_no' => 'trade_pr65_stub',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ], [
            'X-Org-Id' => '0',
        ]);
        $stubDuplicate->assertStatus(200);
        $stubDuplicate->assertJson([
            'ok' => true,
            'duplicate' => true,
        ]);

        $this->assertSame(2, DB::table('payment_events')
            ->where('provider_event_id', $sharedEventId)
            ->count());
        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'stub')
            ->where('provider_event_id', $sharedEventId)
            ->count());
        $this->assertSame(1, DB::table('payment_events')
            ->where('provider', 'stripe')
            ->where('provider_event_id', $sharedEventId)
            ->count());
    }
}
