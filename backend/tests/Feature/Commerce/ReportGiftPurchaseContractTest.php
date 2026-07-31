<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\WechatMiniVirtualPaymentService;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportGiftPurchaseContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureProvider();
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
        $this->seedSku();
    }

    public function test_only_owner_can_create_and_public_token_never_leaks_attempt_or_private_result(): void
    {
        $attemptId = $this->createAttempt('anon_gift_recipient');
        $ownerHeaders = $this->headersFor('anon_gift_recipient');
        $foreignHeaders = $this->headersFor('anon_gift_foreign');

        $this->withHeaders($foreignHeaders)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ATTEMPT_OWNER_MISMATCH');

        $created = $this->withHeaders($ownerHeaders)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('gift_request.status', 'pending');
        $token = (string) $created->json('gift_request.public_token');
        $giftId = (string) $created->json('gift_request.id');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);

        $stored = DB::table('report_gift_requests')->where('id', $giftId)->first();
        $this->assertNotNull($stored);
        $this->assertSame(hash('sha256', $token), (string) $stored->public_token_hash);
        $this->assertSame(64, strlen((string) $stored->public_token_hash));
        $this->assertStringNotContainsString($token, json_encode($stored, JSON_THROW_ON_ERROR));

        $this->withHeaders($foreignHeaders)
            ->getJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests/'.$giftId)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'GIFT_REQUEST_FORBIDDEN');
        $this->withHeaders($foreignHeaders)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests/'.$giftId.'/cancel')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'GIFT_REQUEST_FORBIDDEN');
        $this->withHeaders($ownerHeaders)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'GIFT_REQUEST_ACTIVE');

        $public = $this->getJson('/api/v0.3/report-gifts/'.$token)
            ->assertOk()
            ->assertJsonPath('gift.status', 'pending')
            ->assertJsonPath('gift.scale_code', 'MBTI')
            ->assertJsonPath('gift.price_cents', 499)
            ->assertJsonPath('gift.currency', 'CNY')
            ->assertJsonPath('gift.display_price', '¥4.99')
            ->assertJsonMissingPath('gift.target_attempt_id')
            ->assertJsonMissingPath('gift.recipient_user_id')
            ->assertJsonMissingPath('gift.recipient_anon_id')
            ->assertJsonMissingPath('gift.result');
        $this->assertStringNotContainsString($attemptId, $public->getContent());

        $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());

        $this->withHeaders($ownerHeaders)
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests/'.$giftId.'/cancel')
            ->assertOk()
            ->assertJsonPath('gift_request.status', 'canceled');
        $this->getJson('/api/v0.3/report-gifts/'.$token)
            ->assertOk()
            ->assertJsonPath('gift.status', 'canceled')
            ->assertJsonPath('gift.can_purchase', false);
        $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());
    }

    public function test_expired_and_iq_gift_requests_fail_closed_without_orders_or_grants(): void
    {
        $attemptId = $this->createAttempt('anon_gift_expired');
        $created = $this->withHeaders($this->headersFor('anon_gift_expired'))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');
        DB::table('report_gift_requests')
            ->where('id', (string) $created->json('gift_request.id'))
            ->update(['expires_at' => now()->subSecond()]);

        $this->withHeaders($this->headersFor('anon_gift_payer'))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-expired-order',
                'wx_login_code' => 'wx-login-expired',
            ])
            ->assertStatus(410)
            ->assertJsonPath('error_code', 'GIFT_REQUEST_EXPIRED');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('benefit_grants')->count());

        $iqAttemptId = $this->createAttempt('anon_gift_iq', 'IQ_RAVEN');
        $this->withHeaders($this->headersFor('anon_gift_iq'))
            ->postJson('/api/v0.3/attempts/'.$iqAttemptId.'/gift-requests')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'IQ_GIFT_DISABLED');
    }

    public function test_verified_friend_payment_grants_recipient_once_and_refund_revokes_it(): void
    {
        $recipientAnonId = 'anon_gift_recipient_paid';
        $payerAnonId = 'anon_gift_payer_paid';
        $otherPayerAnonId = 'anon_gift_other_payer';
        $attemptId = $this->createAttempt($recipientAnonId);
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');
        $giftId = (string) $created->json('gift_request.id');

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-gift-payer',
                'session_key' => 'session-key-gift-payer',
            ]),
        ]);
        $purchasePayload = [
            'idempotency_key' => 'gift-order-stable-key',
            'wx_login_code' => 'wx-login-gift-payer',
        ];
        $purchased = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', $purchasePayload)
            ->assertOk()
            ->assertJsonPath('pay.type', 'wechat_mini_virtual');
        $orderNo = (string) $purchased->json('order_no');
        $signData = json_decode((string) $purchased->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR);

        $duplicate = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', $purchasePayload)
            ->assertOk();
        $this->assertSame($orderNo, (string) $duplicate->json('order_no'));
        $this->assertSame(1, DB::table('orders')->where('order_no', $orderNo)->count());

        $this->withHeaders($this->headersFor($otherPayerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-order-race-loser',
                'wx_login_code' => 'wx-login-race-loser',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'GIFT_REQUEST_ALREADY_RESERVED');
        DB::table('report_gift_requests')->where('id', $giftId)->update(['expires_at' => now()->subSecond()]);
        $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'GIFT_REQUEST_ACTIVE');

        $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());
        $callbackPayload = $this->paidCallbackPayload($orderNo, (string) $signData['outTradeNo']);
        $handled = app(WechatMiniVirtualPaymentService::class)
            ->handleCallback($this->signedCallbackRequest($callbackPayload));
        $this->assertTrue(
            (bool) ($handled['ok'] ?? false),
            json_encode($handled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame('fulfilled', (string) DB::table('report_gift_requests')->where('id', $giftId)->value('status'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('order_no', $orderNo)
            ->where('status', 'active')
            ->count());
        $grant = DB::table('benefit_grants')->where('order_no', $orderNo)->first();
        $this->assertSame($recipientAnonId, (string) ($grant->benefit_ref ?? ''));
        $this->assertStringContainsString('"unlock_source":"gift_purchase"', (string) ($grant->meta_json ?? ''));

        app(WechatMiniVirtualPaymentService::class)
            ->handleCallback($this->signedCallbackRequest($callbackPayload));
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());

        $this->withHeaders($this->headersFor($payerAnonId))
            ->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access')
            ->assertNotFound();
        $this->withHeaders($this->headersFor($recipientAnonId))
            ->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access')
            ->assertOk()
            ->assertJsonPath('access_level', 'full')
            ->assertJsonPath('full_report_entitlement_v1.unlock_source', 'gift_purchase');

        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'gift-access-token',
                'expires_in' => 7200,
            ]),
            'https://api.weixin.qq.com/xpay/query_order*' => Http::response([
                'errcode' => 0,
                'order' => [
                    'order_id' => (string) $signData['outTradeNo'],
                    'wx_order_id' => 'wx-gift-provider-trade',
                    'status' => 8,
                    'order_fee' => 499,
                    'paid_fee' => 499,
                    'refund_fee' => 499,
                    'paid_time' => 1700000000,
                    'update_time' => 1700000100,
                ],
            ]),
        ]);
        $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/orders/'.$orderNo.'/wechat-mini-virtual/reconcile')
            ->assertOk()
            ->assertJsonPath('payment_state', 'refunded')
            ->assertJsonPath('grant_state', 'revoked');
        $this->assertSame('refunded', (string) DB::table('report_gift_requests')->where('id', $giftId)->value('status'));
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'revoked')->count());
    }

    public function test_gift_refund_never_revokes_a_concurrent_non_gift_entitlement(): void
    {
        $recipientAnonId = 'anon_gift_concurrent_recipient';
        $payerAnonId = 'anon_gift_concurrent_payer';
        $attemptId = $this->createAttempt($recipientAnonId);
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');
        $giftId = (string) $created->json('gift_request.id');

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-gift-payer',
                'session_key' => 'session-key-gift-concurrent',
            ]),
        ]);
        $purchased = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-concurrent-order',
                'wx_login_code' => 'wx-login-gift-concurrent',
            ])
            ->assertOk();
        $orderNo = (string) $purchased->json('order_no');
        $signData = json_decode((string) $purchased->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR);

        $selfGrant = app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            $recipientAnonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            null,
            'attempt',
            null,
            [],
            ['unlock_source' => 'self_purchase']
        );
        $this->assertTrue((bool) ($selfGrant['ok'] ?? false));

        $handled = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest($this->paidCallbackPayload($orderNo, (string) $signData['outTradeNo']))
        );
        $this->assertTrue(
            (bool) ($handled['ok'] ?? false),
            json_encode($handled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame(0, DB::table('benefit_grants')->where('order_no', $orderNo)->count());
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('status', 'active')->count());
        $concurrentGrant = DB::table('benefit_grants')->where('attempt_id', $attemptId)->first();
        $this->assertStringContainsString('"unlock_source":"self_purchase"', (string) ($concurrentGrant->meta_json ?? ''));
        $this->assertStringNotContainsString('"unlock_source":"gift_purchase"', (string) ($concurrentGrant->meta_json ?? ''));

        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'gift-concurrent-access-token',
                'expires_in' => 7200,
            ]),
            'https://api.weixin.qq.com/xpay/query_order*' => Http::response([
                'errcode' => 0,
                'order' => [
                    'order_id' => (string) $signData['outTradeNo'],
                    'wx_order_id' => 'wx-gift-provider-trade',
                    'status' => 8,
                    'order_fee' => 499,
                    'paid_fee' => 499,
                    'refund_fee' => 499,
                    'paid_time' => 1700000000,
                    'update_time' => 1700000100,
                ],
            ]),
        ]);
        $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/orders/'.$orderNo.'/wechat-mini-virtual/reconcile')
            ->assertOk()
            ->assertJsonPath('payment_state', 'refunded');

        $this->assertSame('refunded', (string) DB::table('report_gift_requests')->where('id', $giftId)->value('status'));
        $this->assertSame(1, DB::table('benefit_grants')->where('attempt_id', $attemptId)->where('status', 'active')->count());
    }

    public function test_repeat_gift_after_refund_reactivates_and_rebinds_the_revoked_grant(): void
    {
        $recipientAnonId = 'anon_gift_repeat_recipient';
        $attemptId = $this->createAttempt($recipientAnonId);
        $first = $this->createAndPayGift($attemptId, $recipientAnonId, 'anon_gift_repeat_payer_one', 'repeat-one');
        $this->refundGiftOrder(
            $first['order_no'],
            $first['provider_order_no'],
            'anon_gift_repeat_payer_one',
            'repeat-one'
        );

        $this->assertSame(1, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('status', 'revoked')
            ->count());

        $second = $this->createAndPayGift($attemptId, $recipientAnonId, 'anon_gift_repeat_payer_two', 'repeat-two');

        $grant = DB::table('benefit_grants')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($grant);
        $this->assertSame('active', (string) $grant->status);
        $this->assertSame($second['order_no'], (string) $grant->order_no);
        $this->assertStringContainsString(
            '"gift_request_id":"'.$second['gift_id'].'"',
            (string) ($grant->meta_json ?? '')
        );
        $this->withHeaders($this->headersFor($recipientAnonId))
            ->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access')
            ->assertOk()
            ->assertJsonPath('access_level', 'full')
            ->assertJsonPath('full_report_entitlement_v1.unlock_source', 'gift_purchase');
    }

    private function configureProvider(): void
    {
        config()->set('payments.providers.wechat_mini_virtual.enabled', true);
        config()->set('report_unlock.providers.wechat_mini_virtual.available', true);
        config()->set('report_unlock.providers.gift_purchase.available', true);
        config()->set('report_unlock.price_cents', 499);
        config()->set('report_unlock.currency', 'CNY');
        config()->set('report_unlock.sku_by_scale.MBTI', 'MBTI_REPORT_FULL');
        config()->set('payments.wechat_mini_virtual', [
            'app_id' => 'wx-test-app',
            'app_secret' => 'wx-test-secret',
            'offer_id' => 'offer-test',
            'app_key' => 'sandbox-app-key',
            'callback_token' => 'callback-token',
            'environment' => 1,
            'mode' => 'short_series_goods',
            'product_id' => 'mbti-report-full-sandbox',
            'sku' => 'MBTI_REPORT_FULL',
            'price_cents' => 499,
            'http_timeout_seconds' => 2,
        ]);
    }

    private function createAttempt(string $anonId, string $scaleCode = 'MBTI'): string
    {
        $attempt = Attempt::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 60,
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
        Result::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => (string) $attempt->id,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => $scaleCode === 'MBTI' ? 'INTJ-A' : 'IQ',
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'pack_id' => $scaleCode,
            'dir_version' => 'v1',
            'scoring_spec_version' => strtolower($scaleCode).'_spec_v1',
            'report_engine_version' => 'v1',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

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

    /**
     * @return array{gift_id:string,order_no:string,provider_order_no:string}
     */
    private function createAndPayGift(
        string $attemptId,
        string $recipientAnonId,
        string $payerAnonId,
        string $suffix
    ): array {
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');
        $giftId = (string) $created->json('gift_request.id');

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-gift-payer',
                'session_key' => 'session-key-'.$suffix,
            ]),
        ]);
        $purchased = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-order-'.$suffix,
                'wx_login_code' => 'wx-login-'.$suffix,
            ])
            ->assertOk();
        $orderNo = (string) $purchased->json('order_no');
        $signData = json_decode((string) $purchased->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR);
        $providerOrderNo = (string) $signData['outTradeNo'];
        $handled = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest($this->paidCallbackPayload($orderNo, $providerOrderNo))
        );
        $this->assertTrue(
            (bool) ($handled['ok'] ?? false),
            json_encode($handled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return [
            'gift_id' => $giftId,
            'order_no' => $orderNo,
            'provider_order_no' => $providerOrderNo,
        ];
    }

    private function refundGiftOrder(
        string $orderNo,
        string $providerOrderNo,
        string $payerAnonId,
        string $suffix
    ): void {
        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'gift-access-token-'.$suffix,
                'expires_in' => 7200,
            ]),
            'https://api.weixin.qq.com/xpay/query_order*' => Http::response([
                'errcode' => 0,
                'order' => [
                    'order_id' => $providerOrderNo,
                    'wx_order_id' => 'wx-gift-provider-trade-'.$suffix,
                    'status' => 8,
                    'order_fee' => 499,
                    'paid_fee' => 499,
                    'refund_fee' => 499,
                    'paid_time' => 1700000000,
                    'update_time' => 1700000100,
                ],
            ]),
        ]);
        $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/orders/'.$orderNo.'/wechat-mini-virtual/reconcile')
            ->assertOk()
            ->assertJsonPath('payment_state', 'refunded');
    }

    /**
     * @return array<string,string>
     */
    private function headersFor(string $anonId): array
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

        return [
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
            'X-Channel' => 'wechat_miniapp',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function paidCallbackPayload(string $orderNo, string $externalOrderNo): array
    {
        return [
            'ToUserName' => 'gh_gift',
            'FromUserName' => 'openid-gift-payer',
            'CreateTime' => 1700000000,
            'MsgType' => 'event',
            'Event' => 'xpay_goods_deliver_notify',
            'OpenId' => 'openid-gift-payer',
            'OutTradeNo' => $externalOrderNo,
            'Env' => 1,
            'WeChatPayInfo' => [
                'TransactionId' => 'wx-gift-'.hash('sha256', $externalOrderNo),
                'PaidTime' => 1700000000,
            ],
            'GoodsInfo' => [
                'ProductId' => 'mbti-report-full-sandbox',
                'Quantity' => 1,
                'OrigPrice' => 499,
                'ActualPrice' => 499,
                'Attach' => $orderNo,
            ],
        ];
    }

    private function signedCallbackRequest(array $payload): Request
    {
        $timestamp = '1700000000';
        $nonce = 'nonce-gift-contract';
        $parts = ['callback-token', $timestamp, $nonce];
        sort($parts, SORT_STRING);

        return Request::create(
            '/callback?'.http_build_query([
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'signature' => sha1(implode('', $parts)),
            ]),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
