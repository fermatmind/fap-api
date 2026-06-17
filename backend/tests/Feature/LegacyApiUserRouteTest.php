<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Tests\TestCase;

final class LegacyApiUserRouteTest extends TestCase
{
    public function test_legacy_api_user_route_does_not_depend_on_sanctum_guard(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/user', 'GET'));
        $this->assertNotNull($route);

        $middlewares = implode(',', $route->gatherMiddleware());
        $this->assertStringNotContainsString('auth:sanctum', $middlewares);

        $response = $this->withHeader('X-Request-Id', 'legacy-user-route-test')
            ->getJson('/api/user');

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer realm="Fermat API", error="invalid_token"')
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED')
            ->assertJsonPath('message', 'Missing or invalid fm_token. Please login.')
            ->assertJsonPath('request_id', 'legacy-user-route-test')
            ->assertJsonMissingPath('error');

        $decoded = json_decode((string) $response->getContent());
        $this->assertIsObject($decoded);
        $this->assertEquals((object) [], $decoded->details ?? null);
    }
}
