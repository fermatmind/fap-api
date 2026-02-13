<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Http\Middleware\LimitWebhookPayloadSize;
use App\Services\Commerce\PaymentWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class WebhookPayloadSizeLimitTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    public function test_v03_payment_webhook_route_contains_payload_limit_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('api.v0_3.webhooks.payment');

        $this->assertNotNull($route);
        $this->assertContains(LimitWebhookPayloadSize::class, $route->gatherMiddleware());
    }

    public function test_oversized_payload_returns_413_and_is_blocked_before_business_processing(): void
    {
        config(['payments.webhook_max_payload_bytes' => 1024]);

        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('process')->never();
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $rawBody = json_encode([
            'id' => 'evt_payload_limit_mw_413',
            'order_no' => 'ord_payload_limit_mw_413',
            'blob' => str_repeat('x', 2048),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($rawBody);
        self::assertGreaterThan(1024, strlen($rawBody));
        self::assertLessThan(262144, strlen($rawBody));

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(413)->assertJson([
            'ok' => false,
            'error_code' => 'PAYLOAD_TOO_LARGE',
        ]);

        $this->assertSame(0, DB::table('payment_events')->count());
    }
}
