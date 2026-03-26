<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Commerce\Compensation\Gateways\WechatPayLifecycleGateway;
use App\Services\Commerce\Compensation\PendingOrderCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaidNoGrantCompensationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_compensation_surfaces_paid_but_not_granted_order_detail(): void
    {
        config([
            'pay.wechat.default.mch_id' => 'mch_test',
            'pay.wechat.default.mch_secret_key' => str_repeat('k', 32),
        ]);

        $email = 'paid-no-grant@example.com';
        $order = $this->insertPendingWechatOrder('ord_paid_no_grant_1', $email);
        $this->insertPaymentAttempt($order->id, (string) $order->order_no);

        $this->app->instance(WechatPayLifecycleGateway::class, new class extends WechatPayLifecycleGateway
        {
            protected function dispatchQuery(array $order): array
            {
                return [
                    'trade_state' => 'SUCCESS',
                    'transaction_id' => 'wx_paid_no_grant_1',
                    'success_time' => '2026-03-26T14:00:00+08:00',
                ];
            }
        });

        app(PendingOrderCompensationService::class)->compensate([
            'provider' => 'wechatpay',
            'older_than_minutes' => 30,
            'limit' => 20,
        ]);

        $freshOrder = DB::table('orders')->where('id', $order->id)->first();
        $this->assertSame(Order::PAYMENT_STATE_PAID, (string) ($freshOrder->payment_state ?? ''));
        $this->assertSame(Order::GRANT_STATE_NOT_STARTED, (string) ($freshOrder->grant_state ?? ''));
        $this->assertNotNull($freshOrder->last_reconciled_at ?? null);

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $order->order_no,
            'email' => $email,
        ]);

        $response->assertOk();
        $response->assertJsonPath('payment_state', 'paid');
        $response->assertJsonPath('grant_state', 'not_started');
        $response->assertJsonPath('last_reconciled_at', (string) $freshOrder->last_reconciled_at);
    }

    private function insertPendingWechatOrder(string $orderNo, string $email): object
    {
        $orderId = (string) Str::uuid();
        $now = now()->subHours(2);

        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_'.$orderNo,
            'contact_email_hash' => hash('sha256', $email),
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 299,
            'amount_total' => 299,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => Order::STATUS_PENDING,
            'payment_state' => Order::PAYMENT_STATE_PENDING,
            'grant_state' => Order::GRANT_STATE_NOT_STARTED,
            'provider' => 'wechatpay',
            'channel' => 'wechat_miniapp',
            'provider_app' => 'wx-miniapp-primary',
            'item_sku' => 'MBTI_REPORT_FULL',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'external_trade_no' => 'wx_'.$orderNo,
            'provider_trade_no' => null,
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'last_payment_event_at' => null,
            'last_reconciled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('orders')->where('id', $orderId)->first();
    }

    private function insertPaymentAttempt(string $orderId, string $orderNo): void
    {
        $timestamp = now()->subHours(2);

        DB::table('payment_attempts')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'attempt_no' => 1,
            'provider' => 'wechatpay',
            'channel' => 'wechat_miniapp',
            'provider_app' => 'wx-miniapp-primary',
            'pay_scene' => 'miniapp',
            'state' => PaymentAttempt::STATE_CLIENT_PRESENTED,
            'external_trade_no' => 'wx_'.$orderNo,
            'provider_trade_no' => null,
            'provider_session_ref' => null,
            'amount_expected' => 299,
            'currency' => 'CNY',
            'payload_meta_json' => json_encode(['source' => 'test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'latest_payment_event_id' => null,
            'initiated_at' => $timestamp,
            'provider_created_at' => $timestamp,
            'client_presented_at' => $timestamp,
            'callback_received_at' => null,
            'verified_at' => null,
            'finalized_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'meta_json' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
