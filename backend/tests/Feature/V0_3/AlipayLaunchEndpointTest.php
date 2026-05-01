<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Commerce\Checkout\AlipayCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AlipayLaunchEndpointTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_launch_endpoint_returns_404_when_order_not_owned(): void
    {
        config(['payments.providers.alipay.enabled' => true]);

        $orderNo = $this->insertOrder('anon_owner_1');

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_other_1',
        ])->get('/api/v0.3/orders/'.$orderNo.'/pay/alipay?scene=desktop');

        $response->assertStatus(404);
    }

    public function test_launch_endpoint_returns_html_response_when_owned(): void
    {
        config(['payments.providers.alipay.enabled' => true]);

        $orderNo = $this->insertOrder('anon_owner_2');

        $service = Mockery::mock(AlipayCheckoutService::class);
        $service->shouldReceive('launch')
            ->once()
            ->with(Mockery::type('array'), 'desktop')
            ->andReturn(new Response('<html>pay</html>', 200));
        $this->app->instance(AlipayCheckoutService::class, $service);

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_owner_2',
        ])->get('/api/v0.3/orders/'.$orderNo.'/pay/alipay?scene=desktop');

        $response->assertStatus(200);
        $response->assertSee('pay');
    }

    public function test_launch_endpoint_enriches_return_url_with_wait_recovery_context(): void
    {
        config([
            'app.frontend_url' => 'https://web.example.test',
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.return_url' => 'https://web.example.test/en/pay/return/alipay',
        ]);

        $orderNo = $this->insertOrder('anon_owner_3');

        $service = Mockery::mock(AlipayCheckoutService::class);
        $service->shouldReceive('launch')
            ->once()
            ->withArgs(function (array $order, string $scene) use ($orderNo): bool {
                $this->assertSame('desktop', $scene);
                $this->assertArrayHasKey('return_url', $order);

                $returnUrl = (string) ($order['return_url'] ?? '');
                $this->assertStringStartsWith('https://web.example.test/en/pay/return/alipay?', $returnUrl);

                $parsedQuery = [];
                parse_str((string) parse_url($returnUrl, PHP_URL_QUERY), $parsedQuery);

                $this->assertSame($orderNo, (string) ($parsedQuery['order_no'] ?? ''));
                $this->assertArrayNotHasKey('payment_recovery_token', $parsedQuery);
                $this->assertStringContainsString(
                    '/en/pay/wait?order_no='.$orderNo,
                    (string) ($parsedQuery['wait_url'] ?? '')
                );
                $this->assertStringNotContainsString(
                    'payment_recovery_token=',
                    (string) ($parsedQuery['wait_url'] ?? '')
                );

                return true;
            })
            ->andReturn(new Response('<html>pay</html>', 200));
        $this->app->instance(AlipayCheckoutService::class, $service);

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_owner_3',
            'X-Locale' => 'en',
        ])->get('/api/v0.3/orders/'.$orderNo.'/pay/alipay?scene=desktop');

        $response->assertStatus(200);
        $response->assertSee('pay');
    }

    public function test_launch_endpoint_derives_return_url_from_recovery_urls_when_static_config_is_missing(): void
    {
        config([
            'app.frontend_url' => 'https://web.example.test',
            'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.return_url' => null,
        ]);

        $orderNo = $this->insertOrder('anon_owner_4');

        $service = Mockery::mock(AlipayCheckoutService::class);
        $service->shouldReceive('launch')
            ->once()
            ->withArgs(function (array $order, string $scene) use ($orderNo): bool {
                $this->assertSame('desktop', $scene);
                $this->assertArrayHasKey('return_url', $order);

                $returnUrl = (string) ($order['return_url'] ?? '');
                $this->assertStringStartsWith('https://web.example.test/en/pay/return/alipay?', $returnUrl);

                $parsedQuery = [];
                parse_str((string) parse_url($returnUrl, PHP_URL_QUERY), $parsedQuery);

                $this->assertSame($orderNo, (string) ($parsedQuery['order_no'] ?? ''));
                $this->assertArrayNotHasKey('payment_recovery_token', $parsedQuery);
                $this->assertStringContainsString(
                    '/en/pay/wait?order_no='.$orderNo,
                    (string) ($parsedQuery['wait_url'] ?? '')
                );
                $this->assertStringNotContainsString(
                    'payment_recovery_token=',
                    (string) ($parsedQuery['wait_url'] ?? '')
                );

                return true;
            })
            ->andReturn(new Response('<html>pay</html>', 200));
        $this->app->instance(AlipayCheckoutService::class, $service);

        $response = $this->withHeaders([
            'X-Anon-Id' => 'anon_owner_4',
            'X-Locale' => 'en',
        ])->get('/api/v0.3/orders/'.$orderNo.'/pay/alipay?scene=desktop');

        $response->assertStatus(200);
        $response->assertSee('pay');
    }

    private function insertOrder(string $anonId): string
    {
        $orderNo = 'ord_alipay_'.Str::random(8);

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'CNY',
            'status' => 'created',
            'provider' => 'alipay',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 4990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        return $orderNo;
    }
}
