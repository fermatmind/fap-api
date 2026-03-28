<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetOpsRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $orgId = $this->resolveOpsOrgId($request);
        if ($orgId > 0) {
            $request->attributes->set('fm_org_id', $orgId);
            $request->attributes->set('org_id', $orgId);
        }

        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        $adminUserId = is_object($user) && method_exists($user, 'getAuthIdentifier')
            ? (int) $user->getAuthIdentifier()
            : 0;

        if ($adminUserId > 0) {
            $request->attributes->set('fm_admin_user_id', $adminUserId);
            $request->attributes->set('fm_user_id', (string) $adminUserId);
            $request->attributes->set('user_id', (string) $adminUserId);
        }

        return $next($request);
    }

    private function resolveOpsOrgId(Request $request): int
    {
        $rawSessionOrgId = (string) $request->session()->get('ops_org_id', '');
        if ($rawSessionOrgId !== '' && preg_match('/^\d+$/', $rawSessionOrgId) === 1) {
            return max(0, (int) $rawSessionOrgId);
        }

        $rawCookieOrgId = (string) $request->cookie('ops_org_id', '');
        if ($rawCookieOrgId !== '' && preg_match('/^\d+$/', $rawCookieOrgId) === 1) {
            $orgId = max(0, (int) $rawCookieOrgId);
            if ($orgId > 0) {
                $request->session()->put('ops_org_id', $orgId);
            }

            return $orgId;
        }

        return 0;
    }
}
