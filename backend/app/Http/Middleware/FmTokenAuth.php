<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * - token 表里的 anon_id 有时会是数字占位（如 "1"），而真实 anon_id 在请求侧（header/query/body）
     * - 本中间件会把“更可信的 anon_id”写入 attributes，保证 /me/attempts 能查到 attempt
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
            return $this->unauthorizedResponse();
        }

        // Always attach token for downstream use
        $request->attributes->set('fm_token', $token);

        // Resolve identity from DB: fm_tokens.token -> user_id / anon_id
        $row = DB::table('fm_tokens')->where('token', $token)->first();
        if (!$row) {
            return $this->unauthorizedResponse();
        }

        // expires_at check (only when present)
        if (property_exists($row, 'expires_at') && !empty($row->expires_at)) {
            $exp = strtotime((string) $row->expires_at);
            if ($exp !== false && $exp < time()) {
                return $this->unauthorizedResponse();
            }
        }

        // 1) user_id: only trust explicit user_id-like columns, never fall back to anon_id
        $userId = $this->resolveUserId($row);
        if ($userId !== '') {
            // basic sanity
            if (strlen($userId) > 64) {
                return $this->unauthorizedResponse();
            }
            // numeric-only (events.user_id is bigint)
            if (!preg_match('/^\d+$/', $userId)) {
                return $this->unauthorizedResponse();
            }

            $request->attributes->set('fm_user_id', $userId);
            $request->attributes->set('user_id', $userId);
        }

        // 2) anon_id: prefer token-bound anon_id; when it looks like numeric placeholder, prefer request-provided anon_id
        $anonId = $this->resolveBestAnonId($row, $request);
        if ($anonId !== '') {
            if (strlen($anonId) > 128) {
                return $this->unauthorizedResponse();
            }

            $request->attributes->set('anon_id', $anonId);
            $request->attributes->set('fm_anon_id', $anonId);
        }

        return $next($request);
    }

    private function resolveUserId(object $row): string
    {
        // prefer: fm_tokens.user_id
        if (property_exists($row, 'user_id')) {
            $v = trim((string) ($row->user_id ?? ''));
            if ($v !== '') return $v;
        }

        // legacy candidates (keep numeric contract)
        foreach (['uid', 'user_uid', 'user'] as $c) {
            if (property_exists($row, $c)) {
                $v = trim((string) ($row->{$c} ?? ''));
                if ($v !== '') return $v;
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

        // request-side anon_id candidates (CI accept_phone 会带这个)
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

        // 规则：
        // - token anon_id 非空 且不是纯数字占位 -> 用 token
        // - token anon_id 为空 -> 用 request
        // - token anon_id 是纯数字占位（如 "1"）且 request 有值 -> 用 request
        // - 其余 -> 用 token
        $tokenIsNumericPlaceholder = ($fromToken !== '' && preg_match('/^\d+$/', $fromToken) === 1);

        $chosen = '';
        if ($fromToken !== '' && !$tokenIsNumericPlaceholder) {
            $chosen = $fromToken;
        } elseif ($fromToken === '') {
            $chosen = $fromReq;
        } elseif ($tokenIsNumericPlaceholder && $fromReq !== '') {
            $chosen = $fromReq;
        } else {
            $chosen = $fromToken;
        }

        // sanitize blacklist
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
            'ok'      => false,
            'error'   => 'UNAUTHORIZED',
            'message' => 'Missing or invalid fm_token. Please login.',
        ], 401)->withHeaders([
            'WWW-Authenticate' => 'Bearer realm="Fermat API", error="invalid_token"',
        ]);
    }
}