<?php

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BillingWebhookMisconfiguredSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_billing_secret_returns_500_and_logs_anchor_without_sensitive_context(): void
    {
        /** @var Application $app */
        $app = $this->app;
        $app->detectEnvironment(static fn (): string => 'production');
        $app->instance('env', 'production');

        config([
            'app.env' => 'production',
            'services.billing.webhook_secret' => '',
            'services.billing.webhook_secret_optional_envs' => ['local', 'testing', 'ci'],
            'services.billing.allow_legacy_signature' => false,
            'services.billing.webhook_tolerance_seconds' => 300,
        ]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context): bool {
                $this->assertSame('CRITICAL: BILLING_WEBHOOK_SECRET_MISSING', $message);
                $this->assertIsArray($context);
                $this->assertSame('billing', $context['provider'] ?? null);
                $this->assertSame('production', $context['environment'] ?? null);
                $this->assertSame('req-pr57-misconfigured', $context['request_id'] ?? null);
                $this->assertArrayNotHasKey('body', $context);
                $this->assertArrayNotHasKey('signature', $context);
                $this->assertArrayNotHasKey('secret', $context);

                return true;
            });

        $raw = json_encode([
            'provider_event_id' => 'evt_pr57_missing_secret',
            'order_no' => 'ord_pr57_missing_secret',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($raw)) {
            self::fail('json_encode payload failed.');
        }

        $response = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BILLING_TIMESTAMP' => (string) time(),
            'HTTP_X_BILLING_SIGNATURE' => 'invalid',
            'HTTP_X_REQUEST_ID' => 'req-pr57-misconfigured',
        ], $raw);

        $response->assertStatus(500);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error', 'INTERNAL_SERVER_ERROR');
        $response->assertJsonPath('message', 'internal server error.');
        $response->assertJsonPath('request_id', 'req-pr57-misconfigured');
    }
}
