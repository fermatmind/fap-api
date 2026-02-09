<?php

namespace Tests\Feature\Commerce;

use App\Services\Commerce\PaymentWebhookProcessor;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentWebhookTrustBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrder(string $orderNo): void
    {
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
    }

    public function test_processor_rejects_invalid_signature_before_any_state_transition(): void
    {
        (new Pr19CommerceSeeder())->run();
        $orderNo = 'ord_trust_sig_1';
        $this->seedOrder($orderNo);

        $processor = app(PaymentWebhookProcessor::class);
        $result = $processor->handle('billing', [
            'provider_event_id' => 'evt_trust_sig_1',
            'order_no' => $orderNo,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'event_type' => 'payment_succeeded',
        ], 0, null, null, false);

        $this->assertFalse($result['ok']);
        $this->assertSame(404, (int) ($result['status'] ?? 0));
        $this->assertSame('NOT_FOUND', (string) ($result['error'] ?? ''));
        $this->assertSame('created', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));
        $this->assertSame('rejected', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_trust_sig_1')
            ->value('status'));
        $this->assertSame('SIGNATURE_INVALID', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_trust_sig_1')
            ->value('last_error_code'));
        $this->assertSame(0, DB::table('benefit_wallet_ledgers')->count());
    }

    public function test_processor_rejects_amount_mismatch(): void
    {
        (new Pr19CommerceSeeder())->run();
        $orderNo = 'ord_trust_amt_1';
        $this->seedOrder($orderNo);

        $processor = app(PaymentWebhookProcessor::class);
        $result = $processor->handle('billing', [
            'provider_event_id' => 'evt_trust_amt_1',
            'order_no' => $orderNo,
            'amount_cents' => 1,
            'currency' => 'USD',
            'event_type' => 'payment_succeeded',
        ], 0, null, null, true);

        $this->assertFalse($result['ok']);
        $this->assertSame(404, (int) ($result['status'] ?? 0));
        $this->assertSame('created', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));
        $this->assertSame('AMOUNT_MISMATCH', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_trust_amt_1')
            ->value('last_error_code'));
    }

    public function test_processor_rejects_event_type_outside_whitelist(): void
    {
        (new Pr19CommerceSeeder())->run();
        $orderNo = 'ord_trust_evt_1';
        $this->seedOrder($orderNo);

        $processor = app(PaymentWebhookProcessor::class);
        $result = $processor->handle('billing', [
            'provider_event_id' => 'evt_trust_evt_1',
            'order_no' => $orderNo,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'event_type' => 'payment_failed',
        ], 0, null, null, true);

        $this->assertFalse($result['ok']);
        $this->assertSame(404, (int) ($result['status'] ?? 0));
        $this->assertSame('EVENT_TYPE_NOT_ALLOWED', (string) DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_trust_evt_1')
            ->value('last_error_code'));
        $this->assertSame('created', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));
    }
}
