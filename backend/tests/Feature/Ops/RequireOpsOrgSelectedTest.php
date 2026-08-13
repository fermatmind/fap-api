<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Http\Middleware\RequireOpsOrgSelected;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class RequireOpsOrgSelectedTest extends TestCase
{
    public function test_global_scale_registry_resource_does_not_require_a_tenant_org(): void
    {
        $response = $this->handleRoute(
            'filament.ops.resources.scale-registries.index',
            '/ops/scale-registries',
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('allowed', $response->getContent());
    }

    public function test_tenant_scoped_resource_still_requires_an_org(): void
    {
        $response = $this->handleRoute(
            'filament.ops.resources.articles.index',
            '/ops/articles',
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/ops/select-org', (string) $response->headers->get('Location'));
    }

    private function handleRoute(string $routeName, string $path): Response
    {
        $request = Request::create($path, 'GET');
        $request->setLaravelSession(app('session')->driver());

        $route = new Route(['GET'], $path, static fn (): Response => response('route'));
        $route->name($routeName);
        $request->setRouteResolver(static fn (): Route => $route);
        $this->assertSame($routeName, $request->route()?->getName());

        return app(RequireOpsOrgSelected::class)->handle(
            $request,
            static fn (): Response => response('allowed'),
        );
    }
}
