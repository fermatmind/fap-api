<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Commerce\Compensation\Gateways\AlipayLifecycleGateway;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Yansongda\Pay\Pay;

use function Yansongda\Artful\filter_params;

final class AlipayReturnRecoveryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Pay::clear();
    }

    protected function tearDown(): void
    {
        Pay::clear();

        parent::tearDown();
    }

    public function test_recover_alipay_return_returns_tokenized_wait_context_for_valid_signed_query(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'app.frontend_url' => 'https://web.example.test',
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_1', [
            'provider_app' => 'app_alipay_return_recovery',
        ]);
        $payload = [
            'app_id' => 'app_alipay_return_recovery',
            'seller_id' => 'seller_alipay_return_recovery',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_1',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_alipay_return_recovery_1',
            'X-Locale' => 'en',
        ])->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('order_no', $orderNo);
        $response->assertJsonPath('payment_recovery_token', fn ($value) => is_string($value) && $value !== '');
        $this->assertStringContainsString('/en/pay/wait?order_no='.$orderNo, (string) $response->json('wait_url'));
        $this->assertStringNotContainsString('payment_recovery_token=', (string) $response->json('wait_url'));
    }

    public function test_recover_alipay_return_immediately_queries_provider_and_repairs_paid_order(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'app.frontend_url' => 'https://web.example.test',
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);
        (new Pr19CommerceSeeder)->run();

        $attemptId = $this->insertAttempt('anon_alipay_return_recovery_compensate');
        $orderNo = $this->insertOrder('anon_alipay_return_recovery_compensate', [
            'sku' => 'MBTI_REPORT_FULL',
            'item_sku' => 'MBTI_REPORT_FULL',
            'target_attempt_id' => $attemptId,
            'provider_app' => 'app_alipay_return_recovery',
            'status' => Order::STATUS_PENDING,
            'payment_state' => Order::PAYMENT_STATE_PENDING,
            'grant_state' => Order::GRANT_STATE_NOT_STARTED,
        ]);
        $orderId = (string) DB::table('orders')->where('order_no', $orderNo)->value('id');
        $this->insertPaymentAttempt($orderId, $orderNo);

        $this->app->instance(AlipayLifecycleGateway::class, new class extends AlipayLifecycleGateway
        {
            protected function dispatchQuery(array $order): array
            {
                return [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => 'ali_trade_return_recovery_compensate',
                    'gmt_payment' => '2026-04-02 12:00:00',
                ];
            }
        });

        $payload = [
            'app_id' => 'app_alipay_return_recovery',
            'seller_id' => 'seller_alipay_return_recovery',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_compensate',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_alipay_return_recovery_compensate',
            'X-Locale' => 'zh',
        ])->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $freshOrder = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertSame(Order::PAYMENT_STATE_PAID, (string) ($freshOrder->payment_state ?? ''));
        $this->assertSame(Order::GRANT_STATE_GRANTED, (string) ($freshOrder->grant_state ?? ''));
        $this->assertSame(Order::STATUS_FULFILLED, (string) ($freshOrder->status ?? ''));
        $this->assertSame('ali_trade_return_recovery_compensate', (string) ($freshOrder->provider_trade_no ?? ''));
        $this->assertNotNull($freshOrder->last_reconciled_at ?? null);
        $this->assertSame(1, DB::table('benefit_grants')->where('order_no', $orderNo)->where('status', 'active')->count());
    }

    public function test_recover_alipay_return_rejects_invalid_signature(): void
    {
        config([
            'payments.providers.alipay.enabled' => true,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_2');

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_alipay_return_recovery_2',
        ])->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?out_trade_no='.$orderNo.'&trade_no=ali_trade_return_recovery_2&trade_status=TRADE_SUCCESS');

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
    }

    public function test_recover_alipay_return_rejects_order_mismatch(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_3');
        $payload = [
            'out_trade_no' => 'ord_other_return_recovery',
            'trade_no' => 'ali_trade_return_recovery_3',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'ORDER_MISMATCH');
    }

    public function test_recover_alipay_return_rejects_missing_app_binding(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_4', [
            'provider_app' => 'app_alipay_return_recovery',
        ]);
        $payload = [
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_4',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'PAYMENT_RETURN_BINDING_MISMATCH');
        $response->assertJsonMissingPath('payment_recovery_token');
    }

    public function test_recover_alipay_return_rejects_app_binding_mismatch(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_5', [
            'provider_app' => 'app_alipay_return_recovery',
        ]);
        $payload = [
            'app_id' => 'app_alipay_other',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_5',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'PAYMENT_RETURN_BINDING_MISMATCH');
        $response->assertJsonMissingPath('payment_recovery_token');
    }

    public function test_recover_alipay_return_rejects_order_provider_mismatch(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_6', [
            'provider' => 'wechatpay',
            'provider_app' => 'app_alipay_return_recovery',
        ]);
        $payload = [
            'app_id' => 'app_alipay_return_recovery',
            'seller_id' => 'seller_alipay_return_recovery',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_6',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'PAYMENT_RETURN_BINDING_MISMATCH');
        $response->assertJsonMissingPath('payment_recovery_token');
    }

    public function test_recover_alipay_return_rejects_missing_configured_seller_binding(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => '',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_7', [
            'provider_app' => 'app_alipay_return_recovery',
        ]);
        $payload = [
            'app_id' => 'app_alipay_return_recovery',
            'seller_id' => 'seller_alipay_return_recovery',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_7',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(503);
        $response->assertJsonPath('error_code', 'PAYMENT_RETURN_BINDING_UNAVAILABLE');
        $response->assertJsonMissingPath('payment_recovery_token');
    }

    public function test_recover_alipay_return_rejects_missing_seller_binding(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_8', [
            'provider_app' => 'app_alipay_return_recovery',
        ]);
        $payload = [
            'app_id' => 'app_alipay_return_recovery',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_8',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'PAYMENT_RETURN_BINDING_MISMATCH');
        $response->assertJsonMissingPath('payment_recovery_token');
    }

    public function test_recover_alipay_return_rejects_seller_binding_mismatch(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_9', [
            'provider_app' => 'app_alipay_return_recovery',
        ]);
        $payload = [
            'app_id' => 'app_alipay_return_recovery',
            'seller_id' => 'seller_alipay_other',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_9',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'PAYMENT_RETURN_BINDING_MISMATCH');
        $response->assertJsonMissingPath('payment_recovery_token');
    }

    public function test_recover_alipay_return_rejects_order_provider_app_mismatch(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_10', [
            'provider_app' => 'app_alipay_other',
        ]);
        $payload = [
            'app_id' => 'app_alipay_return_recovery',
            'seller_id' => 'seller_alipay_return_recovery',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_10',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'PAYMENT_RETURN_BINDING_MISMATCH');
        $response->assertJsonMissingPath('payment_recovery_token');
    }

    public function test_recover_alipay_return_rejects_amount_mismatch(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_11', [
            'provider_app' => 'app_alipay_return_recovery',
        ]);
        $payload = [
            'app_id' => 'app_alipay_return_recovery',
            'seller_id' => 'seller_alipay_return_recovery',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_11',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '20.00',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'PAYMENT_RETURN_BINDING_MISMATCH');
        $response->assertJsonMissingPath('payment_recovery_token');
    }

    public function test_recover_alipay_return_rejects_closed_order_state(): void
    {
        ['private' => $privateKey, 'public' => $publicKey] = $this->generateRsaKeyPair();
        config([
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'app_alipay_return_recovery',
            'pay.alipay.default.seller_id' => 'seller_alipay_return_recovery',
            'pay.alipay.default.alipay_public_cert_path' => $publicKey,
        ]);

        $orderNo = $this->insertOrder('anon_alipay_return_recovery_12', [
            'provider_app' => 'app_alipay_return_recovery',
            'status' => 'canceled',
            'payment_state' => 'canceled',
        ]);
        $payload = [
            'app_id' => 'app_alipay_return_recovery',
            'seller_id' => 'seller_alipay_return_recovery',
            'out_trade_no' => $orderNo,
            'trade_no' => 'ali_trade_return_recovery_12',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '19.90',
            'sign_type' => 'RSA2',
            'notify_time' => '2026-04-02 12:00:00',
        ];
        $payload['sign'] = $this->buildAlipaySignature($payload, $privateKey);

        $response = $this->get('/api/v0.3/orders/'.$orderNo.'/recover/alipay-return?'.http_build_query($payload));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'PAYMENT_RETURN_BINDING_MISMATCH');
        $response->assertJsonMissingPath('payment_recovery_token');
    }

    private function insertOrder(string $anonId, array $overrides = []): string
    {
        $orderNo = 'ord_alipay_recover_'.Str::random(8);

        DB::table('orders')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 1990,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => 'alipay',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 1990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ], $overrides));

        return $orderNo;
    }

    private function insertAttempt(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $timestamp = now()->subHours(2);

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => json_encode(['stage' => 'alipay-return-compensation'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'started_at' => $timestamp,
            'submitted_at' => $timestamp,
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $attemptId;
    }

    private function insertPaymentAttempt(string $orderId, string $orderNo): void
    {
        $timestamp = now()->subHours(2);

        DB::table('payment_attempts')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'attempt_no' => 1,
            'provider' => 'alipay',
            'channel' => 'web',
            'provider_app' => 'app_alipay_return_recovery',
            'pay_scene' => 'web',
            'state' => PaymentAttempt::STATE_CLIENT_PRESENTED,
            'external_trade_no' => null,
            'provider_trade_no' => null,
            'provider_session_ref' => null,
            'amount_expected' => 1990,
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
    }

    /**
     * @return array{private: string, public: string}
     */
    private function generateRsaKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            self::fail('failed to generate rsa keypair.');
        }

        $privateKey = '';
        $exported = openssl_pkey_export($resource, $privateKey);
        if ($exported !== true || trim($privateKey) === '') {
            self::fail('failed to export private key.');
        }

        $details = openssl_pkey_get_details($resource);
        if (! is_array($details) || trim((string) ($details['key'] ?? '')) === '') {
            self::fail('failed to get public key.');
        }

        return [
            'private' => $privateKey,
            'public' => (string) $details['key'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function buildAlipaySignature(array $payload, string $privateKey): string
    {
        $content = filter_params(
            $payload,
            static fn ($key, $value): bool => (string) $value !== '' && $key !== 'sign' && $key !== 'sign_type'
        )->sortKeys()->toString();

        $signature = '';
        $ok = openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if ($ok !== true) {
            self::fail('failed to sign alipay payload.');
        }

        return base64_encode($signature);
    }
}
