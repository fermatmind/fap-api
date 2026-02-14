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
        $rawOrgId = (string) $request->session()->get('ops_org_id', '');
        if ($rawOrgId !== '' && preg_match('/^\d+$/', $rawOrgId) === 1) {
            $orgId = (int) $rawOrgId;
            if ($orgId > 0) {
                $request->attributes->set('fm_org_id', $orgId);
                $request->attributes->set('org_id', $orgId);
            }
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
}
