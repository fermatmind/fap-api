<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderLedgerStateContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_manager_initializes_split_order_ledger_fields(): void
    {
        $attemptId = $this->createAttempt('anon_order_ledger_init', 'web');
        $this->seedSku('MBTI_REPORT_FULL', 'MBTI_REPORT_FULL');

        $created = app(OrderManager::class)->createOrder(
            0,
            null,
            'anon_order_ledger_init',
            'MBTI_REPORT_FULL',
            1,
            $attemptId,
            'billing',
            null,
            'order-ledger-init@example.com',
            null,
            [],
            [],
            [
                'channel' => 'wechat_miniapp',
                'provider_app' => 'wx-miniapp-primary',
            ]
        );

        $this->assertTrue((bool) ($created['ok'] ?? false));

        $order = DB::table('orders')
            ->where('order_no', (string) ($created['order_no'] ?? ''))
            ->first();

        $this->assertNotNull($order);
        $this->assertSame('created', (string) ($order->status ?? ''));
        $this->assertSame('created', (string) ($order->payment_state ?? ''));
        $this->assertSame('not_started', (string) ($order->grant_state ?? ''));
        $this->assertSame('wechat_miniapp', (string) ($order->channel ?? ''));
        $this->assertSame('wx-miniapp-primary', (string) ($order->provider_app ?? ''));
        $this->assertSame('anon:anon_order_ledger_init', (string) ($order->external_user_ref ?? ''));
    }

    public function test_order_ledger_tracks_pending_paid_and_granted_separately(): void
    {
        $attemptId = $this->createAttempt('anon_order_ledger_flow', 'web');
        $this->seedSku('MBTI_REPORT_FULL', 'MBTI_REPORT_FULL');

        $created = app(OrderManager::class)->createOrder(
            0,
            null,
            'anon_order_ledger_flow',
            'MBTI_REPORT_FULL',
            1,
            $attemptId,
            'billing',
            null,
            'order-ledger-flow@example.com',
            null,
            [],
            [],
            [
                'channel' => 'web',
            ]
        );

        $orderNo = (string) ($created['order_no'] ?? '');
        $this->assertNotSame('', $orderNo);

        app(OrderManager::class)->markPaymentPending($orderNo, 0, 'web', null);

        $pending = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($pending);
        $this->assertSame('pending', (string) ($pending->status ?? ''));
        $this->assertSame('pending', (string) ($pending->payment_state ?? ''));
        $this->assertSame('not_started', (string) ($pending->grant_state ?? ''));

        $deliveryBeforePaid = app(OrderManager::class)->presentOrderDelivery($pending);
        $this->assertFalse((bool) data_get($deliveryBeforePaid, 'delivery.can_view_report'));

        $paid = app(OrderManager::class)->transitionToPaidAtomic(
            $orderNo,
            0,
            'trade_order_ledger_flow_1',
            now()->toIso8601String()
        );

        $this->assertTrue((bool) ($paid['ok'] ?? false));

        $paidOrder = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($paidOrder);
        $this->assertSame('paid', (string) ($paidOrder->payment_state ?? ''));
        $this->assertSame('not_started', (string) ($paidOrder->grant_state ?? ''));
        $this->assertSame('trade_order_ledger_flow_1', (string) ($paidOrder->provider_trade_no ?? ''));

        $deliveryAfterPaid = app(OrderManager::class)->presentOrderDelivery($paidOrder);
        $this->assertFalse((bool) data_get($deliveryAfterPaid, 'delivery.can_view_report'));

        $grant = app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            'anon_order_ledger_flow',
            'MBTI_REPORT_FULL',
            $attemptId,
            $orderNo
        );

        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $fulfilled = app(OrderManager::class)->transition($orderNo, 'fulfilled', 0);
        $this->assertTrue((bool) ($fulfilled['ok'] ?? false));

        $fulfilledOrder = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($fulfilledOrder);
        $this->assertSame('fulfilled', (string) ($fulfilledOrder->status ?? ''));
        $this->assertSame('paid', (string) ($fulfilledOrder->payment_state ?? ''));
        $this->assertSame('granted', (string) ($fulfilledOrder->grant_state ?? ''));

        $deliveryAfterGrant = app(OrderManager::class)->presentOrderDelivery($fulfilledOrder);
        $this->assertTrue((bool) data_get($deliveryAfterGrant, 'delivery.can_view_report'));
    }

    private function createAttempt(string $anonId, string $channel): string
    {
        $attempt = Attempt::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 60,
            'answers_summary_json' => ['stage' => 'seed'],
            'client_platform' => 'test',
            'channel' => $channel,
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'MBTI',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'mbti_spec_v1',
        ]);

        return (string) $attempt->id;
    }

    private function seedSku(string $sku, string $benefitCode): void
    {
        $now = now();

        DB::table('skus')->updateOrInsert(
            ['sku' => $sku],
            [
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => $benefitCode,
                'scope' => 'attempt',
                'price_cents' => 299,
                'currency' => 'CNY',
                'is_active' => true,
                'meta_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}
