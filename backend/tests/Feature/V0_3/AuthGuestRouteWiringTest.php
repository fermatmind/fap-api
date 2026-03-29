<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Http\Controllers\API\V0_3\AuthGuestController;
use App\Http\Middleware\ResolveAnonId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class AuthGuestRouteWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_guest_route_is_public_and_has_expected_middlewares(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/v0.3/auth/guest', 'POST'));
        $this->assertNotNull($route);
        $this->assertStringContainsString(AuthGuestController::class, $route->getActionName());

        $middlewares = $route->gatherMiddleware();
        $this->assertContains('throttle:api_auth', $middlewares);
        $this->assertContains(ResolveAnonId::class, $middlewares);

        $joined = implode(',', $middlewares);
        $this->assertStringNotContainsString('FmTokenAuth', $joined);

        $response = $this->postJson('/api/v0.3/auth/guest', [
            'anon_id' => 'anon_guest_route_wiring_001',
        ]);

        $response->assertStatus(200)->assertJson([
            'ok' => true,
            'anon_id' => 'anon_guest_route_wiring_001',
        ]);
    }
}
