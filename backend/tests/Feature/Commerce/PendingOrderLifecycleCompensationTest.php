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

final class PendingOrderLifecycleCompensationTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_confirmed_paid_writes_paid_order_and_attempt(): void
    {
        config([
            'pay.wechat.default.mch_id' => 'mch_test',
            'pay.wechat.default.mch_secret_key' => str_repeat('k', 32),
        ]);

        $order = $this->insertPendingWechatOrder('ord_lifecycle_paid_1', 'paid@example.com');
        $attemptId = $this->insertPaymentAttempt($order->id, (string) $order->order_no, PaymentAttempt::STATE_CLIENT_PRESENTED);

        $this->app->instance(WechatPayLifecycleGateway::class, new class extends WechatPayLifecycleGateway
        {
            protected function dispatchQuery(array $order): array
            {
                return [
                    'trade_state' => 'SUCCESS',
                    'transaction_id' => 'wx_lifecycle_paid_1',
                    'success_time' => '2026-03-26T13:00:00+08:00',
                ];
            }
        });

        $summary = app(PendingOrderCompensationService::class)->compensate([
            'provider' => 'wechatpay',
            'older_than_minutes' => 30,
            'limit' => 20,
        ]);

        $this->assertSame(1, $summary['paid_count']);
        $this->assertSame(Order::PAYMENT_STATE_PAID, DB::table('orders')->where('id', $order->id)->value('payment_state'));
        $this->assertNotNull(DB::table('orders')->where('id', $order->id)->value('paid_at'));
        $this->assertNotNull(DB::table('orders')->where('id', $order->id)->value('last_reconciled_at'));
        $this->assertSame(PaymentAttempt::STATE_PAID, DB::table('payment_attempts')->where('id', $attemptId)->value('state'));
        $this->assertNotNull(DB::table('payment_attempts')->where('id', $attemptId)->value('verified_at'));
        $this->assertNotNull(DB::table('payment_attempts')->where('id', $attemptId)->value('finalized_at'));
    }

    public function test_close_expired_marks_order_and_attempt_expired(): void
    {
        config([
            'pay.wechat.default.mch_id' => 'mch_test',
            'pay.wechat.default.mch_secret_key' => str_repeat('k', 32),
        ]);

        $order = $this->insertPendingWechatOrder('ord_lifecycle_expired_1', 'expired@example.com');
        $attemptId = $this->insertPaymentAttempt($order->id, (string) $order->order_no, PaymentAttempt::STATE_PROVIDER_CREATED);

        $this->app->instance(WechatPayLifecycleGateway::class, new class extends WechatPayLifecycleGateway
        {
            protected function dispatchQuery(array $order): array
            {
                return [
                    'trade_state' => 'NOTPAY',
                ];
            }

            protected function dispatchClose(array $order): array
            {
                return [
                    'trade_state' => 'CLOSED',
                    'transaction_id' => 'wx_lifecycle_closed_1',
                ];
            }
        });

        $summary = app(PendingOrderCompensationService::class)->compensate([
            'provider' => 'wechatpay',
            'older_than_minutes' => 30,
            'limit' => 20,
            'close_expired' => true,
        ]);

        $this->assertSame(1, $summary['expired_count']);
        $this->assertSame(1, $summary['close_attempted_count']);
        $this->assertSame(1, $summary['close_success_count']);
        $this->assertSame(Order::PAYMENT_STATE_EXPIRED, DB::table('orders')->where('id', $order->id)->value('payment_state'));
        $this->assertSame('expired', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertNotNull(DB::table('orders')->where('id', $order->id)->value('expired_at'));
        $this->assertNotNull(DB::table('orders')->where('id', $order->id)->value('closed_at'));
        $this->assertSame(PaymentAttempt::STATE_EXPIRED, DB::table('payment_attempts')->where('id', $attemptId)->value('state'));
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

    private function insertPaymentAttempt(string $orderId, string $orderNo, string $state): string
    {
        $attemptId = (string) Str::uuid();
        $timestamp = now()->subHours(2);

        DB::table('payment_attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'attempt_no' => 1,
            'provider' => 'wechatpay',
            'channel' => 'wechat_miniapp',
            'provider_app' => 'wx-miniapp-primary',
            'pay_scene' => 'miniapp',
            'state' => $state,
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

        return $attemptId;
    }
}
