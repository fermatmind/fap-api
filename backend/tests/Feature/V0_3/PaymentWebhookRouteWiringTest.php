<?php

namespace Tests\Feature\V0_3;

use App\Http\Controllers\API\V0_3\Webhooks\PaymentWebhookController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookRouteWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_webhook_route_points_to_controller_and_blocks_stub_at_route_layer(): void
    {
        $this->assertTrue(class_exists(PaymentWebhookController::class));

        $route = app('router')->getRoutes()->getByName('v0.3.webhooks.payment');
        $this->assertNotNull($route);
        $this->assertSame(PaymentWebhookController::class . '@handle', $route->getActionName());

        $providerWhere = (string) ($route->wheres['provider'] ?? '');
        $this->assertNotSame('', $providerWhere);
        $this->assertStringContainsString('stripe', $providerWhere);
        $this->assertStringContainsString('billing', $providerWhere);
        $stubEnabled = app()->environment(['local', 'testing']) && config('payments.allow_stub') === true;
        if ($stubEnabled) {
            $this->assertStringContainsString('stub', $providerWhere);
        } else {
            $this->assertStringNotContainsString('stub', $providerWhere);
        }

        $response = $this->postJson('/api/v0.3/webhooks/payment/stub', []);
        $this->assertNotSame(500, $response->getStatusCode());
        if ($stubEnabled) {
            $this->assertNotSame(404, $response->getStatusCode());
        } else {
            $response->assertStatus(404);
        }
    }
}
