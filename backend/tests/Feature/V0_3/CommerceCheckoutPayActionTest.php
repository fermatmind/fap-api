<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Commerce\Checkout\WechatPayCheckoutService;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class CommerceCheckoutPayActionTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_checkout_options_allows_x_region_header_for_browser_preflight(): void
    {
        $response = app()->handle(Request::create(
            '/api/v0.3/orders/checkout',
            'OPTIONS',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://fermatmind.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-region,x-fap-locale,idempotency-key,x-anon-id',
            ]
        ));

        $this->assertContains($response->getStatusCode(), [200, 204]);

        $allowedHeaders = strtolower((string) $response->headers->get('Access-Control-Allow-Headers', ''));
        $this->assertStringContainsString('x-region', $allowedHeaders);
    }

    public function test_checkout_lemonsqueezy_returns_pay_redirect_and_checkout_url(): void
    {
        $this->seedCommerce();

        config([
            'payments.providers.lemonsqueezy.enabled' => true,
            'services.lemonsqueezy.api_key' => 'ls_test_key',
            'services.lemonsqueezy.store_id' => '123',
            'services.lemonsqueezy.variant_id' => '456',
            'services.lemonsqueezy.api_base' => 'https://api.lemonsqueezy.test/v1',
        ]);

        Http::fake([
            'https://api.lemonsqueezy.test/v1/checkouts' => Http::response([
                'data' => [
                    'attributes' => [
                        'url' => 'https://checkout.lemonsqueezy.test/session_123',
                    ],
                ],
            ], 201),
        ]);

        $response = $this->checkout([
            'sku' => 'MBTI_CREDIT',
            'provider' => 'lemonsqueezy',
            'email' => 'checkout@example.com',
            'attempt_id' => 'attempt_lemonsqueezy_001',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('provider', 'lemonsqueezy');
        $response->assertJsonPath('pay.type', 'redirect');
        $response->assertJsonPath('pay.value', 'https://checkout.lemonsqueezy.test/session_123');
        $response->assertJsonPath('checkout_url', 'https://checkout.lemonsqueezy.test/session_123');

        Http::assertSent(function ($request): bool {
            $custom = data_get($request->data(), 'data.attributes.checkout_data.custom', []);

            return $request->url() === 'https://api.lemonsqueezy.test/v1/checkouts'
                && data_get($custom, 'order_no') !== null
                && data_get($custom, 'attempt_id') === 'attempt_lemonsqueezy_001';
        });
    }

    public function test_checkout_wechatpay_desktop_returns_qr_action(): void
    {
        $this->seedCommerce();

        config([
            'payments.providers.wechatpay.enabled' => true,
        ]);

        $mock = Mockery::mock(WechatPayCheckoutService::class);
        $mock->shouldReceive('createCheckoutAction')
            ->once()
            ->andReturn([
                'ok' => true,
                'type' => 'qr',
                'value' => 'weixin://wxpay/bizpayurl?pr=test_qr',
            ]);
        $this->app->instance(WechatPayCheckoutService::class, $mock);

        $response = $this->checkout([
            'sku' => 'MBTI_CREDIT',
            'provider' => 'wechatpay',
            'email' => 'wechat@example.com',
        ], [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('provider', 'wechatpay');
        $response->assertJsonPath('pay.type', 'qr');
        $response->assertJsonPath('pay.value', 'weixin://wxpay/bizpayurl?pr=test_qr');
        $response->assertJsonPath('checkout_url', null);
    }

    public function test_checkout_alipay_desktop_returns_html_action_with_launch_url(): void
    {
        $this->seedCommerce();

        config([
            'payments.providers.alipay.enabled' => true,
            'app.url' => 'http://localhost:8000',
        ]);

        $response = $this->checkout([
            'sku' => 'MBTI_CREDIT',
            'provider' => 'alipay',
            'email' => 'alipay@example.com',
        ], [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('provider', 'alipay');
        $response->assertJsonPath('pay.type', 'html');
        $response->assertJsonPath('checkout_url', null);
        $this->assertStringContainsString('/api/v0.3/orders/', (string) $response->json('pay.value'));
        $this->assertStringContainsString('/pay/alipay?scene=desktop', (string) $response->json('pay.value'));
    }

    public function test_checkout_alipay_mobile_returns_redirect_and_checkout_url(): void
    {
        $this->seedCommerce();

        config([
            'payments.providers.alipay.enabled' => true,
            'app.url' => 'http://localhost:8000',
        ]);

        $response = $this->checkout([
            'sku' => 'MBTI_CREDIT',
            'provider' => 'alipay',
            'email' => 'alipay-mobile@example.com',
        ], [
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_4 like Mac OS X)',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('provider', 'alipay');
        $response->assertJsonPath('pay.type', 'redirect');
        $response->assertJsonPath('checkout_url', $response->json('pay.value'));
        $this->assertStringContainsString('/pay/alipay?scene=mobile', (string) $response->json('pay.value'));
    }

    public function test_checkout_legacy_billing_remains_compatible_without_pay_action(): void
    {
        $this->seedCommerce();

        config([
            'payments.providers.billing.enabled' => true,
        ]);

        $response = $this->checkout([
            'sku' => 'MBTI_CREDIT',
            'provider' => 'billing',
            'email' => 'billing@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('provider', 'billing');
        $response->assertJsonPath('pay', null);
        $response->assertJsonPath('checkout_url', null);
        $this->assertNotSame('', (string) $response->json('order_no'));
    }

    public function test_checkout_reuses_pending_order_and_returns_pay_action_for_existing_pending_order(): void
    {
        $this->seedCommerce();

        config([
            'payments.providers.wechatpay.enabled' => true,
        ]);

        $orderNo = 'ord_existing_pending_checkout_1';
        $anonId = 'anon_existing_pending_checkout_1';
        $this->insertPendingOrder($orderNo, 'wechatpay', $anonId);

        $mock = Mockery::mock(WechatPayCheckoutService::class);
        $mock->shouldReceive('createCheckoutAction')
            ->once()
            ->andReturn([
                'ok' => true,
                'type' => 'qr',
                'value' => 'weixin://wxpay/bizpayurl?pr=existing_pending_checkout',
            ]);
        $this->app->instance(WechatPayCheckoutService::class, $mock);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ])->postJson('/api/v0.3/orders/checkout', [
            'order_no' => $orderNo,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('order_no', $orderNo);
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('provider', 'wechatpay');
        $response->assertJsonPath('pay.type', 'qr');
        $response->assertJsonPath('pay.value', 'weixin://wxpay/bizpayurl?pr=existing_pending_checkout');
        $response->assertJsonPath('checkout_url', null);
    }

    public function test_get_order_can_include_payment_action_for_pending_orders(): void
    {
        $this->seedCommerce();

        config([
            'payments.providers.wechatpay.enabled' => true,
        ]);

        $orderNo = 'ord_include_payment_action_1';
        $anonId = 'anon_include_payment_action_1';
        $this->insertPendingOrder($orderNo, 'wechatpay', $anonId);

        $mock = Mockery::mock(WechatPayCheckoutService::class);
        $mock->shouldReceive('createCheckoutAction')
            ->once()
            ->andReturn([
                'ok' => true,
                'type' => 'qr',
                'value' => 'weixin://wxpay/bizpayurl?pr=include_payment_action',
            ]);
        $this->app->instance(WechatPayCheckoutService::class, $mock);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ])->getJson('/api/v0.3/orders/'.$orderNo.'?include_payment_action=1');

        $response->assertStatus(200);
        $response->assertJsonPath('order_no', $orderNo);
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('provider', 'wechatpay');
        $response->assertJsonPath('pay.type', 'qr');
        $response->assertJsonPath('pay.value', 'weixin://wxpay/bizpayurl?pr=include_payment_action');
        $response->assertJsonPath('checkout_url', null);
    }

    public function test_lookup_can_include_payment_action_for_pending_orders_after_email_match(): void
    {
        $this->seedCommerce();

        config([
            'payments.providers.wechatpay.enabled' => true,
        ]);

        $orderNo = 'ord_lookup_include_payment_action_1';
        $anonId = 'anon_lookup_include_payment_action_1';
        $this->insertPendingOrder($orderNo, 'wechatpay', $anonId, 'buyer@example.com');

        $mock = Mockery::mock(WechatPayCheckoutService::class);
        $mock->shouldReceive('createCheckoutAction')
            ->once()
            ->andReturn([
                'ok' => true,
                'type' => 'qr',
                'value' => 'weixin://wxpay/bizpayurl?pr=lookup_include_payment_action',
            ]);
        $this->app->instance(WechatPayCheckoutService::class, $mock);

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'buyer@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('order_no', $orderNo);
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('provider', 'wechatpay');
        $response->assertJsonPath('pay.type', 'qr');
        $response->assertJsonPath('pay.value', 'weixin://wxpay/bizpayurl?pr=lookup_include_payment_action');
        $response->assertJsonPath('checkout_url', null);
    }

    private function seedCommerce(): void
    {
        (new Pr19CommerceSeeder)->run();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,string>  $headers
     */
    private function checkout(array $payload, array $headers = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson('/api/v0.3/orders/checkout', $payload);
    }

    private function insertPendingOrder(string $orderNo, string $provider, string $anonId, ?string $email = null): void
    {
        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => 'MBTI_CREDIT',
            'item_sku' => 'MBTI_CREDIT',
            'requested_sku' => 'MBTI_CREDIT',
            'effective_sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'amount_total' => 199,
            'amount_cents' => 199,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => $provider,
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => 'req_'.$orderNo,
            'created_ip' => null,
            'target_attempt_id' => 'attempt_'.$orderNo,
            'scale_code_v2' => null,
            'scale_uid' => null,
            'external_trade_no' => null,
            'contact_email_hash' => $email ? hash('sha256', mb_strtolower(trim($email), 'UTF-8')) : null,
            'metadata' => null,
            'meta_json' => null,
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'refund_amount_cents' => null,
            'refund_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ([
            'requested_sku',
            'effective_sku',
            'scale_code_v2',
            'scale_uid',
            'contact_email_hash',
            'metadata',
            'meta_json',
            'refund_amount_cents',
            'refund_reason',
        ] as $column) {
            if (! Schema::hasColumn('orders', $column)) {
                unset($row[$column]);
            }
        }

        DB::table('orders')->insert($row);
    }
}
