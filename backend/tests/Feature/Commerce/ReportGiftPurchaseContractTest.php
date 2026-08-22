<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Jobs\Commerce\RefundOrderJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\Repair\OrderRepairService;
use App\Services\Commerce\ReportGiftService;
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
            ->assertJsonPath('gift.price_cents', 199)
            ->assertJsonPath('gift.currency', 'CNY')
            ->assertJsonPath('gift.display_price', '¥1.99')
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

    public function test_big_five_gift_requires_rollout_and_grants_only_after_verified_payment(): void
    {
        config()->set('report_unlock.big5_rollout.mode', 'allowlist_only');
        config()->set('report_unlock.big5_rollout.allowed_anon_ids', ['anon_big5_gift_recipient']);
        config()->set('payments.wechat_mini_virtual.products.BIG5_OCEAN.product_id', 'FullReport199');
        $this->seedBigFiveSku();
        $this->enableBigFivePaywall();

        $attemptId = $this->createAttempt('anon_big5_gift_recipient', 'BIG5_OCEAN');
        $created = $this->withHeaders($this->headersFor('anon_big5_gift_recipient'))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-gift-payer',
                'session_key' => 'session-key-big5-gift',
            ]),
        ]);
        $purchased = $this->withHeaders($this->headersFor('anon_big5_gift_payer'))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'big5-gift-order',
                'wx_login_code' => 'wx-login-big5-gift',
            ])
            ->assertOk();
        $orderNo = (string) $purchased->json('order_no');
        $signData = json_decode((string) $purchased->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('FullReport199', (string) $signData['productId']);
        $this->assertSame(199, (int) $signData['goodsPrice']);
        $this->assertSame(0, DB::table('benefit_grants')->where('attempt_id', $attemptId)->count());

        $payload = $this->paidCallbackPayload($orderNo, (string) $signData['outTradeNo']);
        $payload['GoodsInfo']['ProductId'] = 'FullReport199';
        $handled = app(WechatMiniVirtualPaymentService::class)
            ->handleCallback($this->signedCallbackRequest($payload));
        $this->assertTrue((bool) ($handled['ok'] ?? false));
        $this->assertSame('BIG5_FULL_REPORT', (string) DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->value('benefit_code'));

        $blockedAttemptId = $this->createAttempt('anon_big5_gift_not_allowlisted', 'BIG5_OCEAN');
        $this->withHeaders($this->headersFor('anon_big5_gift_not_allowlisted'))
            ->postJson('/api/v0.3/attempts/'.$blockedAttemptId.'/gift-requests')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ROLLOUT_DISABLED');
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
        $this->assertSame('gift_purchase', $this->grantMeta($grant)['unlock_source'] ?? null);

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
        $payerOrder = $this->withHeaders($this->headersFor($payerAnonId))
            ->getJson('/api/v0.3/orders/'.$orderNo)
            ->assertOk()
            ->assertJsonPath('attempt_id', null)
            ->assertJsonPath('delivery.can_view_report', false)
            ->assertJsonPath('delivery.report_url', null)
            ->assertJsonPath('delivery.can_download_pdf', false)
            ->assertJsonPath('delivery.report_pdf_url', null)
            ->assertJsonMissingPath('order')
            ->assertJsonMissingPath('full_report_access_v1');
        $this->assertStringNotContainsString($attemptId, $payerOrder->getContent());

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
                    'order_fee' => 199,
                    'paid_fee' => 199,
                    'refund_fee' => 199,
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

        $selfOrderNo = 'ord_gift_concurrent_self';
        $selfOrderId = $this->insertOrder($selfOrderNo, $attemptId, $recipientAnonId, 'billing');
        $selfGrant = app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            $recipientAnonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            $selfOrderNo,
            'attempt',
            null,
            [],
            ['unlock_source' => 'self_purchase']
        );
        $this->assertTrue((bool) ($selfGrant['ok'] ?? false));
        DB::table('benefit_grants')->where('attempt_id', $attemptId)->update(['source_order_id' => $selfOrderId]);

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
        $this->assertSame('self_purchase', $this->grantMeta($concurrentGrant)['unlock_source'] ?? null);

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
                    'order_fee' => 199,
                    'paid_fee' => 199,
                    'refund_fee' => 199,
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

        DB::table('orders')->where('id', $selfOrderId)->update([
            'status' => 'refunded',
            'payment_state' => 'refunded',
            'updated_at' => now(),
        ]);
        $revoked = app(EntitlementManager::class)->revokeByOrderNo(0, $selfOrderNo);
        $this->assertTrue((bool) ($revoked['ok'] ?? false));
        $this->assertSame(0, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->count());
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
        $this->assertSame($second['gift_id'], $this->grantMeta($grant)['gift_request_id'] ?? null);
        $this->withHeaders($this->headersFor($recipientAnonId))
            ->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access')
            ->assertOk()
            ->assertJsonPath('access_level', 'full')
            ->assertJsonPath('full_report_entitlement_v1.unlock_source', 'gift_purchase');
    }

    public function test_paid_gift_remains_durable_when_an_unrelated_order_is_refunded_later(): void
    {
        $recipientAnonId = 'anon_gift_durable_recipient';
        $payerAnonId = 'anon_gift_durable_payer';
        $attemptId = $this->createAttempt($recipientAnonId);
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-gift-payer',
                'session_key' => 'session-key-durable',
            ]),
        ]);
        $purchased = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-order-durable',
                'wx_login_code' => 'wx-login-durable',
            ])
            ->assertOk();
        $giftOrderNo = (string) $purchased->json('order_no');
        $signData = json_decode((string) $purchased->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR);

        $selfOrderNo = 'ord_gift_durable_self';
        $selfOrderId = $this->insertOrder($selfOrderNo, $attemptId, $recipientAnonId, 'billing');
        $selfGrant = app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            $recipientAnonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            $selfOrderNo,
            'attempt',
            now()->addHour()->toDateTimeString(),
            [],
            ['unlock_source' => 'self_purchase']
        );
        $this->assertTrue((bool) ($selfGrant['ok'] ?? false));
        DB::table('benefit_grants')->where('attempt_id', $attemptId)->update(['source_order_id' => $selfOrderId]);

        $handled = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest(
                $this->paidCallbackPayload($giftOrderNo, (string) $signData['outTradeNo'])
            )
        );
        $this->assertTrue((bool) ($handled['ok'] ?? false));

        $revoked = app(EntitlementManager::class)->revokeByOrderNo(0, $selfOrderNo);
        $this->assertTrue((bool) ($revoked['ok'] ?? false));
        $grant = DB::table('benefit_grants')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($grant);
        $this->assertSame('active', (string) $grant->status);
        $this->assertSame($giftOrderNo, (string) $grant->order_no);
        $this->assertNull($grant->expires_at);
        $this->assertSame('gift_purchase', $this->grantMeta($grant)['unlock_source'] ?? null);
        $this->withHeaders($this->headersFor($recipientAnonId))
            ->getJson('/api/v0.3/attempts/'.$attemptId.'/report-access')
            ->assertOk()
            ->assertJsonPath('access_level', 'full')
            ->assertJsonPath('full_report_entitlement_v1.unlock_source', 'gift_purchase');
    }

    public function test_non_terminal_payment_attempt_keeps_single_payable_gift_and_queued_refund_updates_state(): void
    {
        $recipientAnonId = 'anon_gift_terminal_recipient';
        $payerAnonId = 'anon_gift_terminal_payer';
        $attemptId = $this->createAttempt($recipientAnonId);
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');
        $giftId = (string) $created->json('gift_request.id');

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-gift-payer',
                'session_key' => 'session-key-terminal',
            ]),
        ]);
        $purchased = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-order-terminal',
                'wx_login_code' => 'wx-login-terminal',
            ])
            ->assertOk();
        $orderNo = (string) $purchased->json('order_no');
        $signData = json_decode((string) $purchased->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR);
        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($order);
        app(OrderManager::class)->transition($orderNo, 'canceled', 0, [
            'payment_state' => 'canceled',
            'closed_at' => now(),
        ]);
        app(ReportGiftService::class)->releaseReservationForOrder($order, 'canceled');
        $this->assertSame('purchasing', (string) DB::table('report_gift_requests')->where('id', $giftId)->value('status'));
        $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertConflict()
            ->assertJsonPath('error_code', 'GIFT_REQUEST_ACTIVE');

        $handled = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest($this->paidCallbackPayload($orderNo, (string) $signData['outTradeNo']))
        );
        $this->assertTrue(
            (bool) ($handled['ok'] ?? false),
            json_encode($handled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame('fulfilled', (string) DB::table('report_gift_requests')->where('id', $giftId)->value('status'));
        $this->assertSame('paid', (string) DB::table('orders')->where('order_no', $orderNo)->value('payment_state'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->count());

        $paid = $this->createAndPayGift(
            $this->createAttempt('anon_gift_queue_refund_recipient'),
            'anon_gift_queue_refund_recipient',
            'anon_gift_queue_refund_payer',
            'queue-refund'
        );
        $job = new RefundOrderJob(0, $paid['order_no'], 'gift queue refund', 'corr-gift-queue-refund');
        $job->handle(app(OrderManager::class), app(EntitlementManager::class));

        $this->assertSame('refunded', (string) DB::table('report_gift_requests')
            ->where('id', $paid['gift_id'])
            ->value('status'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('order_no', $paid['order_no'])
            ->where('status', 'revoked')
            ->count());
    }

    public function test_verified_terminal_payment_attempt_releases_gift_for_replacement(): void
    {
        $recipientAnonId = 'anon_gift_verified_terminal_recipient';
        $attemptId = $this->createAttempt($recipientAnonId);
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');
        $giftId = (string) $created->json('gift_request.id');

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-gift-payer',
                'session_key' => 'session-key-verified-terminal',
            ]),
        ]);
        $purchased = $this->withHeaders($this->headersFor('anon_gift_verified_terminal_payer'))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-order-verified-terminal',
                'wx_login_code' => 'wx-login-verified-terminal',
            ])
            ->assertOk();
        $orderNo = (string) $purchased->json('order_no');
        $signData = json_decode(
            (string) $purchased->json('pay.params.signData'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($order);
        $paymentAttempt = app(OrderManager::class)->latestPaymentAttemptForOrder($orderNo, 0);
        $this->assertNotNull($paymentAttempt);

        app(OrderManager::class)->advancePaymentAttempt((string) $paymentAttempt->id, [
            'state' => \App\Models\PaymentAttempt::STATE_CANCELED,
            'verified_at' => now(),
        ]);
        app(OrderManager::class)->transition($orderNo, 'canceled', 0, [
            'payment_state' => 'canceled',
            'closed_at' => now(),
        ]);
        app(ReportGiftService::class)->releaseReservationForOrder($order, 'canceled');

        $this->assertSame('canceled', (string) DB::table('report_gift_requests')
            ->where('id', $giftId)
            ->value('status'));
        $replacementCreated = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $replacementGiftId = (string) $replacementCreated->json('gift_request.id');
        $replacementToken = (string) $replacementCreated->json('gift_request.public_token');
        $replacementPurchased = $this->withHeaders($this->headersFor('anon_gift_replacement_payer'))
            ->postJson('/api/v0.3/report-gifts/'.$replacementToken.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-order-replacement',
                'wx_login_code' => 'wx-login-replacement',
            ])
            ->assertOk();
        $replacementOrderNo = (string) $replacementPurchased->json('order_no');
        $replacementSignData = json_decode(
            (string) $replacementPurchased->json('pay.params.signData'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $handled = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest(
                $this->paidCallbackPayload($orderNo, (string) $signData['outTradeNo'])
            )
        );
        $this->assertTrue(
            (bool) ($handled['ok'] ?? false),
            json_encode($handled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame('fulfilled', (string) DB::table('report_gift_requests')
            ->where('id', $giftId)
            ->value('status'));
        $this->assertSame('canceled', (string) DB::table('report_gift_requests')
            ->where('id', $replacementGiftId)
            ->value('status'));
        $this->assertSame('canceled', (string) DB::table('orders')
            ->where('order_no', $replacementOrderNo)
            ->value('payment_state'));
        $this->assertSame(\App\Models\PaymentAttempt::STATE_CANCELED, (string) DB::table('payment_attempts')
            ->where('order_no', $replacementOrderNo)
            ->value('state'));

        $replacementHandled = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest(
                $this->paidCallbackPayload(
                    $replacementOrderNo,
                    (string) $replacementSignData['outTradeNo']
                )
            )
        );
        $this->assertFalse((bool) ($replacementHandled['ok'] ?? false));
        $this->assertSame(
            'GIFT_REQUEST_NOT_PAYABLE',
            (string) ($replacementHandled['error_code'] ?? $replacementHandled['error'] ?? '')
        );
        $this->assertSame('fulfilled', (string) DB::table('report_gift_requests')
            ->where('id', $giftId)
            ->value('status'));
        $this->assertSame('canceled', (string) DB::table('report_gift_requests')
            ->where('id', $replacementGiftId)
            ->value('status'));
        $this->assertSame('canceled', (string) DB::table('orders')
            ->where('order_no', $replacementOrderNo)
            ->value('payment_state'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->count());

    }

    public function test_same_purchaser_retry_reuses_one_gift_payment_action(): void
    {
        $recipientAnonId = 'anon_gift_payment_retry_recipient';
        $payerAnonId = 'anon_gift_payment_retry_payer';
        $attemptId = $this->createAttempt($recipientAnonId);
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::sequence()
                ->push([
                    'openid' => 'openid-gift-payment-retry-payer',
                    'session_key' => 'session-key-payment-retry-one',
                ])
                ->push([
                    'openid' => 'openid-gift-payment-retry-payer',
                    'session_key' => 'session-key-payment-retry-two',
                ]),
        ]);
        $payload = [
            'idempotency_key' => 'gift-payment-action-retry',
            'wx_login_code' => 'wx-login-payment-retry',
        ];
        $first = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', $payload)
            ->assertOk();
        $second = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $firstSignData = json_decode(
            (string) $first->json('pay.params.signData'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $secondSignData = json_decode(
            (string) $second->json('pay.params.signData'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame((string) $first->json('order_no'), (string) $second->json('order_no'));
        $this->assertSame((string) $firstSignData['outTradeNo'], (string) $secondSignData['outTradeNo']);
        $this->assertSame(1, DB::table('payment_attempts')
            ->where('order_no', (string) $first->json('order_no'))
            ->count());
    }

    public function test_login_exchange_failure_releases_unpayable_order_and_allows_retry(): void
    {
        $recipientAnonId = 'anon_gift_login_failure_recipient';
        $payerAnonId = 'anon_gift_login_failure_payer';
        $attemptId = $this->createAttempt($recipientAnonId);
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');
        $giftId = (string) $created->json('gift_request.id');

        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['js_code'] ?? null) === 'valid-login-code') {
                return Http::response([
                    'openid' => 'openid-gift-payer',
                    'session_key' => 'session-key-login-retry',
                ]);
            }

            return Http::response([
                'errcode' => 40029,
                'errmsg' => 'invalid code',
            ]);
        });
        $failed = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-login-failure',
                'wx_login_code' => 'invalid-login-code',
            ]);
        $failed->assertJsonPath('ok', false);

        $gift = DB::table('report_gift_requests')->where('id', $giftId)->first();
        $this->assertNotNull($gift);
        $this->assertSame('pending', (string) $gift->status);
        $this->assertNull($gift->purchased_order_id);
        $this->assertSame('canceled', (string) DB::table('orders')
            ->where('target_attempt_id', $attemptId)
            ->value('payment_state'));
        $this->assertSame(0, DB::table('payment_attempts')->count());
        $failedOrderNo = (string) DB::table('orders')
            ->where('target_attempt_id', $attemptId)
            ->value('order_no');

        $staleOrder = DB::table('orders')
            ->where('target_attempt_id', $attemptId)
            ->first();
        $this->assertNotNull($staleOrder);
        $blocked = app(ReportGiftService::class)
            ->createWechatMiniVirtualPaymentAction($staleOrder, 'valid-login-code');
        $this->assertFalse((bool) ($blocked['ok'] ?? false));
        $this->assertSame('GIFT_REQUEST_NOT_PAYABLE', (string) ($blocked['error_code'] ?? ''));
        $this->assertSame(0, DB::table('payment_attempts')->count());

        $retry = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-login-failure',
                'wx_login_code' => 'valid-login-code',
            ]);
        $this->assertTrue((bool) $retry->json('ok'), $retry->getContent());
        $retryOrderNo = (string) $retry->json('order_no');
        $this->assertNotSame($failedOrderNo, $retryOrderNo);
        $this->assertSame($payerAnonId, (string) DB::table('orders')
            ->where('order_no', $retryOrderNo)
            ->value('anon_id'));
        $retrySignData = json_decode(
            (string) $retry->json('pay.params.signData'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $handled = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest(
                $this->paidCallbackPayload($retryOrderNo, (string) $retrySignData['outTradeNo'])
            )
        );
        $this->assertTrue(
            (bool) ($handled['ok'] ?? false),
            json_encode($handled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_repair_materializes_paid_gift_after_reused_grant_naturally_expires(): void
    {
        $recipientAnonId = 'anon_gift_natural_expiry_recipient';
        $payerAnonId = 'anon_gift_natural_expiry_payer';
        $attemptId = $this->createAttempt($recipientAnonId);
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $token = (string) $created->json('gift_request.public_token');

        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-gift-payer',
                'session_key' => 'session-key-natural-expiry',
            ]),
        ]);
        $purchased = $this->withHeaders($this->headersFor($payerAnonId))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-natural-expiry',
                'wx_login_code' => 'wx-login-natural-expiry',
            ])
            ->assertOk();
        $giftOrderNo = (string) $purchased->json('order_no');
        $signData = json_decode((string) $purchased->json('pay.params.signData'), true, flags: JSON_THROW_ON_ERROR);

        $selfOrderNo = 'ord_gift_natural_expiry_self';
        $selfOrderId = $this->insertOrder($selfOrderNo, $attemptId, $recipientAnonId, 'billing');
        $selfGrant = app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            $recipientAnonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            $selfOrderNo,
            'attempt',
            now()->addHour()->toDateTimeString(),
            [],
            ['unlock_source' => 'self_purchase']
        );
        $this->assertTrue((bool) ($selfGrant['ok'] ?? false));
        DB::table('benefit_grants')->where('attempt_id', $attemptId)->update(['source_order_id' => $selfOrderId]);

        $handled = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest(
                $this->paidCallbackPayload($giftOrderNo, (string) $signData['outTradeNo'])
            )
        );
        $this->assertTrue(
            (bool) ($handled['ok'] ?? false),
            json_encode($handled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame($selfOrderNo, (string) DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->value('order_no'));

        DB::table('benefit_grants')->where('attempt_id', $attemptId)->update([
            'expires_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);
        $giftOrder = DB::table('orders')->where('order_no', $giftOrderNo)->first();
        $this->assertNotNull($giftOrder);
        $this->assertTrue(app(OrderRepairService::class)->requiresPaidOrderRepair($giftOrder));
        $this->artisan('commerce:repair-paid-orders', [
            '--order' => $giftOrderNo,
            '--older_than_minutes' => 0,
            '--json' => 1,
        ])->assertExitCode(0);

        $grant = DB::table('benefit_grants')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($grant);
        $this->assertSame('active', (string) $grant->status);
        $this->assertSame($giftOrderNo, (string) $grant->order_no);
        $this->assertNull($grant->expires_at);
        $this->assertSame('gift_purchase', $this->grantMeta($grant)['unlock_source'] ?? null);
    }

    public function test_manual_benefit_revocation_does_not_mislabel_paid_gift_as_refunded(): void
    {
        $attemptId = $this->createAttempt('anon_gift_manual_revoke_recipient');
        $paid = $this->createAndPayGift(
            $attemptId,
            'anon_gift_manual_revoke_recipient',
            'anon_gift_manual_revoke_payer',
            'manual-revoke'
        );

        $revoked = app(EntitlementManager::class)->revokeByOrderNo(0, $paid['order_no']);
        $this->assertTrue((bool) ($revoked['ok'] ?? false));
        $this->assertSame('fulfilled', (string) DB::table('report_gift_requests')
            ->where('id', $paid['gift_id'])
            ->value('status'));
        $this->assertSame('paid', (string) DB::table('orders')
            ->where('order_no', $paid['order_no'])
            ->value('payment_state'));
        $this->assertSame('revoked', (string) DB::table('orders')
            ->where('order_no', $paid['order_no'])
            ->value('grant_state'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('order_no', $paid['order_no'])
            ->where('status', 'revoked')
            ->count());

        $replayedPayload = $this->paidCallbackPayload(
            $paid['order_no'],
            $paid['provider_order_no']
        );
        $replayedPayload['WeChatPayInfo']['TransactionId'] = 'wx-gift-manual-revoke-replay';
        $replayed = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest($replayedPayload)
        );
        $this->assertTrue(
            (bool) ($replayed['ok'] ?? false),
            json_encode($replayed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->assertTrue((bool) ($replayed['duplicate'] ?? false));
        $this->assertSame('revoked', (string) DB::table('orders')
            ->where('order_no', $paid['order_no'])
            ->value('grant_state'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('order_no', $paid['order_no'])
            ->where('status', 'revoked')
            ->count());

        $giftOrder = DB::table('orders')->where('order_no', $paid['order_no'])->first();
        $this->assertNotNull($giftOrder);
        $this->assertFalse(app(OrderRepairService::class)->requiresPaidOrderRepair($giftOrder));
        $this->artisan('commerce:repair-paid-orders', [
            '--order' => $paid['order_no'],
            '--older_than_minutes' => 0,
            '--json' => 1,
        ])->assertExitCode(0);
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('order_no', $paid['order_no'])
            ->where('status', 'revoked')
            ->count());
    }

    public function test_manual_gift_revocation_without_direct_grant_preserves_unrelated_entitlement(): void
    {
        $recipientAnonId = 'anon_gift_manual_unbound_recipient';
        $attemptId = $this->createAttempt($recipientAnonId);
        $created = $this->withHeaders($this->headersFor($recipientAnonId))
            ->postJson('/api/v0.3/attempts/'.$attemptId.'/gift-requests')
            ->assertCreated();
        $giftId = (string) $created->json('gift_request.id');
        $token = (string) $created->json('gift_request.public_token');
        Http::fake([
            'https://api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'openid' => 'openid-gift-payer',
                'session_key' => 'session-key-manual-unbound-revoke',
            ]),
        ]);
        $purchased = $this->withHeaders($this->headersFor('anon_gift_manual_unbound_payer'))
            ->postJson('/api/v0.3/report-gifts/'.$token.'/orders/wechat_mini_virtual', [
                'idempotency_key' => 'gift-order-manual-unbound-revoke',
                'wx_login_code' => 'wx-login-manual-unbound-revoke',
            ])
            ->assertOk();
        $giftOrderNo = (string) $purchased->json('order_no');
        $signData = json_decode(
            (string) $purchased->json('pay.params.signData'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $selfOrderNo = 'ord_gift_manual_unbound_self';
        $selfOrderId = $this->insertOrder($selfOrderNo, $attemptId, $recipientAnonId, 'billing');
        $selfGrant = app(EntitlementManager::class)->grantAttemptUnlock(
            0,
            null,
            $recipientAnonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            $selfOrderNo,
            'attempt',
            null,
            [],
            ['unlock_source' => 'self_purchase']
        );
        $this->assertTrue((bool) ($selfGrant['ok'] ?? false));
        DB::table('benefit_grants')->where('attempt_id', $attemptId)->update([
            'source_order_id' => $selfOrderId,
        ]);

        $handled = app(WechatMiniVirtualPaymentService::class)->handleCallback(
            $this->signedCallbackRequest(
                $this->paidCallbackPayload($giftOrderNo, (string) $signData['outTradeNo'])
            )
        );
        $this->assertTrue(
            (bool) ($handled['ok'] ?? false),
            json_encode($handled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame(0, DB::table('benefit_grants')
            ->where('order_no', $giftOrderNo)
            ->count());

        $revoked = app(EntitlementManager::class)->revokeByOrderNo(0, $giftOrderNo);
        $this->assertTrue((bool) ($revoked['ok'] ?? false));
        $this->assertSame(0, (int) ($revoked['revoked'] ?? -1));
        $this->assertTrue((bool) ($revoked['gift_order_without_direct_grant'] ?? false));
        $this->assertSame('fulfilled', (string) DB::table('report_gift_requests')
            ->where('id', $giftId)
            ->value('status'));
        $this->assertSame('paid', (string) DB::table('orders')
            ->where('order_no', $giftOrderNo)
            ->value('payment_state'));
        $this->assertSame('active', (string) DB::table('benefit_grants')
            ->where('order_no', $selfOrderNo)
            ->value('status'));
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->count());

        DB::table('orders')->where('id', $selfOrderId)->update([
            'status' => 'refunded',
            'payment_state' => 'refunded',
            'updated_at' => now(),
        ]);
        $selfRevoked = app(EntitlementManager::class)->revokeByOrderNo(0, $selfOrderNo);
        $this->assertTrue((bool) ($selfRevoked['ok'] ?? false));
        $this->assertSame(0, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->count());
        $this->assertSame('fulfilled', (string) DB::table('report_gift_requests')
            ->where('id', $giftId)
            ->value('status'));
        $this->assertSame('revoked', (string) DB::table('orders')
            ->where('order_no', $giftOrderNo)
            ->value('grant_state'));
    }

    public function test_paid_order_repair_reloads_refunded_gift_inside_transaction(): void
    {
        $attemptId = $this->createAttempt('anon_gift_stale_repair_recipient');
        $paid = $this->createAndPayGift(
            $attemptId,
            'anon_gift_stale_repair_recipient',
            'anon_gift_stale_repair_payer',
            'stale-repair'
        );
        $stalePaidOrder = DB::table('orders')->where('order_no', $paid['order_no'])->first();
        $this->assertNotNull($stalePaidOrder);

        $job = new RefundOrderJob(0, $paid['order_no'], 'gift stale repair refund', 'corr-gift-stale-repair');
        $job->handle(app(OrderManager::class), app(EntitlementManager::class));

        $repair = app(OrderRepairService::class)->repairPaidOrder($stalePaidOrder);
        $this->assertTrue((bool) ($repair['ok'] ?? false));
        $this->assertTrue((bool) ($repair['skipped'] ?? false));
        $this->assertSame('payment_not_paid', (string) ($repair['reason'] ?? ''));
        $this->assertSame('refunded', (string) DB::table('report_gift_requests')
            ->where('id', $paid['gift_id'])
            ->value('status'));
        $this->assertSame(0, DB::table('benefit_grants')
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->count());
    }

    private function configureProvider(): void
    {
        config()->set('payments.providers.wechat_mini_virtual.enabled', true);
        config()->set('report_unlock.providers.wechat_mini_virtual.available', true);
        config()->set('report_unlock.providers.gift_purchase.available', true);
        config()->set('report_unlock.price_cents', 199);
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
            'price_cents' => 199,
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
            'meta_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enableBigFivePaywall(): void
    {
        $scale = DB::table('scales_registry')->where('org_id', 0)->where('code', 'BIG5_OCEAN')->first();
        $this->assertNotNull($scale);
        $capabilities = json_decode((string) $scale->capabilities_json, true, flags: JSON_THROW_ON_ERROR);
        $commercial = json_decode((string) $scale->commercial_json, true, flags: JSON_THROW_ON_ERROR);
        $capabilities['paywall_mode'] = 'full';
        $commercial['report_unlock_sku'] = 'SKU_BIG5_FULL_REPORT_199';
        $commercial['report_benefit_code'] = 'BIG5_FULL_REPORT';
        DB::table('scales_registry')->where('org_id', 0)->where('code', 'BIG5_OCEAN')->update([
            'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'commercial_json' => json_encode($commercial, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function insertOrder(string $orderNo, string $attemptId, string $anonId, string $provider): string
    {
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'effective_sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 199,
            'amount_total' => 199,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => 'fulfilled',
            'payment_state' => 'paid',
            'provider' => $provider,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
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
                    'order_fee' => 199,
                    'paid_fee' => 199,
                    'refund_fee' => 199,
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
                'OrigPrice' => 199,
                'ActualPrice' => 199,
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

    /** @return array<string,mixed> */
    private function grantMeta(object $grant): array
    {
        $meta = $grant->meta_json ?? null;
        if (is_array($meta)) {
            return $meta;
        }

        return is_string($meta) && trim($meta) !== ''
            ? (array) json_decode($meta, true, 512, JSON_THROW_ON_ERROR)
            : [];
    }
}
