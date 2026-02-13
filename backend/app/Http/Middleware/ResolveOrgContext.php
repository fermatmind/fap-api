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
            $role = $this->resolveTokenRole($request) ?? 'public';
        }

        $request->attributes->set('org_id', $orgId);
        $request->attributes->set('org_role', $role);

        $this->orgContext->set($orgId, $userId, $role, $anonId);
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
        $header = trim((string) $request->header('X-FM-Org-Id', ''));
        if ($header === '') {
            $header = trim((string) $request->header('X-Org-Id', ''));
        }
        if ($header === '') {
            $header = trim((string) $request->query('org_id', ''));
        }

        if ($header !== '') {
            if (preg_match('/^\d+$/', $header) !== 1) {
                return -1;
            }
            return (int) $header;
        }

        $attr = $request->attributes->get('fm_org_id');
        if (is_numeric($attr)) {
            return (int) $attr;
        }

        $tokenOrgId = $this->resolveOrgIdFromToken($request);
        if ($tokenOrgId !== null) {
            return $tokenOrgId;
        }

        return 0;
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
            'error' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }
}
