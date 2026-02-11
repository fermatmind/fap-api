<?php

namespace App\Http\Middleware;

use App\Services\Auth\FmTokenService;
use App\Services\Org\MembershipService;
use App\Support\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $role = null;
        $anonId = $this->resolveAnonId($request);

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
            $role = 'public';
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
            if (!preg_match('/^\d+$/', $header)) {
                return -1;
            }
            return (int) $header;
        }

        $attr = $request->attributes->get('fm_org_id');
        if (is_numeric($attr)) {
            return (int) $attr;
        }

        $fromToken = $this->resolveOrgIdFromToken($request);
        if ($fromToken !== null) {
            return $fromToken;
        }

        return 0;
    }

    private function resolveOrgIdFromToken(Request $request): ?int
    {
        $token = $this->extractBearerToken($request);
        if ($token === '') {
            return null;
        }

        if (!Schema::hasTable('fm_tokens')) {
            return null;
        }

        $row = DB::table('fm_tokens')->where('token', $token)->first();
        if (!$row) {
            return null;
        }

        if (property_exists($row, 'org_id')) {
            $raw = trim((string) ($row->org_id ?? ''));
            if ($raw !== '' && preg_match('/^\d+$/', $raw)) {
                return (int) $raw;
            }
        }

        if (property_exists($row, 'meta_json')) {
            $meta = $row->meta_json ?? null;
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            if (is_array($meta)) {
                $raw = trim((string) ($meta['org_id'] ?? ''));
                if ($raw !== '' && preg_match('/^\d+$/', $raw)) {
                    return (int) $raw;
                }
            }
        }

        return null;
    }

    private function resolveUserId(Request $request): ?int
    {
        $raw = (string) ($request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id') ?? '');
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function resolveUserIdFromToken(Request $request): ?int
    {
        $token = $this->extractBearerToken($request);
        if ($token === '') {
            return null;
        }

        $res = $this->tokenService->validateToken($token);
        if (!($res['ok'] ?? false)) {
            return null;
        }

        $userId = (string) ($res['user_id'] ?? '');
        if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
            return null;
        }

        return (int) $userId;
    }

    private function extractBearerToken(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^\\s*Bearer\\s+(.+)\\s*$/i', $header, $m)) {
            return trim((string) ($m[1] ?? ''));
        }

        return '';
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

    private function orgNotFoundResponse(): Response
    {
        return response()->json([
            'ok' => false,
            'error' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }
}
