<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class OpsLoginLimiterResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_admin_login_clears_distributed_login_limiter_keys(): void
    {
        $request = Request::create('/ops/login', 'POST', [
            'email' => 'ops@example.test',
        ], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'ops.example.test',
        ]);
        $route = new Route(['POST'], '/ops/login', static fn () => response('ok'));
        $route->name('filament.ops.auth.login');
        $request->setRouteResolver(static fn (): Route => $route);
        $this->app->instance('request', $request);

        Redis::shouldReceive('del')
            ->once()
            ->with('ops:login:ip:8.8.8.8')
            ->andReturn(1);
        Redis::shouldReceive('del')
            ->once()
            ->with('ops:login:user:ops@example.test')
            ->andReturn(1);

        Event::dispatch(new Login('admin', new class implements Authenticatable
        {
            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 1;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): string
            {
                return '';
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        }, false));

        $this->assertTrue(true);
    }
}
