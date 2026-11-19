<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Jobs\Commerce\RefundOrderJob;
use App\Models\User;
use App\Services\Commerce\CareerAssessmentPurchase;
use App\Services\Commerce\Checkout\AlipayCheckoutService;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\PaymentWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class Career94CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v0.5/career-assessment';

    private string $keyFile;

    private array $guestKeys = [];

    public function withToken($value, $type = 'Bearer')
    {
        return parent::withToken($value, $type)->withHeader('X-Career-Guest-Token', $this->guestKeys[$value] ?? '');
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile)) {
            unlink($this->keyFile);
        }
        parent::tearDown();
    }

    public static function markets(): array
    {
        return [
            'WeChat' => ['CN_MAINLAND', 'CNY', ['wechat']],
            'international' => ['INTERNATIONAL', 'USD', ['card', 'applepay', 'googlepay']],
            'guest WeChat' => ['CN_MAINLAND', 'CNY', ['wechat'], true],
            'guest international' => ['INTERNATIONAL', 'USD', ['card', 'applepay', 'googlepay'], true],
        ];
    }

    #[DataProvider('markets')]
    public function test_order_and_signed_refund_are_bound_to_the_saved_result(string $market, string $currency, array $methods, bool $guest = false): void
    {
        $owner = $this->token($guest);
        $other = $this->token();
        $key = $this->configureWaffo();
        $payload = $this->submission($market);
        $this->postJson(self::PATH.'/score', $payload + ['include_report' => true])->assertOk()
            ->assertJsonPath('purchase_offers.0.amount_cents', 299);

        $id = $this->withToken($owner)->postJson(self::PATH.'/results', $payload)
            ->assertOk()->assertJsonPath('unlocked', false)
            ->assertJsonPath('offers.0.currency', $currency)->assertJsonPath('offers.0.amount_cents', 299)
            ->json('attempt_id');
        $this->withToken($owner)->postJson(self::PATH.'/results', $payload)
            ->assertOk()->assertJsonPath('attempt_id', $id);
        $this->withToken($other)->getJson(self::PATH.'/results/'.$id)->assertNotFound();

        $orderPayload = ['attempt_id' => $id, 'sku' => CareerAssessmentPurchase::SKUS[$currency], 'provider' => 'waffo'];
        $this->withToken($other)->postJson('/api/v0.3/orders/checkout', $orderPayload)->assertNotFound();
        $this->withToken($owner)->postJson('/api/v0.3/orders/checkout', array_replace($orderPayload, [
            'sku' => CareerAssessmentPurchase::SKUS[$currency === 'USD' ? 'CNY' : 'USD'],
        ]))->assertStatus(400);
        $order = $this->withToken($owner)->postJson('/api/v0.3/orders/checkout', $orderPayload)
            ->assertOk()->assertJsonPath('pay.type', 'redirect')->json('order_no');
        $this->withToken($owner)->postJson('/api/v0.3/orders/checkout', $orderPayload)
            ->assertOk()->assertJsonPath('order_no', $order);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout/create-session')
            && $request['currency'] === $currency
            && $request['includePaymentMethods'] === $methods
            && $request['priceSnapshot']['amount'] === '2.99'
            && $request['metadata']['fap_attempt_id'] === $id
            && ($guest ? ! isset($request['buyerEmail']) : filter_var($request['buyerEmail'] ?? '', FILTER_VALIDATE_EMAIL))
            && ($currency !== 'CNY' || $request['billingDetail']['country'] === 'CN'));
        $this->assertDatabaseCount('orders', 1);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('unlocked', false)->assertJsonMissingPath('premium');

        $event = [
            'eventType' => 'order.completed', 'eventId' => 'evt_career94', 'mode' => 'test',
            'storeId' => 'STO_fixture', 'timestamp' => now()->format('Y-m-d\TH:i:s.v\Z'),
            'data' => [
                'orderId' => 'ORD_fixture', 'orderStatus' => 'completed',
                'paymentStatus' => 'succeeded', 'currency' => $currency, 'chargedAmount' => '2.99',
                'orderMerchantExternalId' => $order,
                'orderMetadata' => ['fap_order_no' => $order, 'fap_attempt_id' => $id, 'fap_product_id' => 'PROD_career94'],
            ],
        ];
        $this->deliver($event, $key, tamper: true)->assertStatus(401);
        $wrongAttempt = $event;
        $wrongAttempt['data']['orderMetadata']['fap_attempt_id'] = (string) Str::uuid();
        $this->deliver($wrongAttempt, $key)->assertJsonPath('ok', false);
        $this->deliver($event, $key)->assertOk()->assertJsonPath('ok', true);
        $this->deliver($event, $key)->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('payment_attempts', [
            'order_no' => $order, 'callback_received_at' => \Illuminate\Support\Carbon::parse($event['timestamp'])->toDateTimeString(),
        ]);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('unlocked', true)->assertJsonCount(6, 'premium.dimensions');
        $this->assertDatabaseCount('benefit_grants', 1);

        $refund = array_replace($event, ['eventType' => 'refund.succeeded', 'eventId' => 'evt_refund']);
        $refund['data'] += ['refundStatus' => 'succeeded', 'refundedAmount' => '2.99', 'originalPayment' => ['total' => '2.99']];
        $partialRefund = array_replace($refund, ['eventId' => 'evt_partial_refund']);
        $partialRefund['data']['refundedAmount'] = '2.97';
        $this->deliver($partialRefund, $key)->assertJsonPath('ok', false);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('unlocked', true);
        $this->deliver($refund, $key)->assertOk()->assertJsonPath('ok', true);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('unlocked', false)->assertJsonMissingPath('premium');
        $this->deliver($event, $key)->assertOk()->assertJsonPath('ok', true);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('unlocked', false)->assertJsonMissingPath('premium');
        $this->assertDatabaseHas('orders', ['order_no' => $order, 'payment_state' => 'refunded']);
    }

    public function test_alipay_cny_checkout_and_verified_refund_revoke_only_the_owned_report(): void
    {
        config([
            'app.frontend_url' => 'https://fermatmind.com',
            'payments.career94.enabled' => true,
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_fixture',
            'pay.alipay.default.seller_id' => 'seller_fixture',
        ]);
        $owner = $this->token();
        $id = $this->withToken($owner)->postJson(self::PATH.'/results', $this->submission('CN_MAINLAND'))
            ->assertOk()->assertJsonPath('offers.0.provider', 'alipay')->json('attempt_id');
        $order = $this->withToken($owner)->postJson('/api/v0.3/orders/checkout', [
            'attempt_id' => $id, 'provider' => 'alipay', 'sku' => CareerAssessmentPurchase::SKUS['CNY'],
        ])->assertOk()->assertJsonPath('provider', 'alipay')->assertJsonPath('pay.type', 'html')->json('order_no');

        $launch = \Mockery::mock(AlipayCheckoutService::class);
        $launch->shouldReceive('launch')->twice()->withArgs(fn ($order, $scene) => ($order['redirect_checkout'] ?? false) === true)
            ->andReturn(new \Symfony\Component\HttpFoundation\Response('', 302, ['Location' => 'https://openapi.alipay.com/gateway.do?sign=fixture']),
                new \Symfony\Component\HttpFoundation\Response('', 302, ['Location' => 'https://untrusted.example/checkout']));
        $this->app->instance(AlipayCheckoutService::class, $launch);
        $this->withToken($this->token())->getJson('/api/v0.3/orders/'.$order.'/pay/alipay')->assertNotFound();
        $this->withToken($owner)->getJson('/api/v0.3/orders/'.$order.'/pay/alipay')->assertOk()
            ->assertJsonPath('redirect_url', 'https://openapi.alipay.com/gateway.do?sign=fixture');
        $this->withToken($owner)->getJson('/api/v0.3/orders/'.$order.'/pay/alipay')->assertStatus(502);

        $processor = app(PaymentWebhookProcessor::class);
        $payment = [
            'out_trade_no' => $order, 'trade_no' => 'ali_trade_fixture',
            'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '2.99', 'notify_id' => 'ali_event_paid',
        ];
        $this->assertTrue($processor->process('alipay', $payment)['ok']);
        $this->withToken($owner)->getJson('/api/v0.3/orders/'.$order)->assertOk()
            ->assertJsonPath('exact_result_entry.actions.page_href', 'https://fermatmind.com/en/career/assessment/result/'.$id);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)
            ->assertJsonPath('unlocked', true)->assertJsonCount(6, 'premium.dimensions');

        $sdk = \Mockery::mock(AlipayCheckoutService::class);
        $sdk->shouldReceive('requestFullRefund')->once()
            ->with($order, 'ali_trade_fixture', 'career94-'.substr(hash('sha256', $order), 0, 32), '2.99')
            ->andReturn(['code' => '10000', 'trade_no' => 'ali_trade_fixture', 'out_trade_no' => $order,
                'fund_change' => 'Y', 'refund_fee' => '2.99']);
        $this->app->instance(AlipayCheckoutService::class, $sdk);

        $job = new RefundOrderJob(0, $order, 'Synthetic Alipay refund', 'test');
        $job->handle(app(OrderManager::class), app(EntitlementManager::class));
        $job->handle(app(OrderManager::class), app(EntitlementManager::class));
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)
            ->assertJsonPath('unlocked', false)->assertJsonMissingPath('premium');
        $this->assertDatabaseHas('orders', ['order_no' => $order, 'payment_state' => 'refunded']);
    }

    public static function alipayRefundCases(): array
    {
        return [
            'partial refund' => [297, 299, 'CNY', 'ali_trade_fixture', true, true],
            'full refund' => [299, 299, 'CNY', 'ali_trade_fixture', true, true],
            'refund exceeds payment' => [300, 299, 'CNY', 'ali_trade_fixture', true, false],
            'zero refund' => [0, 299, 'CNY', 'ali_trade_fixture', true, false],
            'wrong original amount' => [297, 298, 'CNY', 'ali_trade_fixture', true, false],
            'wrong currency' => [297, 299, 'USD', 'ali_trade_fixture', true, false],
            'wrong transaction' => [297, 299, 'CNY', 'another_trade', true, false],
            'invalid signature' => [297, 299, 'CNY', 'ali_trade_fixture', false, false],
        ];
    }

    #[DataProvider('alipayRefundCases')]
    public function test_alipay_refund_records_actual_amount_and_revokes_report_only_after_verification(
        int $refundCents,
        int $originalCents,
        string $currency,
        string $trade,
        bool $signatureOk,
        bool $accepted,
    ): void {
        config([
            'payments.career94.enabled' => true,
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_fixture',
            'pay.alipay.default.seller_id' => 'seller_fixture',
        ]);
        $owner = $this->token();
        $id = $this->withToken($owner)->postJson(self::PATH.'/results', $this->submission('CN_MAINLAND'))
            ->assertOk()->json('attempt_id');
        $order = $this->withToken($owner)->postJson('/api/v0.3/orders/checkout', [
            'attempt_id' => $id, 'provider' => 'alipay', 'sku' => CareerAssessmentPurchase::SKUS['CNY'],
        ])->assertOk()->json('order_no');
        $processor = app(PaymentWebhookProcessor::class);
        $payment = [
            'out_trade_no' => $order, 'trade_no' => 'ali_trade_fixture',
            'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '2.99', 'notify_id' => 'ali_paid',
        ];
        $this->assertTrue($processor->process('alipay', $payment)['ok']);
        $refund = [
            'out_trade_no' => $order, 'trade_no' => $trade, 'notify_id' => 'ali_refund',
            'total_amount' => number_format($originalCents / 100, 2, '.', ''),
            'refund_fee' => number_format($refundCents / 100, 2, '.', ''), 'currency' => $currency,
            ...($refundCents > 0 ? ['trade_status' => 'TRADE_SUCCESS'] : ['event_type' => 'refund.succeeded']),
        ];
        $result = $processor->handle('alipay', $refund, signatureOk: $signatureOk);
        $this->assertSame($accepted, (bool) ($result['ok'] ?? false));
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)
            ->assertOk()->assertJsonPath('unlocked', ! $accepted);
        $this->assertDatabaseHas('orders', [
            'order_no' => $order, 'payment_state' => $accepted ? 'refunded' : 'paid',
            'refund_amount_cents' => $accepted ? $refundCents : null,
        ]);
        $this->assertSame($accepted ? 0 : 1, DB::table('benefit_grants')->where('order_no', $order)->where('status', 'active')->count());
        if ($accepted) {
            $this->assertTrue($processor->process('alipay', $refund)['ok']);
            $this->assertFalse($processor->process('alipay', array_replace($payment, ['notify_id' => 'ali_paid_late']))['ok']);
            $olderRefund = array_replace($refund, ['notify_id' => 'ali_refund_older', 'refund_fee' => '0.01']);
            $this->assertTrue($processor->process('alipay', $olderRefund)['ok']);
            $this->assertDatabaseHas('orders', ['order_no' => $order, 'refund_amount_cents' => $refundCents]);
            $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)
                ->assertJsonPath('unlocked', false)->assertJsonMissingPath('premium');
            $this->assertDatabaseCount('benefit_grants', 1);
        }
    }

    public static function alipayRefundQueryCases(): array
    {
        return [
            'verified refund' => [[], true],
            'processing refund' => [['refund_status' => 'REFUND_PROCESSING'], false],
            'wrong original amount' => [['total_amount' => '3.00'], false],
            'wrong transaction' => [['trade_no' => 'another_trade'], false],
            'wrong refund reference' => [['out_request_no' => 'another_refund'], false],
            'wrong currency' => [['currency' => 'USD'], false],
        ];
    }

    #[DataProvider('alipayRefundQueryCases')]
    public function test_merchant_refund_reconciliation_requires_verified_alipay_identity_and_amount(array $replacement, bool $accepted): void
    {
        config([
            'payments.career94.enabled' => true,
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_fixture',
            'pay.alipay.default.seller_id' => 'seller_fixture',
        ]);
        $owner = $this->token();
        $id = $this->withToken($owner)->postJson(self::PATH.'/results', $this->submission('CN_MAINLAND'))
            ->assertOk()->json('attempt_id');
        $orderNo = $this->withToken($owner)->postJson('/api/v0.3/orders/checkout', [
            'attempt_id' => $id, 'provider' => 'alipay', 'sku' => CareerAssessmentPurchase::SKUS['CNY'],
        ])->assertOk()->json('order_no');
        $processor = app(PaymentWebhookProcessor::class);
        $this->assertTrue($processor->process('alipay', [
            'out_trade_no' => $orderNo, 'trade_no' => 'ali_trade_fixture',
            'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '2.99', 'notify_id' => 'ali_paid',
        ])['ok']);
        $sdk = \Mockery::mock(AlipayCheckoutService::class);
        $sdk->shouldReceive('requestFullRefund')->never();
        $sdk->shouldReceive('queryRefund')->times($accepted ? 2 : 1)
            ->with($orderNo, 'ali_trade_fixture', 'merchant_refund_fixture')
            ->andReturn(array_replace([
                'code' => '10000', 'refund_status' => 'REFUND_SUCCESS',
                'out_trade_no' => $orderNo, 'trade_no' => 'ali_trade_fixture',
                'out_request_no' => 'merchant_refund_fixture', 'total_amount' => '2.99', 'refund_amount' => '2.97',
            ], $replacement));
        $this->app->instance(AlipayCheckoutService::class, $sdk);
        $service = app(\App\Services\Commerce\CareerAssessmentRefund::class);
        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        try {
            $service->reconcileAlipayRefund($order, 'merchant_refund_fixture');
            $this->assertTrue($accepted, 'Unverified refund was accepted.');
            $service->reconcileAlipayRefund(DB::table('orders')->where('order_no', $orderNo)->first(), 'merchant_refund_fixture');
        } catch (\RuntimeException $e) {
            $this->assertFalse($accepted, $e->getMessage());
        }
        $this->assertDatabaseHas('orders', [
            'order_no' => $orderNo, 'payment_state' => $accepted ? 'refunded' : 'paid',
            'refund_amount_cents' => $accepted ? 297 : null,
        ]);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)
            ->assertOk()->assertJsonPath('unlocked', ! $accepted);
        $this->assertDatabaseCount('benefit_grants', 1);
    }

    public static function paymentRecoveryStates(): array
    {
        return [
            'paid CNY' => ['CN_MAINLAND', 'CNY', 'completed', '2.99', 'none', true],
            'paid USD' => ['INTERNATIONAL', 'USD', 'completed', '2.99', 'none', true],
            'unpaid' => ['CN_MAINLAND', 'CNY', 'pending', '2.99', 'none', false],
            'wrong amount' => ['CN_MAINLAND', 'CNY', 'completed', '0.01', 'none', false],
            'refunded' => ['CN_MAINLAND', 'CNY', 'completed', '2.99', 'full', false],
        ];
    }

    #[DataProvider('paymentRecoveryStates')]
    public function test_order_poll_recovers_verified_payment_without_a_webhook(string $market, string $currency, string $state, string $amount, string $refund, bool $ready): void
    {
        $owner = $this->token();
        $other = $this->token();
        $this->configureWaffo();
        $id = $this->withToken($owner)->postJson(self::PATH.'/results', $this->submission($market))->json('attempt_id');
        $order = $this->withToken($owner)->postJson('/api/v0.3/orders/checkout', [
            'attempt_id' => $id, 'sku' => CareerAssessmentPurchase::SKUS[$currency], 'provider' => 'waffo',
        ])->assertOk()->json('order_no');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.waffo.ai/v1/graphql' => function ($request) use ($id, $order, $currency, $state, $amount, $refund) {
            return Http::response(['data' => str_contains($request['query'], 'onetimeOrders') ? ['onetimeOrders' => [[
                'id' => 'ORD_recovery', 'status' => $state, 'orderMerchantExternalId' => $order,
                'currency' => $currency, 'priceSnapshot' => ['total' => '2.99', 'currency' => $currency],
                'metadata' => json_encode(['fap_order_no' => $order, 'fap_attempt_id' => $id, 'fap_product_id' => 'PROD_career94']),
            ]]] : ['payments' => [[
                'id' => 'PMT_recovery', 'orderId' => 'ORD_recovery', 'status' => 'succeeded', 'refundStatus' => $refund,
                'snapshotAmountDetails' => ['total' => $amount, 'currency' => $currency],
            ]]]]);
        }]);
        $this->withToken($other)->getJson('/api/v0.3/orders/'.$order)->assertNotFound();
        Http::assertNothingSent();
        $response = $this->withToken($owner)->getJson('/api/v0.3/orders/'.$order)->assertOk()
            ->assertJsonPath('result_url', 'https://fermatmind.com/en/career/assessment/result/'.$id)
            ->assertJsonPath('status', $ready ? 'paid' : 'pending');
        if ($ready) {
            $response->assertJsonPath('exact_result_entry.access_state', 'ready')
                ->assertJsonPath('exact_result_entry.actions.page_href', 'https://fermatmind.com/en/career/assessment/result/'.$id);
        }
        $this->withToken($owner)->getJson('/api/v0.3/orders/'.$order)->assertOk();
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('unlocked', $ready);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('benefit_grants', $ready ? 1 : 0);
        Http::assertSentCount($state === 'completed' ? 2 : 1);
    }

    public function test_waffo_timestamps_require_rfc3339_and_are_normalized_to_utc(): void
    {
        $this->configureWaffo();
        config(['payments.waffo.product_id' => 'PROD_timestamp']);
        $event = [
            'eventType' => 'order.completed', 'eventId' => 'evt_timestamp', 'mode' => 'test',
            'storeId' => 'STO_fixture', 'timestamp' => '2026-11-19T12:12:32.178+08:00',
            'data' => [
                'orderId' => 'ORD_timestamp', 'orderStatus' => 'completed', 'paymentStatus' => 'succeeded',
                'currency' => 'USD', 'chargedAmount' => '1.99', 'orderMerchantExternalId' => 'ord_timestamp',
                'orderMetadata' => ['fap_order_no' => 'ord_timestamp', 'fap_product_id' => 'PROD_timestamp'],
            ],
        ];
        $gateway = app(\App\Services\Commerce\PaymentGateway\WaffoGateway::class);
        $this->assertSame('2026-11-19 04:12:32', $gateway->normalizePayload($event)['paid_at']);
        $event['timestamp'] = 'tomorrow';
        $this->assertSame([], $gateway->normalizePayload($event));
        unset($event['timestamp']);
        $this->assertSame([], $gateway->normalizePayload($event));
    }

    public function test_guest_can_pay_read_and_request_refund_without_creating_an_account(): void
    {
        config(['payments.career94.enabled' => true, 'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_fixture', 'pay.alipay.default.seller_id' => 'seller_fixture']);
        $users = DB::table('users')->count();
        $guest = $this->token(true);
        $other = $this->token(true);
        $id = $this->withToken($guest)->postJson(self::PATH.'/results', $this->submission('CN_MAINLAND'))
            ->assertOk()->json('attempt_id');
        $this->withToken($other)->getJson(self::PATH.'/results/'.$id)->assertNotFound();
        $originalAnon = DB::table('auth_tokens')->where('token_hash', hash('sha256', $guest))->value('anon_id');
        DB::table('auth_tokens')->where('token_hash', hash('sha256', $other))->update(['anon_id' => $originalAnon]);
        $this->withToken($other)->getJson(self::PATH.'/results/'.$id)->assertNotFound();
        $payload = ['attempt_id' => $id, 'provider' => 'alipay', 'sku' => CareerAssessmentPurchase::SKUS['CNY']];
        $this->withToken($other)->postJson('/api/v0.3/orders/checkout', $payload)->assertNotFound();
        $number = $this->withToken($guest)->postJson('/api/v0.3/orders/checkout', $payload)->assertOk()->json('order_no');
        $this->assertDatabaseHas('orders', ['order_no' => $number, 'user_id' => null]);
        $this->assertTrue(app(PaymentWebhookProcessor::class)->process('alipay', [
            'out_trade_no' => $number, 'trade_no' => 'guest_trade', 'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '2.99', 'notify_id' => 'guest_paid',
        ])['ok']);
        $this->withToken($guest)->getJson(self::PATH.'/results/'.$id)->assertOk()->assertJsonPath('unlocked', true)->assertJsonCount(6, 'premium.dimensions');
        $this->withToken($guest)->getJson('/api/v0.3/orders/'.$number)->assertOk()
            ->assertJsonPath('exact_result_entry.access_state', 'ready');
        $url = self::PATH.'/orders/'.$number.'/refund';
        $this->withToken($other)->postJson($url, ['reason_code' => 'other', 'acknowledged' => true])->assertNotFound();
        $this->withToken($guest)->postJson($url, ['reason_code' => 'other', 'acknowledged' => true])->assertOk()->assertJsonPath('request.status', 'pending');
        $renewed = $this->token(true);
        $this->guestKeys[$renewed] = $this->guestKeys[$guest];
        $this->withToken($renewed)->getJson(self::PATH.'/results/'.$id)->assertOk()->assertJsonPath('unlocked', true);
        $this->assertSame($users, DB::table('users')->count());
    }

    public function test_verified_waffo_query_recovers_a_missing_refund_callback_and_keeps_pending_access(): void
    {
        $owner = $this->token(true);
        $key = $this->configureWaffo();
        $id = $this->withToken($owner)->postJson(self::PATH.'/results', $this->submission('CN_MAINLAND'))->assertOk()->json('attempt_id');
        $number = $this->withToken($owner)->postJson('/api/v0.3/orders/checkout', [
            'attempt_id' => $id, 'provider' => 'waffo', 'sku' => CareerAssessmentPurchase::SKUS['CNY'],
        ])->assertOk()->json('order_no');
        $this->deliver(['eventType' => 'order.completed', 'eventId' => 'paid-before-query', 'mode' => 'test',
            'storeId' => 'STO_fixture', 'timestamp' => now()->toIso8601String(), 'data' => [
                'orderId' => 'ORD_query', 'orderStatus' => 'completed', 'paymentStatus' => 'succeeded', 'currency' => 'CNY',
                'chargedAmount' => '2.99', 'orderMerchantExternalId' => $number,
                'orderMetadata' => ['fap_order_no' => $number, 'fap_attempt_id' => $id, 'fap_product_id' => 'PROD_career94'],
            ]], $key)->assertOk();
        $this->withToken($owner)->postJson(self::PATH.'/orders/'.$number.'/refund', ['reason_code' => 'other', 'acknowledged' => true])->assertOk();
        foreach ([['succeeded', '2.97'], ['pending', '2.99'], ['succeeded', '2.99']] as [$state, $amount]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake(['https://api.waffo.ai/v1/graphql' => function ($request) use ($number, $state, $amount) {
                return Http::response(['data' => str_contains($request['query'], 'refunds(') ? ['refunds' => [[
                    'id' => 'refund_query', 'paymentId' => 'pay_query', 'status' => $state,
                    'amount' => ['amount' => $amount, 'currency' => 'CNY'], 'testMode' => true,
                    'orderMerchantExternalId' => $number,
                    'refundTicketMerchantExternalId' => 'career94-'.substr(hash('sha256', $number), 0, 32),
                ]]] : ['payments' => [['id' => 'pay_query', 'orderId' => 'ORD_query', 'status' => 'succeeded',
                    'refundStatus' => 'none', 'snapshotAmountDetails' => ['total' => '2.99', 'currency' => 'CNY']]]]]);
            }]);
            $order = DB::table('orders')->where('order_no', $number)->first();
            try {
                $confirmed = app(\App\Services\Commerce\CareerAssessmentRefund::class)->reconcileWaffoRefund($order);
                $this->assertSame($state === 'succeeded', $confirmed);
            } catch (\RuntimeException $error) {
                $this->assertSame('2.97', $amount, $error->getMessage());
            }
            $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)
                ->assertJsonPath('unlocked', ! ($state === 'succeeded' && $amount === '2.99'));
        }
        $this->assertDatabaseHas('career_refund_requests', ['order_no' => $number, 'status' => 'succeeded']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/refund-ticket/'));
    }

    private function token(bool $guest = false): string
    {
        $token = 'fm_'.Str::uuid();
        if ($guest) {
            $this->guestKeys[$token] = bin2hex(random_bytes(32));
        }
        DB::table('auth_tokens')->insert([
            'anon_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $token), 'user_id' => $guest ? null : User::factory()->create()->getKey(),
            'org_id' => 0, 'role' => 'public', 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $token;
    }

    private function submission(string $market): array
    {
        $items = $this->getJson(self::PATH.'/questions')->assertOk()->json('items');
        $answers = [];
        foreach ($items as $item) {
            $answers[$item['id']] = match (true) {
                $item['section'] === 'context' => null,
                $item['axis'] === 'I' => 5,
                default => 3,
            };
        }

        return ['form_version' => 'career_v1', 'locale' => 'en', 'answers' => $answers,
            'submission_id' => (string) Str::uuid(), 'market' => $market];
    }

    private function configureWaffo(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'career94-test-');
        openssl_pkey_export_to_file($key, $this->keyFile);
        chmod($this->keyFile, 0600);
        config([
            'app.frontend_url' => 'https://fermatmind.com',
            'payments.career94.enabled' => true,
            'payments.providers.waffo.enabled' => true,
            'payments.waffo' => ['environment' => 'test', 'merchant_id' => 'MER_fixture', 'store_id' => 'STO_fixture',
                'career94_product_id' => 'PROD_career94', 'private_key_path' => $this->keyFile,
                'webhook_public_key' => openssl_pkey_get_details($key)['key']],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.waffo.ai/v1/graphql' => Http::response(['data' => ['onetimeOrders' => []]]),
            'https://api.waffo.ai/v1/actions/checkout/create-session' => Http::response(['data' => [
                'checkoutUrl' => 'https://pancake.waffo.ai/store/fixture/checkout/cs_career94?env=test',
                'sessionId' => 'cs_career94', 'expiresAt' => now()->addMinutes(30)->toIso8601String(),
            ]]),
        ]);

        return $key;
    }

    private function deliver(array $event, \OpenSSLAsymmetricKey $key, bool $tamper = false)
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (int) floor(microtime(true) * 1000);
        openssl_sign($timestamp.'.'.$raw, $signature, $key, OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/api/v0.3/webhooks/payment/waffo', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WAFFO_SIGNATURE' => 't='.$timestamp.',v1='.base64_encode($signature),
        ], $raw.($tamper ? ' ' : ''));
    }
}
