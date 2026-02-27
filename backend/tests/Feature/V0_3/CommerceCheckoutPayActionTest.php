<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Commerce\Checkout\WechatPayCheckoutService;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class CommerceCheckoutPayActionTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

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
}
