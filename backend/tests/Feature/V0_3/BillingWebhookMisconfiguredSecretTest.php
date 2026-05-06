<?php

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingWebhookMisconfiguredSecretTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('nonOptionalEnvironmentProvider')]
    public function test_missing_billing_secret_in_non_optional_env_returns_400_invalid_signature(string $environment): void
    {
        /** @var Application $app */
        $app = $this->app;
        $app->detectEnvironment(static fn (): string => $environment);
        $app->instance('env', $environment);

        config([
            'app.env' => $environment,
            'services.billing.webhook_secret' => '',
            'services.billing.webhook_secret_optional_envs' => ['local', 'testing'],
            'services.billing.allow_legacy_signature' => false,
            'services.billing.webhook_tolerance_seconds' => 300,
        ]);

        $raw = json_encode([
            'provider_event_id' => 'evt_pr57_missing_secret',
            'order_no' => 'ord_pr57_missing_secret',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($raw)) {
            self::fail('json_encode payload failed.');
        }

        $response = $this->call('POST', '/api/v0.3/webhooks/payment/billing', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) time(),
            'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid',
            'HTTP_X_REQUEST_ID' => 'req-pr57-misconfigured',
        ], $raw);

        $response->assertStatus(400);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('error_code', 'INVALID_SIGNATURE');
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function nonOptionalEnvironmentProvider(): array
    {
        return [
            'ci' => ['ci'],
            'staging' => ['staging'],
            'production' => ['production'],
        ];
    }
}
