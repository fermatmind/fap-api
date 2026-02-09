<?php

namespace Tests\Feature\V0_3;

use App\Http\Controllers\API\V0_3\Webhooks\PaymentWebhookController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookRouteWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_webhook_route_points_to_controller_and_unsupported_provider_returns_404(): void
    {
        $this->assertTrue(class_exists(PaymentWebhookController::class));

        $route = app('router')->getRoutes()->getByName('v0.3.webhooks.payment');
        $this->assertNotNull($route);
        $this->assertSame(PaymentWebhookController::class . '@handle', $route->getActionName());

        $response = $this->postJson('/api/v0.3/webhooks/payment/stub', []);
        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertStatus(404);
    }
}
