<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class FmTokenAuth
{
    /**
     * Minimal Bearer token gate + resolve identity.
     *
     * Expect:
     *   Authorization: Bearer fm_xxxxx
     *
     * - validate fm_token format
     * - resolve user_id / anon_id from fm_tokens table
     * - attach fm_token / fm_user_id / user_id / anon_id / fm_anon_id to request attributes
     *
     * CI accept_phone 关键点：
     * - token 可能没有 user_id
     * - anon_id 一律以 token 绑定值为准，避免伪造 request 造成越权
     * - request 里的 anon_id 仅用于日志/事件 meta
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        // Accept: "Bearer <token>"
        $token = '';
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $m)) {
            $token = trim((string) ($m[1] ?? ''));
        }

        // Minimal format check: "fm_" + UUID
        $isOk = $token !== '' && preg_match(
            '/^fm_[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $token
        );

        if (!$isOk) {
            return $this->unauthorizedResponse($request, 'token_missing_or_invalid');
        }

        // Always attach token for downstream use
        $request->attributes->set('fm_token', $token);

        // Resolve identity from DB: fm_tokens.token -> user_id / anon_id
        if (!Schema::hasTable('fm_tokens')) {
            return $this->unauthorizedResponse($request, 'token_table_missing');
        }
        $columns = ['token'];
        foreach (['user_id', 'fm_user_id', 'anon_id', 'org_id', 'meta_json', 'expires_at'] as $col) {
            if (Schema::hasColumn('fm_tokens', $col)) {
                $columns[] = $col;
            }
        }
        $row = DB::table('fm_tokens')->select($columns)->where('token', $token)->first();
        if (!$row) {
            return $this->unauthorizedResponse($request, 'token_not_found');
        }

        // Always expose identity keys after DB lookup; values remain null when unresolved.
        $request->attributes->set('fm_user_id', null);
        $request->attributes->set('user_id', null);

        // expires_at check (only when present)
        if (property_exists($row, 'expires_at') && !empty($row->expires_at)) {
            $exp = strtotime((string) $row->expires_at);
            if ($exp !== false && $exp < time()) {
                return $this->unauthorizedResponse($request, 'token_expired');
            }
        }

        // 1) user_id: only trust explicit user_id-like columns, never fall back to anon_id
        $resolvedUserId = $this->resolveUserId($row);
        if ($resolvedUserId !== '') {
            $resolvedUserId = (string) $resolvedUserId;
            // basic sanity
            if (strlen($resolvedUserId) > 64) {
                return $this->unauthorizedResponse($request, 'user_id_too_long');
            }
            // numeric-only (events.user_id is bigint)
            if (!preg_match('/^\d+$/', $resolvedUserId)) {
                return $this->unauthorizedResponse($request, 'user_id_not_numeric');
            }

            // Always inject fm_user_id once DB lookup provides a valid numeric identity.
            $request->attributes->set('fm_user_id', $resolvedUserId);
        }

        $existingUserId = $this->resolveExistingUserId($resolvedUserId);
        if ($existingUserId !== '') {
            $request->attributes->set('user_id', $existingUserId);
        }

        // 2) anon_id: always trust token-bound anon_id
        $anonId = $this->resolveBestAnonId($row, $request);
        if ($anonId !== '') {
            if (strlen($anonId) > 128) {
                return $this->unauthorizedResponse($request, 'anon_id_too_long');
            }

            $request->attributes->set('anon_id', $anonId);
            $request->attributes->set('fm_anon_id', $anonId);
        }

        $orgId = $this->resolveOrgId($row);
        if ($orgId !== null) {
            $request->attributes->set('fm_org_id', $orgId);
        }

        $this->logAuthResult($request, true);

        return $next($request);
    }

    private function resolveUserId(object $row): string
    {
        // prefer: fm_tokens.user_id
        if (property_exists($row, 'user_id')) {
            $v = trim((string) ($row->user_id ?? ''));
            if ($v !== '' && preg_match('/^\d+$/', $v)) return $v;
        }

        // optional alias
        if (property_exists($row, 'fm_user_id')) {
            $v = trim((string) ($row->fm_user_id ?? ''));
            if ($v !== '' && preg_match('/^\d+$/', $v)) return $v;
        }

        // meta_json fallback (when tokens are issued by legacy services)
        if (property_exists($row, 'meta_json')) {
            $meta = $row->meta_json ?? null;
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            if (is_array($meta)) {
                $v = trim((string) ($meta['user_id'] ?? $meta['fm_user_id'] ?? ''));
                if ($v !== '' && preg_match('/^\d+$/', $v)) return $v;
            }
        }

        // legacy candidates (keep numeric contract)
        foreach (['uid', 'user_uid', 'user'] as $c) {
            if (property_exists($row, $c)) {
                $v = trim((string) ($row->{$c} ?? ''));
                if ($v !== '' && preg_match('/^\d+$/', $v)) return $v;
            }
        }

        return '';
    }

    private function resolveExistingUserId(string $userId): string
    {
        $candidate = trim($userId);
        if ($candidate === '' || !preg_match('/^\d+$/', $candidate)) {
            return '';
        }

        if (!Schema::hasTable('users')) {
            return $candidate;
        }

        $exists = DB::table('users')->where('id', (int) $candidate)->exists();
        return $exists ? $candidate : '';
    }

    private function resolveBestAnonId(object $row, Request $request): string
    {
        // ✅ 一律以 token 绑定的 anon_id 为准，避免 token + 伪造 anon_id 越权
        $fromToken = '';
        if (property_exists($row, 'anon_id')) {
            $fromToken = trim((string) ($row->anon_id ?? ''));
        }

        if ($fromToken === '') return '';

        // sanitize blacklist
        $lower = mb_strtolower($fromToken, 'UTF-8');
        foreach (['todo', 'placeholder', 'fixme', 'tbd', '填这里'] as $bad) {
            if ($bad !== '' && mb_strpos($lower, $bad) !== false) {
                return '';
            }
        }

        return $fromToken;
    }

    private function resolveOrgId(object $row): ?int
    {
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

    private function unauthorizedResponse(Request $request, string $reason): Response
    {
        $this->logAuthResult($request, false, $reason);

        return response()->json([
            'ok' => false,
            'error' => 'UNAUTHORIZED',
            'error_code' => 'UNAUTHORIZED',
            'message' => 'Missing or invalid fm_token. Please login.',
        ], 401)->withHeaders([
            'WWW-Authenticate' => 'Bearer realm="Fermat API", error="invalid_token"',
        ]);
    }

    private function logAuthResult(Request $request, bool $ok, string $reason = ''): void
    {
        $context = [
            'ok' => $ok,
            'path' => $request->path(),
            'method' => $request->method(),
            'attempt_id' => $this->extractAttemptId($request),
        ];

        if ($reason !== '') {
            $context['reason'] = $reason;
        }

        Log::info($ok ? '[fm_token_auth] passed' : '[fm_token_auth] failed', $context);
    }

    private function extractAttemptId(Request $request): ?string
    {
        $routeAttemptId = $request->route('attempt_id');
        if (is_string($routeAttemptId) || is_numeric($routeAttemptId)) {
            $val = trim((string) $routeAttemptId);
            if ($val !== '') return $val;
        }

        $routeId = $request->route('id');
        if (is_string($routeId) || is_numeric($routeId)) {
            $val = trim((string) $routeId);
            if ($val !== '') return $val;
        }

        $bodyAttemptId = $request->input('attempt_id');
        if (is_string($bodyAttemptId) || is_numeric($bodyAttemptId)) {
            $val = trim((string) $bodyAttemptId);
            if ($val !== '') return $val;
        }

        return null;
    }
}
