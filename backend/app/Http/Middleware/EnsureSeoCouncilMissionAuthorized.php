<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Rbac\PermissionNames;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSeoCouncilMissionAuthorized
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        if (! is_object($user) || ! method_exists($user, 'hasPermission') || ! method_exists($user, 'getAuthIdentifier')) {
            return response()->json(['ok' => false, 'error_code' => 'UNAUTHORIZED', 'message' => 'admin_session_required'], 401);
        }
        $adminUserId = (int) $user->getAuthIdentifier();
        $ownerId = (int) config('review_governance.solo_owner_admin_user_id');
        if (($request->attributes->get('admin_auth_mode') ?? null) !== 'session'
            || ! $user->hasPermission(PermissionNames::ADMIN_OWNER)
            || $ownerId < 1
            || $adminUserId !== $ownerId) {
            return response()->json(['ok' => false, 'error_code' => 'FORBIDDEN', 'message' => 'owner_sensitive_action_required'], 403);
        }
        if (! $request->hasSession()
            || (int) $request->session()->get('ops_admin_totp_verified_user_id', 0) !== $adminUserId) {
            return response()->json(['ok' => false, 'error_code' => 'FORBIDDEN', 'message' => 'totp_verification_required'], 403);
        }

        return $next($request);
    }
}
