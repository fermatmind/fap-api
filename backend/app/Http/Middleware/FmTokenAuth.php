<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * - attach fm_token / fm_user_id / user_id / anon_id to request attributes
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
        $isOk =
            $token !== '' &&
            preg_match(
                '/^fm_[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $token
            );

        if (!$isOk) {
            return $this->unauthorizedResponse();
        }

        // ✅ Always attach token for downstream use
        $request->attributes->set('fm_token', $token);

        // ✅ Resolve identity from DB: fm_tokens.token -> user_id / anon_id
        $row = DB::table('fm_tokens')
            ->where('token', $token)
            ->first();

        if (!$row) {
            return $this->unauthorizedResponse();
        }

        // Optional: expires check
        if (!empty($row->expires_at)) {
            $exp = strtotime((string) $row->expires_at);
            if ($exp !== false && $exp < time()) {
                return $this->unauthorizedResponse();
            }
        }

        $userId = $this->resolveUserId($row);
        if ($userId !== '') {
            // basic sanity
            if (strlen($userId) > 128) {
                return $this->unauthorizedResponse();
            }
            // ✅✅ 统一挂两份，避免下游读不同 key
            $request->attributes->set('fm_user_id', $userId);
            $request->attributes->set('user_id', $userId);
        }

        $anonId = $this->resolveAnonId($row);
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
        // 优先：fm_tokens.user_id
        if (Schema::hasColumn('fm_tokens', 'user_id')) {
            $v = trim((string) ($row->user_id ?? ''));
            if ($v !== '') return $v;
        }

        // 兼容字段（老数据）
        $candidates = ['uid', 'user_uid', 'user'];
        foreach ($candidates as $c) {
            if (property_exists($row, $c)) {
                $val = trim((string) ($row->{$c} ?? ''));
                if ($val !== '') return $val;
            }
        }

        // 最后兜底：anon_id（仅在确实需要且结构如此时才会返回）
        if (Schema::hasColumn('fm_tokens', 'anon_id')) {
            $v = trim((string) ($row->anon_id ?? ''));
            if ($v !== '') return $v;
        }

        return '';
    }

    private function resolveAnonId(object $row): string
    {
        if (Schema::hasColumn('fm_tokens', 'anon_id')) {
            return trim((string) ($row->anon_id ?? ''));
        }
        return '';
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