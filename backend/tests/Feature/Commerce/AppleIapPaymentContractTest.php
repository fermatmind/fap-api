<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Result;
use App\Services\Commerce\AppleIapPaymentService;
use App\Services\Commerce\PaymentGateway\AppleIapGateway;
use App\Services\Payments\PaymentProviderRegistry;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AppleIapPaymentContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_is_fail_closed_until_wechat_routed_ios_contract_is_complete(): void
    {
        config()->set('payments.providers.apple_iap.enabled', true);
        config()->set('report_unlock.providers.apple_iap.available', false);

        $this->assertFalse(app(PaymentProviderRegistry::class)->isEnabled(AppleIapGateway::PROVIDER));

        $this->configureProvider();
        config()->set('payments.apple_iap.product_id', '');
        $this->assertFalse(app(PaymentProviderRegistry::class)->isEnabled(AppleIapGateway::PROVIDER));

        config()->set('payments.apple_iap.product_id', 'mbti-report-full');
        config()->set('payments.apple_iap.environment', 1);
        $this->assertFalse(app(PaymentProviderRegistry::class)->isEnabled(AppleIapGateway::PROVIDER));

        config()->set('payments.apple_iap.environment', 0);
        $this->assertTrue(app(PaymentProviderRegistry::class)->isEnabled(AppleIapGateway::PROVIDER));

        config()->set('payments.providers.apple_iap.enabled', false);
        config()->set('report_unlock.providers.apple_iap.available', false);
        $this->assertFalse(app(PaymentProviderRegistry::class)->isEnabled(AppleIapGateway::PROVIDER));
        $this->assertTrue(app(PaymentProviderRegistry::class)->canProcessSettlement(AppleIapGateway::PROVIDER));
    }

    public function test_ios_order_uses_shared_virtual_payment_api_with_production_environment_and_backend_price(): void
    {
        $this->configureProvider();
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
        $this->seedSku();
        $attemptId = $this->createAttempt('anon_apple_iap', true);
        $otherAttemptId = $this->createAttempt('anon_apple_iap_other', true);
        $token = $this->issueAnonToken('anon_apple_iap');
        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-apple-contract',
                'session_key' => 'session-key-apple-contract',
            ]),
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => 'anon_apple_iap',
            'X-Channel' => 'wechat_miniapp',
        ];
        $payload = [
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'idempotency_key' => 'idem-apple-iap-1',
            'wx_login_code' => 'wx-login-apple-contract',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/apple_iap', array_merge($payload, ['amount_cents' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount_cents']);
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/apple_iap', array_merge($payload, ['target_attempt_id' => $otherAttemptId]))
            ->assertNotFound();

        $created = $this->withHeaders($headers)->postJson('/api/v0.3/orders/apple_iap', $payload);
        $created
            ->assertOk()
            ->assertJsonPath('pay.type', AppleIapGateway::PROVIDER)
            ->assertJsonPath('pay.provider', AppleIapGateway::PROVIDER);
        $orderNo = (string) $created->json('order_no');
        $signDataJson = (string) $created->json('pay.params.signData');
        $signData = json_decode($signDataJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $signData['env']);
        $this->assertSame(499, $signData['goodsPrice']);
        $this->assertSame('CNY', $signData['currencyType']);
        $this->assertSame('mbti-report-full', $signData['productId']);
        $this->assertSame(
            hash_hmac('sha256', 'requestVirtualPayment&'.$signDataJson, 'production-app-key'),
            $created->json('pay.params.paySig')
        );

        $paymentAttempt = DB::table('payment_attempts')->where('order_no', $orderNo)->first();
        $this->assertNotNull($paymentAttempt);
        $stored = (string) ($paymentAttempt->payload_meta_json ?? '');
        $this->assertStringNotContainsString('session-key-apple-contract', $stored);
        $this->assertStringNotContainsString('openid-apple-contract', $stored);
        $this->assertStringContainsString('apple_iap', $stored);

        config()->set('payments.apple_iap.app_key', 'rotated-production-app-key');
        config()->set('payments.apple_iap.offer_id', 'offer-test-v2');
        config()->set('payments.apple_iap.product_id', 'mbti-report-full-v2');
        $duplicate = $this->withHeaders($headers)->postJson('/api/v0.3/orders/apple_iap', $payload);
        $duplicate->assertOk();
        $this->assertSame($orderNo, (string) $duplicate->json('order_no'));
        $duplicateSignData = json_decode(
            (string) $duplicate->json('pay.params.signData'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame($signData['outTradeNo'], $duplicateSignData['outTradeNo']);
        $this->assertSame($signData['offerId'], $duplicateSignData['offerId']);
        $this->assertSame($signData['productId'], $duplicateSignData['productId']);
        $this->assertSame($signData['goodsPrice'], $duplicateSignData['goodsPrice']);
        $this->assertSame(1, DB::table('payment_attempts')->where('order_no', $orderNo)->count());
        $this->assertSame(
            $stored,
            (string) DB::table('payment_attempts')->where('order_no', $orderNo)->value('payload_meta_json')
        );

        DB::table('orders')->where('order_no', $orderNo)->update([
            'payment_state' => Order::PAYMENT_STATE_PAID,
            'status' => Order::STATUS_PAID,
        ]);
        DB::table('payment_attempts')->where('order_no', $orderNo)->update([
            'state' => PaymentAttempt::STATE_PAID,
        ]);
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/apple_iap', $payload)
            ->assertOk();
        $this->assertSame(
            Order::PAYMENT_STATE_PAID,
            DB::table('orders')->where('order_no', $orderNo)->value('payment_state')
        );
        $this->assertSame(Order::STATUS_PAID, DB::table('orders')->where('order_no', $orderNo)->value('status'));

        DB::table('orders')->where('order_no', $orderNo)->update([
            'payment_state' => Order::PAYMENT_STATE_REFUNDED,
            'status' => Order::STATUS_REFUNDED,
        ]);
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/apple_iap', $payload)
            ->assertOk();
        $this->assertSame(
            Order::PAYMENT_STATE_REFUNDED,
            DB::table('orders')->where('order_no', $orderNo)->value('payment_state')
        );
        $this->assertSame(Order::STATUS_REFUNDED, DB::table('orders')->where('order_no', $orderNo)->value('status'));
    }

    public function test_apple_channel_callback_and_query_are_verified_idempotent_and_refund_revokes_grant(): void
    {
        $this->configureProvider();
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
        $this->seedSku();
        $attemptId = $this->createAttempt('anon_apple_iap', true);
        $token = $this->issueAnonToken('anon_apple_iap');
        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-apple-contract',
                'session_key' => 'session-key-apple-contract',
            ]),
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => 'anon_apple_iap',
            'X-Channel' => 'wechat_miniapp',
        ];
        $created = $this->withHeaders($headers)->postJson('/api/v0.3/orders/apple_iap', [
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'idempotency_key' => 'idem-apple-iap-callback',
            'wx_login_code' => 'wx-login-apple-contract',
        ])->assertOk();
        $orderNo = (string) $created->json('order_no');
        $externalOrderNo = (string) data_get(
            json_decode((string) $created->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR),
            'outTradeNo'
        );

        $callbackPayload = [
            'Event' => 'xpay_goods_deliver_notify',
            'OpenId' => 'openid-apple-contract',
            'OutTradeNo' => $externalOrderNo,
            'Env' => 0,
            'channel_order_id' => 'apple-channel-bill-1',
            'GoodsInfo' => [
                'ProductId' => 'mbti-report-full',
                'Quantity' => 1,
                'OrigPrice' => 499,
                'ActualPrice' => 499,
                'Attach' => $orderNo,
            ],
        ];
        $wrongEnvironment = $callbackPayload;
        $wrongEnvironment['Env'] = 1;
        $rejected = app(AppleIapPaymentService::class)
            ->handleCallback($this->signedCallbackRequest($wrongEnvironment));
        $this->assertFalse((bool) ($rejected['ok'] ?? true));
        $this->assertSame('PROVIDER_ENV_MISMATCH', $rejected['error_code'] ?? null);

        config()->set('payments.apple_iap.app_key', 'rotated-production-app-key');
        config()->set('payments.apple_iap.offer_id', 'offer-test-v2');
        config()->set('payments.apple_iap.sku', 'MBTI_REPORT_FULL_V2');
        config()->set('payments.apple_iap.product_id', 'mbti-report-full-v2');
        config()->set('payments.apple_iap.price_cents', 999);
        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'apple-access-token-contract',
                'expires_in' => 7200,
            ]),
            'https://api.weixin.qq.com/xpay/query_order*' => Http::sequence()
                ->push($this->appleQueryResponse($externalOrderNo))
                ->push($this->appleQueryResponse($externalOrderNo))
                ->push($this->appleQueryResponse($externalOrderNo))
                ->push($this->appleQueryResponse($externalOrderNo))
                ->push($this->appleQueryResponse($externalOrderNo))
                ->push($this->appleQueryResponse($externalOrderNo, 8)),
        ]);

        $handled = app(AppleIapPaymentService::class)
            ->handleCallback($this->signedCallbackRequest($callbackPayload));
        $this->assertTrue((bool) ($handled['ok'] ?? false));
        $duplicate = app(AppleIapPaymentService::class)
            ->handleCallback($this->signedCallbackRequest($callbackPayload));
        $this->assertTrue((bool) ($duplicate['duplicate'] ?? false));
        $this->assertSame(1, DB::table('payment_events')->where('provider', AppleIapGateway::PROVIDER)->count());
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
        $eventPayload = (string) DB::table('payment_events')
            ->where('provider', AppleIapGateway::PROVIDER)
            ->value('payload_json');
        $this->assertStringNotContainsString('openid-apple-contract', $eventPayload);
        $this->assertStringNotContainsString('session-key-apple-contract', $eventPayload);

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/'.$orderNo.'/apple-iap/reconcile')
            ->assertOk()
            ->assertJsonPath('provider_status', 2)
            ->assertJsonPath('grant_state', 'granted');
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/'.$orderNo.'/apple-iap/reconcile')
            ->assertOk()
            ->assertJsonPath('provider_status', 2)
            ->assertJsonPath('grant_state', 'granted');
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
        $this->assertSame(2, DB::table('payment_events')->where('provider', AppleIapGateway::PROVIDER)->count());

        config()->set('payments.providers.apple_iap.enabled', false);
        config()->set('report_unlock.providers.apple_iap.available', false);
        $this->assertFalse(app(PaymentProviderRegistry::class)->isEnabled(AppleIapGateway::PROVIDER));
        $this->assertTrue(app(PaymentProviderRegistry::class)->canProcessSettlement(AppleIapGateway::PROVIDER));
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/'.$orderNo.'/apple-iap/reconcile')
            ->assertOk()
            ->assertJsonPath('provider_status', 2)
            ->assertJsonPath('grant_state', 'granted');

        $refundPayload = [
            'Event' => 'xpay_refund_notify',
            'OpenId' => 'openid-apple-contract',
            'MchOrderId' => $externalOrderNo,
            'RefundFee' => 499,
            'RetCode' => 0,
        ];
        $refunded = app(AppleIapPaymentService::class)
            ->handleCallback($this->signedCallbackRequest($refundPayload));
        $this->assertTrue((bool) ($refunded['ok'] ?? false));
        $this->assertSame(3, DB::table('payment_events')->where('provider', AppleIapGateway::PROVIDER)->count());
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'revoked')->count());
    }

    public function test_query_rejects_non_apple_channel_and_environment_mismatch(): void
    {
        $this->configureProvider();
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
        $this->seedSku();
        $attemptId = $this->createAttempt('anon_apple_iap', true);
        $token = $this->issueAnonToken('anon_apple_iap');
        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-apple-contract',
                'session_key' => 'session-key-apple-contract',
            ]),
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => 'anon_apple_iap',
        ];
        $created = $this->withHeaders($headers)->postJson('/api/v0.3/orders/apple_iap', [
            'sku' => 'MBTI_REPORT_FULL',
            'target_attempt_id' => $attemptId,
            'idempotency_key' => 'idem-apple-iap-query-guards',
            'wx_login_code' => 'wx-login-apple-contract',
        ])->assertOk();
        $orderNo = (string) $created->json('order_no');
        $externalOrderNo = (string) data_get(
            json_decode((string) $created->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR),
            'outTradeNo'
        );

        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'token', 'expires_in' => 7200]),
            'https://api.weixin.qq.com/xpay/query_order*' => Http::sequence()
                ->push([
                    'errcode' => 0,
                    'order' => [
                        'order_id' => $externalOrderNo,
                        'status' => 2,
                        'order_type' => 0,
                        'env_type' => 1,
                        'order_fee' => 499,
                        'paid_fee' => 499,
                    ],
                ])
                ->push([
                    'errcode' => 0,
                    'order' => [
                        'order_id' => $externalOrderNo,
                        'status' => 2,
                        'order_type' => 0,
                        'env_type' => 1,
                        'order_fee' => 499,
                        'paid_fee' => 499,
                    ],
                ])
                ->push([
                    'errcode' => 0,
                    'order' => [
                        'order_id' => $externalOrderNo,
                        'status' => 2,
                        'order_type' => 7,
                        'env_type' => 2,
                        'order_fee' => 499,
                        'paid_fee' => 499,
                    ],
                ])
                ->push($this->appleQueryResponse($externalOrderNo, 8, 0))
                ->push($this->appleQueryResponse($externalOrderNo, 8, 500)),
        ]);
        $callback = [
            'Event' => 'xpay_goods_deliver_notify',
            'OpenId' => 'openid-apple-contract',
            'OutTradeNo' => $externalOrderNo,
            'Env' => 0,
            'GoodsInfo' => [
                'ProductId' => 'mbti-report-full',
                'Quantity' => 1,
                'OrigPrice' => 499,
                'ActualPrice' => 499,
                'Attach' => $orderNo,
            ],
        ];
        $callbackRejected = app(AppleIapPaymentService::class)
            ->handleCallback($this->signedCallbackRequest($callback));
        $this->assertFalse((bool) ($callbackRejected['ok'] ?? true));
        $this->assertSame('PROVIDER_CHANNEL_MISMATCH', $callbackRejected['error_code'] ?? null);
        $this->assertSame(0, DB::table('payment_events')->where('order_no', $orderNo)->count());

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/'.$orderNo.'/apple-iap/reconcile')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PROVIDER_CHANNEL_MISMATCH');

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/'.$orderNo.'/apple-iap/reconcile')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PROVIDER_ENV_MISMATCH');
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/'.$orderNo.'/apple-iap/reconcile')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'REFUND_MISMATCH');
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/'.$orderNo.'/apple-iap/reconcile')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'REFUND_MISMATCH');
        $this->assertSame(0, DB::table('payment_events')->where('order_no', $orderNo)->count());
        $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $orderNo)->count());
    }

    private function configureProvider(): void
    {
        config()->set('payments.providers.apple_iap.enabled', true);
        config()->set('report_unlock.providers.apple_iap.available', true);
        config()->set('report_unlock.price_cents', 499);
        config()->set('report_unlock.currency', 'CNY');
        config()->set('report_unlock.sku_by_scale.MBTI', 'MBTI_REPORT_FULL');
        config()->set('payments.apple_iap', [
            'app_id' => 'wx-test-app',
            'app_secret' => 'wx-test-secret',
            'offer_id' => 'offer-test',
            'app_key' => 'production-app-key',
            'callback_token' => 'apple-callback-token',
            'environment' => 0,
            'mode' => 'short_series_goods',
            'product_id' => 'mbti-report-full',
            'sku' => 'MBTI_REPORT_FULL',
            'price_cents' => 499,
            'http_timeout_seconds' => 2,
        ]);
    }

    private function createAttempt(string $anonId, bool $withResult): string
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
            'channel' => 'wechat_miniapp',
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => 'MBTI',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'mbti_spec_v1',
        ]);
        if ($withResult) {
            Result::query()->create([
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => (string) $attempt->id,
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.3',
                'type_code' => 'INTJ-A',
                'scores_json' => ['EI' => ['sum' => 0]],
                'scores_pct' => ['EI' => 50],
                'axis_states' => ['EI' => 'clear'],
                'pack_id' => 'MBTI',
                'dir_version' => 'v1',
                'scoring_spec_version' => 'mbti_spec_v1',
                'report_engine_version' => 'v1',
                'is_valid' => true,
                'computed_at' => now(),
            ]);
        }

        return (string) $attempt->id;
    }

    private function seedSku(): void
    {
        DB::table('skus')->updateOrInsert(['sku' => 'MBTI_REPORT_FULL'], [
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'price_cents' => 499,
            'currency' => 'CNY',
            'is_active' => true,
            'meta_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function signedCallbackRequest(array $payload): Request
    {
        $timestamp = '1700000000';
        $nonce = 'nonce-apple-contract';
        $parts = ['apple-callback-token', $timestamp, $nonce];
        sort($parts, SORT_STRING);
        $query = http_build_query([
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => sha1(implode('', $parts)),
        ]);

        return Request::create(
            '/callback?'.$query,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function appleQueryResponse(string $externalOrderNo, int $status = 2, int $refundAmount = 499): array
    {
        $isRefund = $status === 8;

        return [
            'errcode' => 0,
            'errmsg' => 'ok',
            'order' => array_filter([
                'order_id' => $externalOrderNo,
                'channel_order_id' => 'apple-channel-bill-1',
                'wx_order_id' => 'wx-apple-order-1',
                'status' => $status,
                'order_type' => $isRefund ? 8 : 7,
                'env_type' => 1,
                'order_fee' => 499,
                'paid_fee' => 499,
                'refund_fee' => $isRefund ? $refundAmount : null,
                'paid_time' => 1700000000,
                'update_time' => $isRefund ? 1700000100 : 1700000050,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }
}
