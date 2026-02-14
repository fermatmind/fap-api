<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetTenantRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = (string) config('tenant.guard', 'tenant');
        $user = auth($guard)->user();

        $userId = is_object($user) && method_exists($user, 'getAuthIdentifier')
            ? (int) $user->getAuthIdentifier()
            : 0;

        if ($userId <= 0) {
            return $next($request);
        }

        $request->attributes->set('fm_user_id', (string) $userId);
        $request->attributes->set('user_id', (string) $userId);

        $orgIds = DB::table('organization_members')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->select('org_id')
            ->distinct()
            ->orderBy('org_id')
            ->limit(2)
            ->pluck('org_id')
            ->filter(static fn ($orgId): bool => is_numeric($orgId) && (int) $orgId > 0)
            ->map(static fn ($orgId): int => (int) $orgId)
            ->values();

        if ($orgIds->count() !== 1) {
            return response()->make('Tenant organization context invalid.', 403);
        }

        $orgId = (int) $orgIds->first();
        $request->attributes->set('fm_org_id', $orgId);
        $request->attributes->set('org_id', $orgId);

        return $next($request);
    }
}
