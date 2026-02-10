<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

class CommerceWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_webhook_idempotent_topup(): void
    {
        (new Pr19CommerceSeeder())->run();

        $orgId = 0;
        $orderNo = 'ord_test_1';

        DB::table('orders')->insert([
            'id' => '00000000-0000-0000-0000-000000000001',
            'order_no' => $orderNo,
            'org_id' => $orgId,
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
            'provider_event_id' => 'evt_123',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];

        for ($i = 0; $i < 10; $i++) {
            $res = $this->postSignedBillingWebhook($payload);
            $res->assertStatus(200);
            $res->assertJson([
                'ok' => true,
            ]);
        }

        $this->assertSame(1, DB::table('payment_events')->count());
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'topup')->count());
        $wallet = DB::table('benefit_wallets')->where('org_id', $orgId)->where('benefit_code', 'MBTI_CREDIT')->first();
        $this->assertNotNull($wallet);
        $this->assertSame(100, (int) ($wallet->balance ?? 0));
    }
}
