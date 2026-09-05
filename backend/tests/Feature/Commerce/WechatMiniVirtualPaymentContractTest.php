<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\PaymentGateway\WechatMiniVirtualGateway;
use App\Services\Commerce\WechatMiniVirtualPaymentService;
use App\Services\Payments\PaymentProviderRegistry;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WechatMiniVirtualPaymentContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_fails_closed_until_every_contract_field_and_rollout_gate_exist(): void
    {
        config()->set('payments.providers.wechat_mini_virtual.enabled', true);
        config()->set('report_unlock.providers.wechat_mini_virtual.available', false);

        $this->assertFalse(app(PaymentProviderRegistry::class)->isEnabled(WechatMiniVirtualGateway::PROVIDER));

        $this->configureProvider();
        config()->set('payments.wechat_mini_virtual.product_id', '');

        $this->assertFalse(app(PaymentProviderRegistry::class)->isEnabled(WechatMiniVirtualGateway::PROVIDER));

        config()->set('payments.wechat_mini_virtual.product_id', 'mbti-report-full-sandbox');

        $this->assertTrue(app(PaymentProviderRegistry::class)->isEnabled(WechatMiniVirtualGateway::PROVIDER));
    }

    public function test_payment_action_uses_backend_price_and_never_persists_session_key_or_plain_openid(): void
    {
        $this->configureProvider();
        $attemptId = $this->createAttempt();
        $this->seedSku();
        $created = app(OrderManager::class)->createOrder(
            0,
            null,
            'anon_vpay_contract',
            'MBTI_REPORT_FULL_199',
            1,
            $attemptId,
            WechatMiniVirtualGateway::PROVIDER,
            'idem-vpay-contract-1',
            'vpay-contract@example.com',
            null,
            [],
            [],
            [
                'channel' => 'wechat_miniapp',
                'provider_app' => 'wx-test-app',
            ]
        );
        $this->assertTrue((bool) ($created['ok'] ?? false));

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-test-contract',
                'session_key' => 'session-key-test-contract',
            ]),
        ]);

        $result = app(WechatMiniVirtualPaymentService::class)->createPaymentAction(
            $created['order'],
            'wx-login-code-once'
        );

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $signDataJson = (string) data_get($result, 'pay.params.signData');
        $signData = json_decode($signDataJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(199, $signData['goodsPrice']);
        $this->assertSame('CNY', $signData['currencyType']);
        $this->assertSame('mbti-report-full-sandbox', $signData['productId']);
        $this->assertMatchesRegularExpression('/^fm[a-zA-Z0-9]{30}$/i', $signData['outTradeNo']);
        $this->assertSame(
            hash_hmac('sha256', 'requestVirtualPayment&'.$signDataJson, 'sandbox-app-key'),
            data_get($result, 'pay.params.paySig')
        );
        $this->assertSame(
            hash_hmac('sha256', $signDataJson, 'session-key-test-contract'),
            data_get($result, 'pay.params.signature')
        );

        $paymentAttempt = DB::table('payment_attempts')->where('order_no', $created['order_no'])->first();
        $this->assertNotNull($paymentAttempt);
        $stored = (string) ($paymentAttempt->payload_meta_json ?? '');
        $this->assertStringNotContainsString('session-key-test-contract', $stored);
        $this->assertStringNotContainsString('openid-test-contract', $stored);
        $this->assertSame($signData['outTradeNo'], (string) ($paymentAttempt->external_trade_no ?? ''));
        $this->assertSame(
            $signData['outTradeNo'],
            (string) DB::table('orders')->where('order_no', $created['order_no'])->value('external_trade_no')
        );
    }

    public function test_big_five_payment_uses_the_shared_199_product_contract(): void
    {
        $this->configureProvider();
        config()->set('report_unlock.big5_rollout.mode', 'allowlist_only');
        config()->set('report_unlock.big5_rollout.allowed_anon_ids', ['anon_vpay_big5']);
        (new ScaleRegistrySeeder)->run();
        $this->setBigFivePaywallMode('full');
        $attemptId = $this->createAttempt(true, 'anon_vpay_big5', 'BIG5_OCEAN');
        $this->seedBigFiveSku();

        $eligibility = app(WechatMiniVirtualPaymentService::class)->validateOrderEligibility(
            0,
            null,
            'anon_vpay_big5',
            $attemptId,
            'SKU_BIG5_FULL_REPORT_199',
            1,
        );
        $this->assertTrue((bool) ($eligibility['ok'] ?? false), json_encode($eligibility, JSON_THROW_ON_ERROR));

        $created = app(OrderManager::class)->createOrder(
            0,
            null,
            'anon_vpay_big5',
            'SKU_BIG5_FULL_REPORT_199',
            1,
            $attemptId,
            WechatMiniVirtualGateway::PROVIDER,
            'idem-vpay-big5-contract',
            null,
            null,
            [],
            [],
            ['channel' => 'wechat_miniapp'],
        );
        $this->assertTrue((bool) ($created['ok'] ?? false));
        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-big5-contract',
                'session_key' => 'session-key-big5-contract',
            ]),
        ]);

        $payment = app(WechatMiniVirtualPaymentService::class)->createPaymentAction($created['order'], 'wx-big5-code');
        $this->assertTrue((bool) ($payment['ok'] ?? false));
        $signData = json_decode((string) data_get($payment, 'pay.params.signData'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('mbti-report-full-sandbox', $signData['productId']);
        $this->assertSame(199, $signData['goodsPrice']);
    }

    public function test_callback_signature_and_payload_normalization_are_provider_specific(): void
    {
        $this->configureProvider();
        $orderNo = 'ord_'.Str::uuid();
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'anon_id' => 'anon_vpay_callback',
            'sku' => 'MBTI_REPORT_FULL_199',
            'item_sku' => 'MBTI_REPORT_FULL_199',
            'quantity' => 1,
            'target_attempt_id' => (string) Str::uuid(),
            'amount_cents' => 199,
            'amount_total' => 199,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => 'pending',
            'provider' => WechatMiniVirtualGateway::PROVIDER,
            'external_trade_no' => 'fmcallbackcontract00000000000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $timestamp = '1700000000';
        $nonce = 'nonce-contract';
        $parts = ['callback-token', $timestamp, $nonce];
        sort($parts, SORT_STRING);
        $signature = sha1(implode('', $parts));
        $request = Request::create('/callback?'.http_build_query([
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature,
        ]), 'POST');
        $gateway = new WechatMiniVirtualGateway;
        $this->assertTrue($gateway->verifySignature($request));

        $normalized = $gateway->normalizePayload([
            'OutTradeNo' => 'fmcallbackcontract00000000000001',
            'Env' => 1,
            'GoodsInfo' => [
                'ProductId' => 'mbti-report-full-sandbox',
                'ActualPrice' => 199,
            ],
            'WeChatPayInfo' => [
                'TransactionId' => 'wx-provider-trade-1',
                'PaidTime' => 1700000000,
            ],
        ]);
        $this->assertSame($orderNo, $normalized['order_no']);
        $this->assertSame(199, $normalized['amount_cents']);
        $this->assertSame('CNY', $normalized['currency']);
        $this->assertSame('payment_succeeded', $normalized['event_type']);

        $invalid = Request::create('/callback?'.http_build_query([
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => str_repeat('0', 40),
        ]), 'POST');
        $this->assertFalse($gateway->verifySignature($invalid));
    }

    public function test_message_push_endpoint_echoes_challenge_only_for_a_valid_signature(): void
    {
        config()->set('payments.wechat_mini_virtual.callback_token', 'callback-token');

        $timestamp = '1700000000';
        $nonce = 'nonce-endpoint-verification';
        $parts = ['callback-token', $timestamp, $nonce];
        sort($parts, SORT_STRING);
        $query = [
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => sha1(implode('', $parts)),
            'echostr' => 'wechat-endpoint-ready',
        ];

        $this->get('/api/v0.3/webhooks/payment/wechat_mini_virtual?'.http_build_query($query))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('wechat-endpoint-ready');

        $query['signature'] = str_repeat('0', 40);
        $this->get('/api/v0.3/webhooks/payment/wechat_mini_virtual?'.http_build_query($query))
            ->assertStatus(400)
            ->assertJsonPath('error_code', 'INVALID_SIGNATURE');
    }

    public function test_http_order_callback_idempotency_and_refund_reconciliation_follow_existing_ledger(): void
    {
        $this->configureProvider();
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
        $this->setReportPaywall('MBTI', 'MBTI_REPORT_FULL_199');
        $this->seedSku();
        $attemptId = $this->createAttempt(true);
        $otherAttemptId = $this->createAttempt(true, 'anon_other_vpay');
        $token = $this->issueAnonToken('anon_vpay_contract');
        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-http-contract',
                'session_key' => 'session-key-http-contract',
            ]),
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => 'anon_vpay_contract',
            'X-Channel' => 'wechat_miniapp',
        ];
        $basePayload = [
            'sku' => 'MBTI_REPORT_FULL_199',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'idempotency_key' => 'idem-http-vpay-1',
            'wx_login_code' => 'wx-login-http-contract',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/wechat_mini_virtual', array_merge($basePayload, ['amount_cents' => 1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount_cents']);
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/wechat_mini_virtual', array_merge($basePayload, ['sku' => 'OTHER_SKU']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SKU_NOT_SUPPORTED');
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/wechat_mini_virtual', array_merge($basePayload, ['target_attempt_id' => $otherAttemptId]))
            ->assertStatus(404);

        $created = $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/wechat_mini_virtual', $basePayload);
        $created
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pay.type', WechatMiniVirtualGateway::PROVIDER);
        $orderNo = (string) $created->json('order_no');
        $externalOrderNo = (string) data_get(
            json_decode((string) $created->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR),
            'outTradeNo'
        );
        $this->assertSame(199, (int) DB::table('orders')->where('order_no', $orderNo)->value('amount_cents'));

        $duplicateCreate = $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/wechat_mini_virtual', $basePayload)
            ->assertOk();
        $this->assertSame($orderNo, (string) $duplicateCreate->json('order_no'));
        $this->assertSame(1, DB::table('orders')->where('order_no', $orderNo)->count());
        $this->assertSame(1, DB::table('payment_attempts')->where('order_no', $orderNo)->count());

        foreach (range(1, 4) as $index) {
            DB::table('orders')->insert([
                'id' => (string) Str::uuid(),
                'order_no' => 'ord_membership_callback_'.$index.'_'.Str::uuid(),
                'org_id' => 0,
                'user_id' => null,
                'anon_id' => 'anon_vpay_contract',
                'sku' => 'MBTI_REPORT_FULL_199',
                'item_sku' => 'MBTI_REPORT_FULL_199',
                'quantity' => 1,
                'amount_cents' => 199,
                'amount_total' => 199,
                'amount_refunded' => 0,
                'currency' => 'CNY',
                'status' => 'fulfilled',
                'payment_state' => 'paid',
                'grant_state' => 'granted',
                'provider' => WechatMiniVirtualGateway::PROVIDER,
                'paid_at' => now()->subMinutes($index),
                'created_at' => now()->subMinutes($index),
                'updated_at' => now(),
            ]);
        }

        $callbackPayload = [
            'ToUserName' => 'gh_contract',
            'FromUserName' => 'openid-http-contract',
            'CreateTime' => 1700000000,
            'MsgType' => 'event',
            'Event' => 'xpay_goods_deliver_notify',
            'OpenId' => 'openid-http-contract',
            'OutTradeNo' => $externalOrderNo,
            'Env' => 1,
            'WeChatPayInfo' => [
                'TransactionId' => 'wx-http-provider-trade-1',
                'PaidTime' => 1700000000,
            ],
            'GoodsInfo' => [
                'ProductId' => 'mbti-report-full-sandbox',
                'Quantity' => 1,
                'OrigPrice' => 199,
                'ActualPrice' => 199,
                'Attach' => $orderNo,
            ],
        ];
        $callback = $this->signedCallbackRequest($callbackPayload);
        $amountMismatchPayload = $callbackPayload;
        $amountMismatchPayload['GoodsInfo']['ActualPrice'] = 1;
        $amountMismatch = app(WechatMiniVirtualPaymentService::class)
            ->handleCallback($this->signedCallbackRequest($amountMismatchPayload));
        $this->assertFalse((bool) ($amountMismatch['ok'] ?? true));
        $this->assertSame('AMOUNT_MISMATCH', $amountMismatch['error_code'] ?? null);
        $this->assertSame(0, DB::table('payment_events')->where('provider', WechatMiniVirtualGateway::PROVIDER)->count());

        $handled = app(WechatMiniVirtualPaymentService::class)->handleCallback($callback);
        $this->assertTrue((bool) ($handled['ok'] ?? false));
        $duplicate = app(WechatMiniVirtualPaymentService::class)->handleCallback($callback);
        $this->assertTrue((bool) ($duplicate['ok'] ?? false));
        $this->assertTrue((bool) ($duplicate['duplicate'] ?? false));
        $this->assertSame(1, DB::table('payment_events')->where('provider', WechatMiniVirtualGateway::PROVIDER)->count());
        $storedEventPayload = (string) DB::table('payment_events')
            ->where('provider', WechatMiniVirtualGateway::PROVIDER)
            ->value('payload_json');
        $this->assertStringNotContainsString('openid-http-contract', $storedEventPayload);
        $this->assertStringNotContainsString('session-key-http-contract', $storedEventPayload);
        $this->assertStringNotContainsString('access-token-contract', $storedEventPayload);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
        $this->assertSame('fulfilled', (string) DB::table('orders')->where('order_no', $orderNo)->value('status'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('benefit_code', 'FERMAT_MEMBER')
            ->where('benefit_ref', 'anon_vpay_contract')
            ->where('status', 'active')
            ->where('meta_json', 'like', '%"granted_via":"five_paid_reports"%')
            ->count());

        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'access-token-contract',
                'expires_in' => 7200,
            ]),
            'https://api.weixin.qq.com/xpay/query_order*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'order' => [
                    'order_id' => $externalOrderNo,
                    'wx_order_id' => 'wx-http-provider-trade-1',
                    'status' => 8,
                    'order_fee' => 199,
                    'paid_fee' => 199,
                    'refund_fee' => 199,
                    'paid_time' => 1700000000,
                    'update_time' => 1700000100,
                ],
            ]),
        ]);
        $this->withHeaders($headers)
            ->postJson('/api/v0.3/orders/'.$orderNo.'/wechat-mini-virtual/reconcile')
            ->assertOk()
            ->assertJsonPath('provider_status', 8)
            ->assertJsonPath('payment_state', 'refunded')
            ->assertJsonPath('grant_state', 'revoked');
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'revoked')->count());
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('benefit_code', 'FERMAT_MEMBER')
            ->where('benefit_ref', 'anon_vpay_contract')
            ->where('status', 'revoked')
            ->where('meta_json', 'like', '%"granted_via":"five_paid_reports"%')
            ->count());
    }

    private function configureProvider(): void
    {
        config()->set('payments.providers.wechat_mini_virtual.enabled', true);
        config()->set('report_unlock.providers.wechat_mini_virtual.available', true);
        config()->set('report_unlock.price_cents', 199);
        config()->set('report_unlock.currency', 'CNY');
        config()->set('report_unlock.sku_by_scale.MBTI', 'MBTI_REPORT_FULL_199');
        config()->set('payments.wechat_mini_virtual', [
            'app_id' => 'wx-test-app',
            'app_secret' => 'wx-test-secret',
            'offer_id' => 'offer-test',
            'app_key' => 'sandbox-app-key',
            'callback_token' => 'callback-token',
            'environment' => 1,
            'mode' => 'short_series_goods',
            'product_id' => 'mbti-report-full-sandbox',
            'sku' => 'MBTI_REPORT_FULL_199',
            'price_cents' => 199,
            'products' => [
                'BIG5_OCEAN' => [
                    'product_id' => 'mbti-report-full-sandbox',
                ],
            ],
            'http_timeout_seconds' => 2,
        ]);
    }

    private function createAttempt(bool $withResult = false, string $anonId = 'anon_vpay_contract', string $scaleCode = 'MBTI'): string
    {
        $attempt = Attempt::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => $scaleCode === 'BIG5_OCEAN' ? 120 : 60,
            'answers_summary_json' => ['stage' => 'seed'],
            'client_platform' => 'test',
            'channel' => 'wechat_miniapp',
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => strtolower($scaleCode).'_spec_v1',
        ]);
        if ($withResult) {
            Result::query()->create([
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => (string) $attempt->id,
                'scale_code' => $scaleCode,
                'scale_version' => 'v0.3',
                'type_code' => $scaleCode === 'MBTI' ? 'INTJ-A' : 'BIG5',
                'scores_json' => [
                    'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                ],
                'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
                'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
                'pack_id' => $scaleCode,
                'dir_version' => 'v1',
                'scoring_spec_version' => strtolower($scaleCode).'_spec_v1',
                'report_engine_version' => 'v1',
                'is_valid' => true,
                'computed_at' => now(),
            ]);
        }

        return (string) $attempt->id;
    }

    private function seedSku(): void
    {
        DB::table('skus')->updateOrInsert(['sku' => 'MBTI_REPORT_FULL_199'], [
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'price_cents' => 199,
            'currency' => 'CNY',
            'is_active' => true,
            'meta_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBigFiveSku(): void
    {
        DB::table('skus')->updateOrInsert(['sku' => 'SKU_BIG5_FULL_REPORT_199'], [
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'kind' => 'report_unlock',
            'unit_qty' => 1,
            'benefit_code' => 'BIG5_FULL_REPORT',
            'scope' => 'attempt',
            'price_cents' => 199,
            'currency' => 'CNY',
            'is_active' => true,
            'meta_json' => json_encode(['effective_default' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setBigFivePaywallMode(string $mode): void
    {
        $scale = DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'BIG5_OCEAN')->first();
        $capabilities = json_decode((string) ($scale->capabilities_json ?? '{}'), true);
        $capabilities = is_array($capabilities) ? $capabilities : [];
        $capabilities['paywall_mode'] = $mode;
        DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'BIG5_OCEAN')->update([
            'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function setReportPaywall(string $scaleCode, string $sku): void
    {
        $scale = DB::table('scales_registry_v2')->where('org_id', 0)->where('code', $scaleCode)->first();
        $capabilities = json_decode((string) ($scale->capabilities_json ?? '{}'), true);
        $commercial = json_decode((string) ($scale->commercial_json ?? '{}'), true);
        $viewPolicy = json_decode((string) ($scale->view_policy_json ?? '{}'), true);
        $capabilities = is_array($capabilities) ? $capabilities : [];
        $commercial = is_array($commercial) ? $commercial : [];
        $viewPolicy = is_array($viewPolicy) ? $viewPolicy : [];
        $capabilities['paywall_mode'] = 'full';
        $commercial['report_unlock_sku'] = $sku;
        $viewPolicy['upgrade_sku'] = $sku;
        $viewPolicy['blur_others'] = true;
        DB::table('scales_registry_v2')->where('org_id', 0)->where('code', $scaleCode)->update([
            'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
            'commercial_json' => json_encode($commercial, JSON_UNESCAPED_SLASHES),
            'view_policy_json' => json_encode($viewPolicy, JSON_UNESCAPED_SLASHES),
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
        $nonce = 'nonce-http-contract';
        $parts = ['callback-token', $timestamp, $nonce];
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
}
