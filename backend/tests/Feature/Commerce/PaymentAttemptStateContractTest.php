<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Commerce\OrderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentAttemptStateContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_manager_creates_and_advances_payment_attempt(): void
    {
        config([
            'payments.providers.wechatpay.enabled' => true,
        ]);

        $attemptId = $this->createAttempt();
        $this->seedSku();

        $created = app(OrderManager::class)->createOrder(
            0,
            null,
            'anon_payment_attempt_contract',
            'MBTI_REPORT_FULL',
            1,
            $attemptId,
            'wechatpay',
            null,
            'payment-attempt-contract@example.com',
            null,
            [],
            [],
            [
                'channel' => 'wechat_miniapp',
                'provider_app' => 'wx-miniapp-primary',
            ]
        );

        $this->assertTrue((bool) ($created['ok'] ?? false));
        $orderNo = (string) ($created['order_no'] ?? '');
        $this->assertNotSame('', $orderNo);

        $attempt = app(OrderManager::class)->createPaymentAttempt(
            $orderNo,
            0,
            'wechatpay',
            'wechat_miniapp',
            'wx-miniapp-primary',
            'mobile',
            299,
            'CNY',
            ['source' => 'contract_test']
        )['attempt'] ?? null;

        $this->assertNotNull($attempt);
        $this->assertSame(1, (int) ($attempt->attempt_no ?? 0));
        $this->assertSame(PaymentAttempt::STATE_INITIATED, (string) ($attempt->state ?? ''));

        $providerCreated = app(OrderManager::class)->advancePaymentAttempt((string) ($attempt->id ?? ''), [
            'state' => PaymentAttempt::STATE_PROVIDER_CREATED,
            'external_trade_no' => 'wx_trade_contract_1',
            'provider_created_at' => now()->toDateTimeString(),
        ]);
        $this->assertNotNull($providerCreated);
        $this->assertSame(PaymentAttempt::STATE_PROVIDER_CREATED, (string) ($providerCreated->state ?? ''));
        $this->assertSame('wx_trade_contract_1', (string) ($providerCreated->external_trade_no ?? ''));

        $paid = app(OrderManager::class)->advancePaymentAttempt((string) ($attempt->id ?? ''), [
            'state' => PaymentAttempt::STATE_PAID,
            'provider_trade_no' => 'wx_provider_trade_contract_1',
            'verified_at' => now()->toDateTimeString(),
        ]);
        $this->assertNotNull($paid);
        $this->assertSame(PaymentAttempt::STATE_PAID, (string) ($paid->state ?? ''));
        $this->assertSame('wx_provider_trade_contract_1', (string) ($paid->provider_trade_no ?? ''));
        $this->assertNotNull($paid->finalized_at ?? null);

        $order = Order::query()->where('order_no', $orderNo)->first();
        $this->assertNotNull($order);
        $this->assertSame(1, $order->paymentAttempts()->count());
        $this->assertNotNull($order->latestPaymentAttempt);
        $this->assertSame((string) ($attempt->id ?? ''), (string) ($order->latestPaymentAttempt->id ?? ''));
    }

    private function createAttempt(): string
    {
        $attempt = Attempt::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => 'anon_payment_attempt_contract',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 60,
            'answers_summary_json' => ['stage' => 'seed'],
            'client_platform' => 'test',
            'channel' => 'wechat_miniapp',
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'MBTI',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'mbti_spec_v1',
        ]);

        return (string) $attempt->id;
    }

    private function seedSku(): void
    {
        DB::table('skus')->updateOrInsert(
            ['sku' => 'MBTI_REPORT_FULL'],
            [
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'kind' => 'report_unlock',
                'unit_qty' => 1,
                'benefit_code' => 'MBTI_REPORT_FULL',
                'scope' => 'attempt',
                'price_cents' => 299,
                'currency' => 'CNY',
                'is_active' => true,
                'meta_json' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
