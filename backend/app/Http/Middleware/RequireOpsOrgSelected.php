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
    private array $allowWithoutOrgExact = [
        'filament.ops.auth.login',
        'filament.ops.auth.logout',
        'filament.ops.home',
        'filament.ops.pages.dashboard',
        'filament.ops.pages.select-org',
        'filament.ops.pages.two-factor-challenge',
        'filament.ops.pages.organizations-import',
        'filament.ops.pages.go-live-gate',
        'filament.ops.pages.health-checks',
        'filament.ops.pages.queue-monitor',
    ];

    /**
     * @var list<string>
     */
    private array $allowWithoutOrgPrefixes = [
        'filament.ops.resources.admin-users.',
        'filament.ops.resources.roles.',
        'filament.ops.resources.permissions.',
        'filament.ops.resources.organizations.',
        'filament.ops.resources.deploys.',
    ];

    /**
     * @var list<string>
     */
    private array $orgScopedPages = [
        'filament.ops.pages.order-lookup',
        'filament.ops.pages.delivery-tools',
        'filament.ops.pages.secure-link',
        'filament.ops.pages.webhook-monitor',
        'filament.ops.pages.global-search',
    ];

    /**
     * @var list<string>
     */
    private array $orgScopedResourcePrefixes = [
        'filament.ops.resources.orders.',
        'filament.ops.resources.payment-events.',
        'filament.ops.resources.benefit-grants.',
        'filament.ops.resources.skus.',
        'filament.ops.resources.audit-logs.',
        'filament.ops.resources.admin-approvals.',
        'filament.ops.resources.content-pack-releases.',
        'filament.ops.resources.content-pack-versions.',
        'filament.ops.resources.content-releases.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = (string) optional($request->route())->getName();
        if ($routeName === '' || ! str_starts_with($routeName, 'filament.ops.')) {
            return $next($request);
        }

        if (in_array($routeName, $this->allowWithoutOrgExact, true)) {
            return $next($request);
        }

        foreach ($this->allowWithoutOrgPrefixes as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return $next($request);
            }
        }

        $isOrgScopedRoute = in_array($routeName, $this->orgScopedPages, true);
        if (! $isOrgScopedRoute) {
            foreach ($this->orgScopedResourcePrefixes as $prefix) {
                if (str_starts_with($routeName, $prefix)) {
                    $isOrgScopedRoute = true;
                    break;
                }
            }
        }

        if (! $isOrgScopedRoute && str_starts_with($routeName, 'filament.ops.resources.')) {
            $isOrgScopedRoute = true;
        }

        if (! $isOrgScopedRoute) {
            return $next($request);
        }

        $orgId = (int) app(OrgContext::class)->orgId();
        if ($orgId > 0) {
            return $next($request);
        }

        $request->session()->flash('ops_org_required_message', '需要先选择组织后再访问该模块。');

        return redirect()->route('filament.ops.pages.select-org', [
            'return_to' => '/' . ltrim($request->path(), '/'),
        ]);
    }
}
