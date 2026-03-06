<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCmsAdminAuthorized
{
    public function __construct(
        private readonly OrgContext $orgContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (
            ! is_object($user)
            || ! method_exists($user, 'hasPermission')
            || ! method_exists($user, 'getAuthIdentifier')
        ) {
            return $this->unauthorizedResponse('admin_session_required');
        }

        if (
            ! $user->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH)
            && ! $user->hasPermission(PermissionNames::ADMIN_OWNER)
        ) {
            return $this->forbiddenResponse('admin_content_publish_required');
        }

        $adminUserId = (int) $user->getAuthIdentifier();
        $orgId = $this->resolveTrustedOrgId($request);

        $request->attributes->set('fm_admin_user_id', $adminUserId);
        $request->attributes->set('fm_user_id', (string) $adminUserId);
        $request->attributes->set('user_id', (string) $adminUserId);
        $request->attributes->set('fm_org_id', $orgId);
        $request->attributes->set('org_id', $orgId);
        $request->attributes->set('org_role', 'admin');
        $request->attributes->set('org_context_resolved', true);
        $request->attributes->set('org_context_trusted', true);

        $this->orgContext->set($orgId, $adminUserId, 'admin');
        app()->instance(OrgContext::class, $this->orgContext);

        return $next($request);
    }

    private function resolveTrustedOrgId(Request $request): int
    {
        $candidates = [
            $request->attributes->get('fm_org_id'),
            $request->hasSession() ? $request->session()->get('ops_org_id') : null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_int($candidate) && ! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }

            $raw = trim((string) $candidate);
            if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
                continue;
            }

            return max(0, (int) $raw);
        }

        return 0;
    }

    private function unauthorizedResponse(string $reason): Response
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
            'message' => $reason,
        ], 401);
    }

    private function forbiddenResponse(string $reason): Response
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'FORBIDDEN',
            'message' => $reason,
        ], 403);
    }
}
