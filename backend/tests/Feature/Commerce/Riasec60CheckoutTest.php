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

final class Riasec60CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v0.5/riasec-assessment';

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
        $id = $this->fixture($owner, $market);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertOk()
            ->assertJsonPath('riasec_purchase.unlocked', false)->assertJsonPath('riasec_purchase.offers.0.currency', 'CNY');
        $this->withToken($other)->getJson(self::PATH.'/results/'.$id)->assertNotFound();

        $orderPayload = ['attempt_id' => $id, 'sku' => CareerAssessmentPurchase::RIASEC_SKUS[$currency], 'provider' => 'waffo'];
        $this->withToken($other)->postJson('/api/v0.3/orders/checkout', $orderPayload)->assertNotFound();
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
        $this->withToken($owner)->getJson('/api/v0.3/attempts/'.$id.'/result')->assertOk()->assertJsonPath('riasec_purchase.unlocked', false)->assertJsonMissingPath('riasec_private_result_authority');
        $this->withToken($owner)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk()->assertJsonMissingPath('riasec_public_projection_v2.deep_content_slots_v1');
        $this->withToken($owner)->getJson('/api/v0.3/attempts/'.$id.'/report.pdf')->assertForbidden();
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('riasec_purchase.unlocked', false)->assertJsonMissingPath('riasec_public_projection_v2.deep_content_slots_v1');

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
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('riasec_purchase.unlocked', true)->assertJsonStructure(['riasec_public_projection_v2' => ['deep_content_slots_v1']]);
        $this->assertDatabaseCount('benefit_grants', 1);
        $this->withToken($owner)->postJson('/api/v0.5/career-assessment/orders/'.$order.'/refund', ['reason_code' => 'not_as_expected', 'acknowledged' => true])->assertOk()->assertJsonPath('request.status', 'pending');

        $refund = array_replace($event, ['eventType' => 'refund.succeeded', 'eventId' => 'evt_refund']);
        $refund['data'] += ['refundStatus' => 'succeeded', 'refundedAmount' => '2.99', 'originalPayment' => ['total' => '2.99']];
        $partialRefund = array_replace($refund, ['eventId' => 'evt_partial_refund']);
        $partialRefund['data']['refundedAmount'] = '2.97';
        $this->deliver($partialRefund, $key)->assertJsonPath('ok', false);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('riasec_purchase.unlocked', true);
        $this->deliver($refund, $key)->assertOk()->assertJsonPath('ok', true);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('riasec_purchase.unlocked', false)->assertJsonMissingPath('riasec_public_projection_v2.deep_content_slots_v1');
        $this->deliver($event, $key)->assertOk()->assertJsonPath('ok', true);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)->assertJsonPath('riasec_purchase.unlocked', false)->assertJsonMissingPath('riasec_public_projection_v2.deep_content_slots_v1');
        $this->assertDatabaseHas('orders', ['order_no' => $order, 'payment_state' => 'refunded']);
        $this->assertDatabaseHas('career_refund_requests', ['order_no' => $order, 'status' => 'succeeded']);
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
        $id = $this->fixture($owner, 'CN_MAINLAND');
        $order = $this->withToken($owner)->postJson('/api/v0.3/orders/checkout', [
            'attempt_id' => $id, 'provider' => 'alipay', 'sku' => CareerAssessmentPurchase::RIASEC_SKUS['CNY'],
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
            ->assertJsonPath('exact_result_entry.actions.page_href', 'https://fermatmind.com/en/result/'.$id);
        $this->withToken($owner)->getJson(self::PATH.'/results/'.$id)
            ->assertJsonPath('riasec_purchase.unlocked', true)->assertJsonStructure(['riasec_public_projection_v2' => ['deep_content_slots_v1']]);

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
            ->assertJsonPath('riasec_purchase.unlocked', false)->assertJsonMissingPath('riasec_public_projection_v2.deep_content_slots_v1');
        $this->assertDatabaseHas('orders', ['order_no' => $order, 'payment_state' => 'refunded']);
    }

    public function test_public_analytics_id_without_private_purchase_key_cannot_read_or_buy(): void
    {
        config(['payments.career94.enabled' => true, 'payments.providers.alipay.enabled' => true, 'pay.alipay.default.app_id' => 'app_fixture', 'pay.alipay.default.seller_id' => 'seller_fixture']);
        $owner = $this->token(true);
        $id = $this->fixture($owner, 'CN_MAINLAND');
        $actor = DB::table('auth_tokens')->where('token_hash', hash('sha256', $owner))->first();
        $impostor = $this->token(true);
        DB::table('auth_tokens')->where('token_hash', hash('sha256', $impostor))->update(['anon_id' => $actor->anon_id]);
        $this->withToken($impostor)->getJson(self::PATH.'/results/'.$id)->assertNotFound();
        $this->withToken($impostor)->postJson('/api/v0.3/orders/checkout', ['attempt_id' => $id,
            'sku' => CareerAssessmentPurchase::RIASEC_SKUS['CNY'], 'provider' => 'alipay'])->assertNotFound();
    }

    public function test_new_native_60_attempts_require_a_purchase_key_and_enroll_on_the_server(): void
    {
        config(['payments.career94.enabled' => true]);
        $this->seed(\Database\Seeders\ScaleRegistrySeeder::class);
        $token = $this->token(true);
        $anon = DB::table('auth_tokens')->where('token_hash', hash('sha256', $token))->value('anon_id');
        $body = ['scale_code' => 'RIASEC', 'form_code' => 'riasec_60', 'anon_id' => $anon,
            'locale' => 'zh-CN', 'region' => 'CN_MAINLAND'];
        $blocked = $this->withToken($token)->withHeader('X-Anon-Id', $anon)->withHeader('X-Career-Guest-Token', '')->postJson('/api/v0.3/attempts/start', $body);
        $this->assertSame(422, $blocked->status(), $blocked->getContent());
        $id = $this->withToken($token)->postJson('/api/v0.3/attempts/start', $body)->assertOk()->json('attempt_id');
        $attempt = \App\Models\Attempt::withoutGlobalScopes()->findOrFail($id);
        $this->assertTrue(app(\App\Services\Commerce\RiasecReportPurchase::class)->managed($attempt));
        $this->assertTrue(app(\App\Services\Commerce\RiasecReportPurchase::class)->guestOwns(request(), $attempt));
    }

    private function fixture(string $token, string $market): string
    {
        $actor = DB::table('auth_tokens')->where('token_hash', hash('sha256', $token))->first();
        $a = \App\Models\Attempt::create(['org_id' => 0, 'anon_id' => $actor->anon_id, 'user_id' => $actor->user_id,
            'client_platform' => 'web', 'channel' => 'web', 'scale_code' => 'RIASEC', 'scale_version' => 'v1', 'locale' => 'en', 'region' => $market, 'question_count' => 60,
            'submitted_at' => now(), 'answers_summary_json' => ['meta' => ['riasec60_commerce' => 'v1', 'riasec60_guest_owner' => isset($this->guestKeys[$token]) ? 'c94_'.substr(hash('sha256', $this->guestKeys[$token]), 0, 48) : null]]]);
        \App\Models\Result::create(['id' => (string) Str::uuid(), 'org_id' => 0, 'attempt_id' => $a->id, 'scale_code' => 'RIASEC', 'scale_version' => 'v1', 'computed_at' => now(), 'scores_json' => [], 'type_code' => 'IAR',
            'scores_pct' => ['R' => 70, 'I' => 75, 'A' => 75, 'S' => 57.5, 'E' => 22.5, 'C' => 0],
            'result_json' => ['top_code' => 'IAR', 'form_code' => 'riasec_60', 'answer_count' => 60]]);

        return $a->id;
    }

    public function test_fixed_sample_is_identical_for_all_visitors_and_does_not_write_results(): void
    {
        $a = $this->getJson(self::PATH.'/sample?locale=en')->assertOk()->json();
        $b = $this->getJson(self::PATH.'/sample?locale=en&profile=ECR&score=0')->assertOk()->json();
        $this->assertSame($a, $b);
        $this->assertSame('IAR', data_get($a, 'riasec_public_projection_v2.holland_code.code'));
        $this->assertDatabaseCount('attempts', 0);
        $this->assertFalse(app(\App\Services\Commerce\RiasecReportPurchase::class)->managed(new \App\Models\Attempt(['org_id' => 0, 'scale_code' => 'RIASEC', 'question_count' => 60])));
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
