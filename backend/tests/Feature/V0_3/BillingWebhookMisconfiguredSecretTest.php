<?php

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BillingWebhookMisconfiguredSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_billing_secret_logs_critical_anchor_and_returns_404(): void
    {
        /** @var Application $app */
        $app = $this->app;
        $app->detectEnvironment(static fn (): string => 'production');
        $app->instance('env', 'production');

        config([
            'app.env' => 'production',
            'services.billing.webhook_secret' => '',
        ]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context): bool {
                $this->assertSame('CRITICAL: BILLING_WEBHOOK_SECRET_MISSING', $message);
                $this->assertIsArray($context);
                $this->assertSame('billing', $context['provider'] ?? null);
                $this->assertSame('req-pr57-misconfigured', $context['request_id'] ?? null);
                $this->assertArrayNotHasKey('body', $context);
                $this->assertArrayNotHasKey('signature', $context);
                $this->assertArrayNotHasKey('secret', $context);

                return true;
            });

        $response = $this->postJson(
            '/api/v0.3/webhooks/payment/billing',
            [
                'provider_event_id' => 'evt_pr57_missing_secret',
                'order_no' => 'ord_pr57_missing_secret',
            ],
            [
                'X-Billing-Timestamp' => (string) time(),
                'X-Billing-Signature' => 'invalid',
                'X-Request-Id' => 'req-pr57-misconfigured',
            ]
        );

        $response->assertStatus(404);
        $response->assertJsonStructure(['ok', 'error', 'message']);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error', 'NOT_FOUND');
        $response->assertJsonPath('message', 'not found.');
    }
}
