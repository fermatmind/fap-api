<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Http\Middleware\OpsAccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class OpsAccessControlMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_routes_respect_host_and_ip_blocks(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('ops.access_control.allowed_host', 'ops.example.test');
        config()->set('ops.access_control.ip_allowlist', ['1.1.1.1']);

        Redis::shouldReceive('sismember')
            ->never();

        $request = Request::create('/ops/login', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'blocked.example.test',
        ]);
        $route = new Route(['GET'], '/ops/login', static fn (): Response => response('ok'));
        $route->name('filament.ops.auth.login');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(OpsAccessControl::class)->handle(
            $request,
            static fn (): Response => response('allowed'),
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_login_page_get_is_not_blocked_by_blacklist(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        Redis::shouldReceive('sismember')
            ->never();

        $request = Request::create('/ops/login', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'ops.example.test',
        ]);
        $route = new Route(['GET'], '/ops/login', static fn (): Response => response('ok'));
        $route->name('filament.ops.auth.login');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(OpsAccessControl::class)->handle(
            $request,
            static fn (): Response => response('allowed'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('allowed', $response->getContent());
    }

    public function test_login_post_rate_limit_still_applies_for_auth_route(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('ops.access_control.rate_limit.login', 1);

        Redis::shouldReceive('sismember')
            ->once()
            ->with('ops:ip:blacklist', '8.8.8.8')
            ->andReturn(false);
        Redis::shouldReceive('incr')
            ->with('ops:login:route:filament.ops.auth.login')
            ->never();
        Redis::shouldReceive('incr')
            ->once()
            ->with('ops:login:ip:8.8.8.8')
            ->andReturn(2);
        Redis::shouldReceive('incr')
            ->once()
            ->with('ops:login:user:anonymous:8.8.8.8')
            ->andReturn(1);
        Redis::shouldReceive('expire')
            ->once()
            ->with('ops:login:user:anonymous:8.8.8.8', 300)
            ->andReturnTrue();
        Redis::shouldReceive('sadd')
            ->once()
            ->with('ops:ip:blacklist', '8.8.8.8')
            ->andReturn(1);

        $request = Request::create('/ops/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'ops.example.test',
        ]);
        $route = new Route(['POST'], '/ops/login', static fn (): Response => response('ok'));
        $route->name('filament.ops.auth.login');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(OpsAccessControl::class)->handle(
            $request,
            static fn (): Response => response('allowed'),
        );

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('RATE_LIMITED', (string) $response->getContent());
    }

    public function test_login_rate_limit_does_not_use_shared_route_key_to_block_unrelated_admins(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('ops.access_control.rate_limit.login', 1);
        config()->set('ops.access_control.risk.enabled', false);

        Redis::shouldReceive('incr')
            ->with('ops:login:route:filament.ops.auth.login')
            ->never();

        Redis::shouldReceive('sismember')
            ->once()
            ->with('ops:ip:blacklist', '8.8.8.8')
            ->andReturn(false);
        Redis::shouldReceive('incr')
            ->once()
            ->with('ops:login:ip:8.8.8.8')
            ->andReturn(2);
        Redis::shouldReceive('incr')
            ->once()
            ->with('ops:login:user:target@example.test')
            ->andReturn(1);
        Redis::shouldReceive('expire')
            ->once()
            ->with('ops:login:user:target@example.test', 300)
            ->andReturnTrue();
        Redis::shouldReceive('sadd')
            ->once()
            ->with('ops:ip:blacklist', '8.8.8.8')
            ->andReturn(1);

        Redis::shouldReceive('sismember')
            ->once()
            ->with('ops:ip:blacklist', '9.9.9.9')
            ->andReturn(false);
        Redis::shouldReceive('incr')
            ->once()
            ->with('ops:login:ip:9.9.9.9')
            ->andReturn(1);
        Redis::shouldReceive('incr')
            ->once()
            ->with('ops:login:user:owner@example.test')
            ->andReturn(1);
        Redis::shouldReceive('expire')
            ->once()
            ->with('ops:login:ip:9.9.9.9', 300)
            ->andReturnTrue();
        Redis::shouldReceive('expire')
            ->once()
            ->with('ops:login:user:owner@example.test', 300)
            ->andReturnTrue();
        Redis::shouldReceive('get')
            ->once()
            ->with('ops:login:ip:9.9.9.9')
            ->andReturn(1);
        Redis::shouldReceive('get')
            ->once()
            ->with('ops:login:user:owner@example.test')
            ->andReturn(1);

        $blockedRequest = Request::create('/ops/login', 'POST', [
            'email' => 'target@example.test',
        ], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'ops.example.test',
        ]);
        $route = new Route(['POST'], '/ops/login', static fn (): Response => response('ok'));
        $route->name('filament.ops.auth.login');
        $blockedRequest->setRouteResolver(static fn (): Route => $route);

        $blockedResponse = app(OpsAccessControl::class)->handle(
            $blockedRequest,
            static fn (): Response => response('blocked source allowed'),
        );

        $allowedRequest = Request::create('/ops/login', 'POST', [
            'email' => 'owner@example.test',
        ], [], [], [
            'REMOTE_ADDR' => '9.9.9.9',
            'HTTP_HOST' => 'ops.example.test',
        ]);
        $allowedRequest->setRouteResolver(static fn (): Route => $route);

        $allowedResponse = app(OpsAccessControl::class)->handle(
            $allowedRequest,
            static fn (): Response => response('unrelated admin allowed'),
        );

        $this->assertSame(429, $blockedResponse->getStatusCode());
        $this->assertSame(200, $allowedResponse->getStatusCode());
        $this->assertSame('unrelated admin allowed', $allowedResponse->getContent());
    }

    public function test_non_auth_ops_routes_remain_blocked_by_host_policy(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('ops.access_control.allowed_host', 'ops.example.test');
        config()->set('ops.access_control.ip_allowlist', []);
        config()->set('ops.access_control.rate_limit.global', 100);

        Redis::shouldReceive('sismember')
            ->never();
        Redis::shouldReceive('incr')
            ->never();

        $request = Request::create('/ops/orders', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'blocked.example.test',
        ]);
        $route = new Route(['GET'], '/ops/orders', static fn (): Response => response('ok'));
        $route->name('filament.ops.resources.orders.index');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(OpsAccessControl::class)->handle(
            $request,
            static fn (): Response => response('allowed'),
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_select_org_route_respects_host_and_ip_blocks(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('ops.access_control.allowed_host', 'ops.example.test');
        config()->set('ops.access_control.ip_allowlist', ['1.1.1.1']);

        Redis::shouldReceive('sismember')
            ->never();

        $request = Request::create('/ops/select-org', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'blocked.example.test',
        ]);
        $route = new Route(['GET'], '/ops/select-org', static fn (): Response => response('ok'));
        $route->name('filament.ops.pages.select-org');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(OpsAccessControl::class)->handle(
            $request,
            static fn (): Response => response('allowed'),
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_dashboard_route_is_not_treated_as_safe_route(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('ops.access_control.allowed_host', 'ops.example.test');
        config()->set('ops.access_control.ip_allowlist', []);
        config()->set('ops.access_control.rate_limit.global', 100);

        Redis::shouldReceive('sismember')
            ->never();
        Redis::shouldReceive('incr')
            ->never();

        $request = Request::create('/ops', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'blocked.example.test',
        ]);
        $route = new Route(['GET'], '/ops', static fn (): Response => response('ok'));
        $route->name('filament.ops.pages.dashboard');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(OpsAccessControl::class)->handle(
            $request,
            static fn (): Response => response('allowed'),
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_fail_open_allows_request_when_redis_lookup_throws(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('ops.access_control.fail_open', true);
        config()->set('ops.access_control.allowed_host', 'ops.example.test');

        Redis::shouldReceive('sismember')
            ->once()
            ->andThrow(new \RuntimeException('redis unavailable'));

        $request = Request::create('/ops/orders', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'ops.example.test',
        ]);
        $route = new Route(['GET'], '/ops/orders', static fn (): Response => response('ok'));
        $route->name('filament.ops.resources.orders.index');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(OpsAccessControl::class)->handle(
            $request,
            static fn (): Response => response('allowed'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('allowed', $response->getContent());
    }

    public function test_emergency_disable_bypasses_access_control(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('ops.access_control.enabled', true);
        config()->set('ops.access_control.emergency_disable', true);
        config()->set('ops.access_control.allowed_host', 'ops.example.test');
        config()->set('ops.access_control.ip_allowlist', ['1.1.1.1']);

        $request = Request::create('/ops/orders', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'blocked.example.test',
        ]);
        $route = new Route(['GET'], '/ops/orders', static fn (): Response => response('ok'));
        $route->name('filament.ops.resources.orders.index');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(OpsAccessControl::class)->handle(
            $request,
            static fn (): Response => response('allowed'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('allowed', $response->getContent());
    }
}
