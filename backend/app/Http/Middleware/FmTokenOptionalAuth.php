<?php

namespace App\Http\Middleware;

use App\Support\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class FmTokenOptionalAuth
{
    /**
     * Optional token auth:
     * - No Authorization header: allow request pass through
     * - With Authorization: validate token, attach identity attributes
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        // Accept: "Bearer <token>"
        $token = '';
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $m)) {
            $token = trim((string) ($m[1] ?? ''));
        }

        // no token -> allow
        if ($token === '') {
            return $next($request);
        }

        // format check: "fm_" + UUID
        $isOk = preg_match(
            '/^fm_[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $token
        ) === 1;

        if (! $isOk) {
            return $this->unauthorizedResponse();
        }

        $request->attributes->set('fm_token', $token);

        $tokenHash = hash('sha256', $token);
        $select = [
            'token_hash',
            'user_id',
            'anon_id',
            'org_id',
            'role',
            'meta_json',
            'expires_at',
            'revoked_at',
        ];

        $row = $this->findTokenRow($token, $tokenHash, $select);

        if (! $row) {
            return $this->unauthorizedResponse();
        }

        if (property_exists($row, 'revoked_at') && ! empty($row->revoked_at)) {
            return $this->unauthorizedResponse();
        }

        // expires_at check (only when present)
        if (property_exists($row, 'expires_at') && ! empty($row->expires_at)) {
            $exp = strtotime((string) $row->expires_at);
            if ($exp !== false && $exp < time()) {
                return $this->unauthorizedResponse();
            }
        }

        // user_id: only trust explicit user_id-like columns, never fall back to anon_id
        $userId = $this->resolveUserId($row);
        if ($userId !== '') {
            if (strlen($userId) > 64) {
                return $this->unauthorizedResponse();
            }
            if (preg_match('/^\d+$/', $userId) !== 1) {
                return $this->unauthorizedResponse();
            }
            $request->attributes->set('fm_user_id', $userId);
            $request->attributes->set('user_id', $userId);
        }

        // anon_id: prefer token-bound anon_id; when it looks numeric placeholder, prefer request-provided anon_id
        $anonId = $this->resolveBestAnonId($row, $request);
        if ($anonId !== '') {
            if (strlen($anonId) > 128) {
                return $this->unauthorizedResponse();
            }
            $request->attributes->set('anon_id', $anonId);
            $request->attributes->set('fm_anon_id', $anonId);
        }

        $orgId = $this->resolveNumeric($row->org_id ?? null) ?? 0;
        $role = $this->resolveRole($row->role ?? null, $row->meta_json ?? null);

        $request->attributes->set('fm_org_id', $orgId);
        $request->attributes->set('org_id', $orgId);
        $request->attributes->set('org_role', $role);
        $request->attributes->set('org_context_resolved', true);
        $request->attributes->set('org_context_kind', OrgContext::deriveContextKind($orgId));

        if ($orgId <= 0 && $this->isOpsSystemBypass($request, $role)) {
            $request->attributes->set('org_context_bypass', true);
        }

        $ctx = new OrgContext;
        $ctx->set(
            $orgId,
            preg_match('/^\d+$/', $userId) === 1 ? (int) $userId : null,
            $role,
            $anonId !== '' ? $anonId : null,
            OrgContext::deriveContextKind($orgId)
        );
        app()->instance(OrgContext::class, $ctx);

        return $next($request);
    }

    private function resolveUserId(object $row): string
    {
        if (property_exists($row, 'user_id')) {
            $v = trim((string) ($row->user_id ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        foreach (['uid', 'user_uid', 'user'] as $c) {
            if (property_exists($row, $c)) {
                $v = trim((string) ($row->{$c} ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    private function resolveBestAnonId(object $row, Request $request): string
    {
        $fromToken = '';
        if (property_exists($row, 'anon_id')) {
            $fromToken = trim((string) ($row->anon_id ?? ''));
        }

        $fromReq = '';
        $candidates = [
            (string) $request->header('X-Anon-Id', ''),
            (string) $request->header('X-Fm-Anon-Id', ''),
            (string) $request->query('anon_id', ''),
            (string) $request->input('anon_id', ''),
        ];
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c !== '') {
                $fromReq = $c;
                break;
            }
        }

        $tokenIsNumericPlaceholder = ($fromToken !== '' && preg_match('/^\d+$/', $fromToken) === 1);

        $chosen = '';
        if ($fromToken !== '' && ! $tokenIsNumericPlaceholder) {
            $chosen = $fromToken;
        } elseif ($fromToken === '') {
            $chosen = $fromReq;
        } elseif ($tokenIsNumericPlaceholder && $fromReq !== '') {
            $chosen = $fromReq;
        } else {
            $chosen = $fromToken;
        }

        $lower = mb_strtolower($chosen, 'UTF-8');
        foreach (['todo', 'placeholder', 'fixme', 'tbd', '填这里'] as $bad) {
            if ($bad !== '' && mb_strpos($lower, $bad) !== false) {
                return '';
            }
        }

        return $chosen;
    }

    private function unauthorizedResponse(): Response
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
            'message' => 'Missing or invalid fm_token. Please login.',
        ], 401)->withHeaders([
            'WWW-Authenticate' => 'Bearer realm="Fermat API", error="invalid_token"',
        ]);
    }

    private function resolveNumeric(mixed $candidate): ?int
    {
        $raw = trim((string) $candidate);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    private function resolveRole(mixed $roleCandidate, mixed $metaCandidate): string
    {
        $role = trim((string) $roleCandidate);
        if ($role !== '') {
            return $role;
        }

        if (is_array($metaCandidate)) {
            $metaRole = trim((string) ($metaCandidate['role'] ?? ''));
            if ($metaRole !== '') {
                return $metaRole;
            }
        }

        if (is_string($metaCandidate) && $metaCandidate !== '') {
            $decoded = json_decode($metaCandidate, true);
            if (is_array($decoded)) {
                $metaRole = trim((string) ($decoded['role'] ?? ''));
                if ($metaRole !== '') {
                    return $metaRole;
                }
            }
        }

        return 'public';
    }

    /**
     * @param  array<int,string>  $select
     */
    private function findTokenRow(string $token, string $tokenHash, array $select): ?object
    {
        try {
            $authRow = DB::table('auth_tokens')
                ->select($select)
                ->where('token_hash', $tokenHash)
                ->first();
            if ($authRow) {
                return $authRow;
            }
        } catch (\Throwable $e) {
            Log::warning('[SEC] auth_tokens_lookup_failed', [
                'path' => 'middleware.fm_token_optional_auth',
                'exception' => $e::class,
            ]);
        }

        if (! $this->shouldAllowLegacyTestingTokenFallback()) {
            return null;
        }

        try {
            return DB::table('fm_tokens')
                ->select($select)
                ->where('token', $token)
                ->where('token_hash', $tokenHash)
                ->first() ?: null;
        } catch (\Throwable $e) {
            Log::warning('[SEC] fm_tokens_legacy_lookup_failed', [
                'path' => 'middleware.fm_token_optional_auth',
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    private function shouldAllowLegacyTestingTokenFallback(): bool
    {
        return app()->environment(['testing', 'ci']);
    }

    private function isOpsSystemBypass(Request $request, string $role): bool
    {
        if (! $request->is('ops*')) {
            return false;
        }

        $normalizedRole = strtolower(trim($role));

        return in_array($normalizedRole, ['system', 'ops', 'admin'], true);
    }
}
