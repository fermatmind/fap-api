<?php

namespace Tests\Feature\V0_3;

use App\Services\Commerce\PaymentWebhookProcessor;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class PaymentWebhookFailSafeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_unknown_provider_route_returns_404_and_does_not_call_processor(): void
    {
        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')->never();
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $response = $this->postJson('/api/v0.3/webhooks/payment/unknown', [
            'provider_event_id' => 'evt_unknown_provider',
            'order_no' => 'ord_unknown_provider',
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }

    public function test_invalid_json_returns_400_and_does_not_call_processor(): void
    {
        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')->never();
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

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
            '{"provider_event_id":"evt_invalid_json","order_no":"ord_invalid_json"',
        );

        $response->assertStatus(400);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'INVALID_JSON',
        ]);
    }
}
