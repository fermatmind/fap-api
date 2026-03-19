<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\FmTokenService;
use App\Services\Org\MembershipService;
use App\Support\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrgContext
{
    public function __construct(
        private MembershipService $membershipService,
        private FmTokenService $tokenService,
        private OrgContext $orgContext,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId < 0) {
            return $this->orgNotFoundResponse();
        }

        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        if ($anonId === null) {
            $anonId = $this->resolveAnonIdFromToken($request);
            if ($anonId !== null) {
                $request->attributes->set('anon_id', $anonId);
                $request->attributes->set('fm_anon_id', $anonId);
            }
        }
        $role = null;

        if ($orgId > 0) {
            if ($this->isAdminGuardAuthenticated()) {
                $role = 'admin';
            } else {
                if ($userId === null) {
                    $userId = $this->resolveUserIdFromToken($request);
                    if ($userId !== null) {
                        $request->attributes->set('fm_user_id', (string) $userId);
                        $request->attributes->set('user_id', (string) $userId);
                    }
                }

                if ($userId === null) {
                    return $this->orgNotFoundResponse();
                }

                $role = $this->membershipService->getRole($orgId, $userId);
                if ($role === null) {
                    return $this->orgNotFoundResponse();
                }
            }
        } else {
            $orgId = 0;

            if ($userId === null) {
                $userId = $this->resolveUserIdFromToken($request);
                if ($userId !== null) {
                    $request->attributes->set('fm_user_id', (string) $userId);
                    $request->attributes->set('user_id', (string) $userId);
                }
            }

            $role = $this->resolveTokenRole($request) ?? 'public';
        }

        $contextKind = OrgContext::deriveContextKind($orgId);

        $request->attributes->set('org_id', $orgId);
        $request->attributes->set('org_role', $role);
        $request->attributes->set('org_context_resolved', true);
        $request->attributes->set('org_context_kind', $contextKind);

        if ($orgId <= 0 && $this->isOpsSystemBypass($request, $role)) {
            $request->attributes->set('org_context_bypass', true);
        }

        $this->orgContext->set($orgId, $userId, $role, $anonId, $contextKind);
        app()->instance(OrgContext::class, $this->orgContext);

        return $next($request);
    }

    private function isAdminGuardAuthenticated(): bool
    {
        $guard = (string) config('admin.guard', 'admin');

        return auth($guard)->check();
    }

    private function resolveOrgId(Request $request): int
    {
        $candidates = [
            $request->header('X-FM-Org-Id'),
            $request->header('X-Org-Id'),
            $request->query('org_id'),
            $request->route('org_id'),
            $request->attributes->get('fm_org_id'),
            $request->attributes->get('org_id'),
        ];
        $tokenOrgId = $this->resolveOrgIdFromToken($request);
        if ($tokenOrgId !== null) {
            $candidates[] = $tokenOrgId;
        }

        $resolved = [];
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeOrgId($candidate);
            if ($normalized === null) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return -1;
                }

                continue;
            }

            $resolved[] = $normalized;
        }

        $resolved = array_values(array_unique($resolved));
        if ($resolved === []) {
            return 0;
        }

        $positive = array_values(array_filter($resolved, static fn (int $value): bool => $value > 0));
        $positive = array_values(array_unique($positive));
        if ($positive !== []) {
            return count($positive) === 1 ? $positive[0] : -1;
        }

        return count($resolved) === 1 ? $resolved[0] : -1;
    }

    private function normalizeOrgId(mixed $candidate): ?int
    {
        if (! is_int($candidate) && ! is_string($candidate) && ! is_numeric($candidate)) {
            return null;
        }

        $raw = trim((string) $candidate);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    private function resolveOrgIdFromToken(Request $request): ?int
    {
        $payload = $this->resolveFmTokenPayload($request);
        if (!($payload['ok'] ?? false)) {
            return null;
        }

        $orgId = $payload['org_id'] ?? null;
        if (!is_int($orgId)) {
            return null;
        }

        return $orgId;
    }

    private function resolveUserId(Request $request): ?int
    {
        $raw = (string) ($request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id') ?? '');
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    private function resolveUserIdFromToken(Request $request): ?int
    {
        $payload = $this->resolveFmTokenPayload($request);
        if (!($payload['ok'] ?? false)) {
            return null;
        }

        $userId = (string) ($payload['user_id'] ?? '');
        if ($userId === '' || preg_match('/^\d+$/', $userId) !== 1) {
            return null;
        }

        return (int) $userId;
    }

    private function resolveTokenRole(Request $request): ?string
    {
        $payload = $this->resolveFmTokenPayload($request);
        if (!($payload['ok'] ?? false)) {
            return null;
        }

        $role = trim((string) ($payload['role'] ?? ''));

        return $role !== '' ? $role : null;
    }

    private function resolveAnonIdFromToken(Request $request): ?string
    {
        $payload = $this->resolveFmTokenPayload($request);
        if (!($payload['ok'] ?? false)) {
            return null;
        }

        $anonId = trim((string) ($payload['anon_id'] ?? ''));
        if ($anonId === '') {
            return null;
        }

        return strlen($anonId) <= 128 ? $anonId : null;
    }

    /**
     * @return array{ok:bool,user_id:?string,expires_at:?string,org_id:int,role:string,anon_id:?string}|array{ok:false}
     */
    private function resolveFmTokenPayload(Request $request): array
    {
        $cached = $request->attributes->get('fm_token_payload');
        if (is_array($cached)) {
            return $cached;
        }

        $token = $this->extractBearerToken($request);
        if ($token === '') {
            $payload = ['ok' => false];
            $request->attributes->set('fm_token_payload', $payload);

            return $payload;
        }

        $payload = $this->tokenService->validateToken($token);
        if (!is_array($payload)) {
            $payload = ['ok' => false];
        }

        $request->attributes->set('fm_token_payload', $payload);

        return $payload;
    }

    private function extractBearerToken(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $m) === 1) {
            return trim((string) ($m[1] ?? ''));
        }

        return '';
    }

    private function resolveAnonId(Request $request): ?string
    {
        $raw = $request->attributes->get('anon_id') ?? $request->attributes->get('fm_anon_id') ?? '';
        if (is_string($raw) || is_numeric($raw)) {
            $value = trim((string) $raw);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function orgNotFoundResponse(): Response
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }

    private function isOpsSystemBypass(Request $request, ?string $role): bool
    {
        if (! $request->is('ops*')) {
            return false;
        }

        $normalizedRole = strtolower(trim((string) $role));

        return in_array($normalizedRole, ['system', 'ops', 'admin'], true);
    }
}
