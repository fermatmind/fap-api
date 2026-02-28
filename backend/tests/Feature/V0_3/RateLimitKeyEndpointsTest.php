<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Tests\TestCase;

final class RateLimitKeyEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_wiring_contains_expected_throttle_middlewares(): void
    {
        $authGuest = $this->findRoute('POST', 'api/v0.3/auth/guest');
        $this->assertContains('throttle:api_auth', $authGuest->gatherMiddleware());

        $orderLookup = $this->findRoute('POST', 'api/v0.3/orders/lookup');
        $this->assertContains('throttle:api_order_lookup', $orderLookup->gatherMiddleware());

        $shareClick = $this->findRoute('POST', 'api/v0.3/shares/{shareId}/click');
        $this->assertContains('throttle:api_track', $shareClick->gatherMiddleware());

        $paymentWebhook = $this->findRoute('POST', 'api/v0.3/webhooks/payment/{provider}');
        $this->assertContains('throttle:api_webhook', $paymentWebhook->gatherMiddleware());
    }

    public function test_auth_guest_rate_limit_returns_429_error_contract(): void
    {
        $this->configureRateLimitGate([
            'fap.rate_limits.api_auth_per_minute' => 2,
        ]);

        $server = ['REMOTE_ADDR' => '198.51.100.40'];
        $payload = ['anon_id' => 'rate_limit_auth_anon'];

        $this->withServerVariables($server)->postJson('/api/v0.3/auth/guest', $payload)->assertStatus(200);
        $this->withServerVariables($server)->postJson('/api/v0.3/auth/guest', $payload)->assertStatus(200);

        $response = $this->withServerVariables($server)->postJson('/api/v0.3/auth/guest', $payload);
        $this->assertRateLimitContract($response->status(), $response->json(), 'RATE_LIMIT_AUTH');
    }

    public function test_order_lookup_rate_limit_returns_429_error_contract(): void
    {
        $this->configureRateLimitGate([
            'fap.rate_limits.api_order_lookup_per_minute' => 2,
        ]);

        $server = ['REMOTE_ADDR' => '198.51.100.41'];
        $payload = [
            'order_no' => 'ord_rate_limit_lookup_case',
            'email' => 'lookup@example.com',
        ];

        $this->withServerVariables($server)->postJson('/api/v0.3/orders/lookup', $payload)->assertStatus(404);
        $this->withServerVariables($server)->postJson('/api/v0.3/orders/lookup', $payload)->assertStatus(404);

        $response = $this->withServerVariables($server)->postJson('/api/v0.3/orders/lookup', $payload);
        $this->assertRateLimitContract($response->status(), $response->json(), 'RATE_LIMIT_ORDER_LOOKUP');
    }

    public function test_share_click_track_rate_limit_returns_429_error_contract(): void
    {
        $this->configureRateLimitGate([
            'fap.rate_limits.api_track_per_minute' => 2,
        ]);

        $server = ['REMOTE_ADDR' => '198.51.100.42'];

        $first = $this->withServerVariables($server)->postJson('/api/v0.3/shares/abc12345/click', []);
        $second = $this->withServerVariables($server)->postJson('/api/v0.3/shares/abc12345/click', []);

        $this->assertNotSame(429, $first->status());
        $this->assertNotSame(429, $second->status());

        $response = $this->withServerVariables($server)->postJson('/api/v0.3/shares/abc12345/click', []);
        $this->assertRateLimitContract($response->status(), $response->json(), 'RATE_LIMIT_TRACK');
    }

    public function test_payment_webhook_rate_limit_returns_429_error_contract(): void
    {
        $this->configureRateLimitGate([
            'fap.rate_limits.api_webhook_per_minute' => 2,
        ]);
        config([
            'services.stripe.webhook_secret' => 'whsec_rate_limit_contract',
            'services.stripe.webhook_tolerance_seconds' => 300,
        ]);

        $raw = json_encode([
            'id' => 'evt_rate_limit_case',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_rate_limit_case',
                    'metadata' => ['order_no' => 'ord_rate_limit_case'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($raw);

        $server = [
            'REMOTE_ADDR' => '198.51.100.43',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1700000000,v1=invalid',
        ];

        $first = $this->call('POST', '/api/v0.3/webhooks/payment/stripe', [], [], [], $server, $raw);
        $second = $this->call('POST', '/api/v0.3/webhooks/payment/stripe', [], [], [], $server, $raw);

        $this->assertSame(400, $first->status());
        $this->assertSame(400, $second->status());

        $response = $this->call('POST', '/api/v0.3/webhooks/payment/stripe', [], [], [], $server, $raw);
        $this->assertRateLimitContract($response->status(), $response->json(), 'RATE_LIMIT_WEBHOOK');
    }

    private function configureRateLimitGate(array $overrides = []): void
    {
        config(array_merge([
            'fap.rate_limits.bypass_in_test_env' => false,
            'fap.rate_limits.api_auth_per_minute' => 30,
            'fap.rate_limits.api_order_lookup_per_minute' => 20,
            'fap.rate_limits.api_track_per_minute' => 60,
            'fap.rate_limits.api_webhook_per_minute' => 60,
        ], $overrides));
    }

    private function assertRateLimitContract(int $status, mixed $json, string $errorCode): void
    {
        $this->assertSame(429, $status);
        $this->assertIsArray($json);
        $this->assertSame(false, $json['ok'] ?? null);
        $this->assertSame($errorCode, $json['error_code'] ?? null);
        $this->assertIsString($json['message'] ?? null);
        $this->assertNotSame('', trim((string) ($json['message'] ?? '')));
        $this->assertArrayHasKey('details', $json);
        $this->assertNull($json['details']);
        $this->assertIsString($json['request_id'] ?? null);
        $this->assertNotSame('', trim((string) ($json['request_id'] ?? '')));
        $this->assertArrayNotHasKey('error', $json);
    }

    private function findRoute(string $method, string $uri): IlluminateRoute
    {
        $routes = app('router')->getRoutes();

        foreach ($routes as $route) {
            if ($route->uri() !== $uri) {
                continue;
            }

            if (in_array(strtoupper($method), $route->methods(), true)) {
                return $route;
            }
        }

        self::fail("Route not found for [{$method}] {$uri}");
    }
}
