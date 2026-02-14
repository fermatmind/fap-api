<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOpsOrgSelected
{
    /**
     * @var list<string>
     */
    private array $orgScopedRoutePrefixes = [
        'filament.ops.resources.audit-logs.',
    ];

    /**
     * @var list<string>
     */
    private array $bypassRoutes = [
        'filament.ops.auth.login',
        'filament.ops.auth.logout',
        'filament.ops.home',
        'filament.ops.pages.dashboard',
        'filament.ops.pages.select-org',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = (string) optional($request->route())->getName();
        if ($routeName === '' || !str_starts_with($routeName, 'filament.ops.')) {
            return $next($request);
        }

        if (in_array($routeName, $this->bypassRoutes, true)) {
            return $next($request);
        }

        $isOrgScopedRoute = false;
        foreach ($this->orgScopedRoutePrefixes as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                $isOrgScopedRoute = true;
                break;
            }
        }

        if (!$isOrgScopedRoute) {
            return $next($request);
        }

        $orgId = (int) app(OrgContext::class)->orgId();
        if ($orgId > 0) {
            return $next($request);
        }

        return redirect()->route('filament.ops.pages.select-org');
    }
}
