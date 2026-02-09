<?php

namespace Tests\Feature\V0_3;

use App\Services\Commerce\PaymentWebhookProcessor;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class PaymentWebhookFailSafeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_unsupported_provider_returns_404_and_logs_stable_keyword(): void
    {
        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')->never();
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context): bool {
                $this->assertSame('PAYMENT_WEBHOOK_PROVIDER_UNSUPPORTED', $message);
                $this->assertIsArray($context);
                $this->assertSame('unknown', $context['provider'] ?? null);
                $this->assertIsString($context['request_id'] ?? null);
                $this->assertNotSame('', (string) ($context['request_id'] ?? ''));
                $this->assertArrayNotHasKey('payload', $context);

                return true;
            });

        $response = $this->postJson('/api/v0.3/webhooks/payment/unknown', [
            'provider_event_id' => 'evt_unknown_provider',
            'order_no' => 'ord_unknown_provider',
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error' => 'NOT_FOUND',
        ]);
    }

    public function test_invalid_json_returns_404_and_does_not_call_processor(): void
    {
        $processor = Mockery::mock(PaymentWebhookProcessor::class);
        $processor->shouldReceive('handle')->never();
        $this->app->instance(PaymentWebhookProcessor::class, $processor);

        $response = $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/stub',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            '{"provider_event_id":"evt_invalid_json","order_no":"ord_invalid_json"',
        );

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error' => 'NOT_FOUND',
        ]);
    }
}
