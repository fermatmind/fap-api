<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\PaymentAttempt;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class PaymentAttemptWebhookBindingTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_billing_webhook_binds_to_existing_open_payment_attempt(): void
    {
        (new Pr19CommerceSeeder)->run();

        $orderId = (string) Str::uuid();
        $orderNo = 'ord_attempt_binding_1';
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_attempt_binding',
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_state' => 'pending',
            'grant_state' => 'not_started',
            'provider' => 'billing',
            'channel' => 'web',
            'provider_app' => null,
            'external_trade_no' => null,
            'provider_trade_no' => null,
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

        $paymentAttemptId = (string) Str::uuid();
        DB::table('payment_attempts')->insert([
            'id' => $paymentAttemptId,
            'org_id' => 0,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'attempt_no' => 1,
            'provider' => 'billing',
            'channel' => 'web',
            'provider_app' => null,
            'pay_scene' => 'desktop',
            'state' => PaymentAttempt::STATE_CLIENT_PRESENTED,
            'external_trade_no' => null,
            'provider_trade_no' => null,
            'provider_session_ref' => null,
            'amount_expected' => 4990,
            'currency' => 'USD',
            'payload_meta_json' => json_encode(['source' => 'test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'latest_payment_event_id' => null,
            'initiated_at' => now()->subMinute(),
            'provider_created_at' => now()->subMinute(),
            'client_presented_at' => now()->subMinute(),
            'callback_received_at' => null,
            'verified_at' => null,
            'finalized_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'meta_json' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $response = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_attempt_binding_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_attempt_binding_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);

        $event = DB::table('payment_events')
            ->where('provider', 'billing')
            ->where('provider_event_id', 'evt_attempt_binding_1')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($paymentAttemptId, (string) ($event->payment_attempt_id ?? ''));

        $attempt = DB::table('payment_attempts')->where('id', $paymentAttemptId)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(PaymentAttempt::STATE_PAID, (string) ($attempt->state ?? ''));
        $this->assertSame('trade_attempt_binding_1', (string) ($attempt->provider_trade_no ?? ''));
        $this->assertSame((string) ($event->id ?? ''), (string) ($attempt->latest_payment_event_id ?? ''));
        $this->assertNotNull($attempt->callback_received_at ?? null);
        $this->assertNotNull($attempt->verified_at ?? null);
    }
}
