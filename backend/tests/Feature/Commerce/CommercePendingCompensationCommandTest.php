<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Commerce\Compensation\Gateways\WechatPayLifecycleGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommercePendingCompensationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_mutate_order_or_attempt_state(): void
    {
        config([
            'pay.wechat.default.mch_id' => 'mch_test',
            'pay.wechat.default.mch_secret_key' => str_repeat('k', 32),
        ]);

        $order = $this->insertPendingWechatOrder('ord_compensate_dry_run_1', 'dry-run@example.com');
        $attempt = $this->insertPaymentAttempt($order->id, (string) $order->order_no, PaymentAttempt::STATE_CLIENT_PRESENTED);

        $this->app->instance(WechatPayLifecycleGateway::class, new class extends WechatPayLifecycleGateway
        {
            protected function dispatchQuery(array $order): array
            {
                return [
                    'trade_state' => 'SUCCESS',
                    'transaction_id' => 'wx_compensate_dry_run_paid',
                    'success_time' => '2026-03-26T12:30:00+08:00',
                ];
            }
        });

        $this->artisan('commerce:compensate-pending-orders', [
            '--provider' => 'wechatpay',
            '--dry-run' => true,
            '--older-than-minutes' => 30,
            '--limit' => 20,
        ])
            ->expectsOutputToContain('candidate_count=1')
            ->expectsOutputToContain('paid_count=1')
            ->assertExitCode(0);

        $this->assertSame(Order::PAYMENT_STATE_PENDING, DB::table('orders')->where('id', $order->id)->value('payment_state'));
        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('last_reconciled_at'));
        $this->assertSame(PaymentAttempt::STATE_CLIENT_PRESENTED, DB::table('payment_attempts')->where('id', $attempt)->value('state'));
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
