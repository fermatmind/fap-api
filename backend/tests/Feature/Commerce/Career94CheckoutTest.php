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
        ];
    }

    #[DataProvider('markets')]
    public function test_order_and_signed_refund_are_bound_to_the_saved_result(string $market, string $currency, array $methods): void
    {
        $owner = $this->token();
        $other = $this->token();
        $key = $this->configureWaffo();
        $payload = $this->submission($market);

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
            && $request['metadata']['fap_attempt_id'] === $id);
        $this->assertDatabaseCount('orders', 1);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('unlocked', false)->assertJsonMissingPath('premium');

        $event = [
            'eventType' => 'order.completed', 'eventId' => 'evt_career94', 'mode' => 'test',
            'storeId' => 'STO_fixture', 'timestamp' => now()->toIso8601String(),
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
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('unlocked', true)->assertJsonCount(6, 'premium.dimensions');
        $this->assertDatabaseCount('benefit_grants', 1);

        $refund = array_replace($event, ['eventType' => 'refund.succeeded', 'eventId' => 'evt_refund']);
        $refund['data'] += ['refundStatus' => 'succeeded', 'refundedAmount' => '2.99', 'originalPayment' => ['total' => '2.99']];
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

    private function token(): string
    {
        $token = 'fm_'.Str::uuid();
        DB::table('auth_tokens')->insert([
            'anon_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $token), 'user_id' => User::factory()->create()->getKey(),
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
