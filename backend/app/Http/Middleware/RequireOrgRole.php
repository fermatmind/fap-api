<?php

namespace App\Http\Middleware;

use App\Services\Org\MembershipService;
use App\Support\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOrgRole
{
    public function __construct(
        private MembershipService $memberships,
        private OrgContext $orgContext,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId <= 0) {
            return $this->orgNotFound();
        }

        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return $this->orgNotFound();
        }

        $role = $this->memberships->getRole($orgId, $userId);
        if ($role === null) {
            return $this->orgNotFound();
        }

        if ($roles !== [] && !in_array($role, $roles, true)) {
            return $this->orgNotFound();
        }

        $request->attributes->set('org_id', $orgId);
        $request->attributes->set('org_role', $role);

        $anonId = $this->resolveAnonId($request);
        $this->orgContext->set($orgId, $userId, $role, $anonId);
        app()->instance(OrgContext::class, $this->orgContext);

        return $next($request);
    }

    private function resolveOrgId(Request $request): int
    {
        $raw = $request->route('org_id');
        if (is_string($raw) || is_numeric($raw)) {
            $val = trim((string) $raw);
            if ($val !== '' && preg_match('/^\d+$/', $val)) {
                return (int) $val;
            }
        }

        return 0;
    }

    private function resolveUserId(Request $request): ?int
    {
        $raw = (string) ($request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id') ?? '');
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function resolveAnonId(Request $request): ?string
    {
        $raw = $request->attributes->get('anon_id') ?? $request->attributes->get('fm_anon_id') ?? '';
        if (is_string($raw) || is_numeric($raw)) {
            $val = trim((string) $raw);
            if ($val !== '') {
                return $val;
            }
        }

        return null;
    }

    private function orgNotFound(): Response
    {
        return response()->json([
            'ok' => false,
            'error' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }
}
