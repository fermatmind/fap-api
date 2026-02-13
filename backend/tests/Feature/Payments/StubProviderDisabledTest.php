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

    public function test_stub_webhook_route_exposure_depends_on_environment_even_when_enabled(): void
    {
        config(['payments.allow_stub' => true]);

        $route = app('router')->getRoutes()->getByName('api.v0_3.webhooks.payment');
        $this->assertNotNull($route);

        $response = $this->postJson('/api/v0.3/webhooks/payment/stub', []);
        if (app()->environment(['local', 'testing'])) {
            $this->assertStringContainsString('stub', (string) ($route->wheres['provider'] ?? ''));
            $response->assertStatus(404);
        } else {
            $this->assertStringNotContainsString('stub', (string) ($route->wheres['provider'] ?? ''));
            $response->assertStatus(404);
        }
    }
}
