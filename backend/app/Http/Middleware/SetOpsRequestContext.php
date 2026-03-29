<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetOpsRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $orgId = $this->resolveOpsOrgId($request);
        if ($orgId > 0) {
            $request->session()->put('ops_org_id', $orgId);
            $request->attributes->set('ops_org_id', $orgId);
            $request->attributes->set('fm_org_id', $orgId);
            $request->attributes->set('org_id', $orgId);
            $this->queueNormalizedOpsOrgCookie($orgId);
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

        if ($orgId > 0) {
            $context = app(OrgContext::class);
            $context->set(
                $orgId,
                $adminUserId > 0 ? $adminUserId : $context->userId(),
                $adminUserId > 0 ? 'admin' : $context->role(),
                $context->anonId(),
                OrgContext::KIND_TENANT,
            );
            app()->instance(OrgContext::class, $context);
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

    private function queueNormalizedOpsOrgCookie(int $orgId): void
    {
        Cookie::queue(Cookie::forget('ops_org_id', '/ops'));
        Cookie::queue(cookie(
            name: 'ops_org_id',
            value: (string) $orgId,
            minutes: 60 * 24 * 30,
            path: '/',
            domain: null,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }
}
