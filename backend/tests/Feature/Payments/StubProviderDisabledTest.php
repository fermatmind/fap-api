<?php

namespace Tests\Feature\Payments;

use Tests\TestCase;

class StubProviderDisabledTest extends TestCase
{
    public function test_stub_routes_return_404_when_allow_stub_disabled(): void
    {
        config(['payments.allow_stub' => false]);

        $this->postJson('/api/v0.3/orders/stub', [
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
        ])->assertStatus(404);

        $this->postJson('/api/v0.3/webhooks/payment/stub', [])->assertStatus(404);
    }

    public function test_stub_webhook_route_is_exposed_when_allow_stub_enabled_in_testing(): void
    {
        $this->assertTrue(app()->environment(['testing', 'local']));
        config(['payments.allow_stub' => true]);

        $route = app('router')->getRoutes()->getByName('v0.3.webhooks.payment');
        $this->assertNotNull($route);
        $this->assertStringContainsString('stub', (string) ($route->wheres['provider'] ?? ''));

        $response = $this->postJson('/api/v0.3/webhooks/payment/stub', []);
        $this->assertNotSame(404, $response->getStatusCode());
    }
}
