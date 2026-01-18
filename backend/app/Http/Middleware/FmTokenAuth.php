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
     * Phase A+:
     * - validate fm_token format
     * - resolve anon_id from fm_tokens table
     * - attach fm_token / fm_anon_id to request attributes
     *
     * Compatibility:
     * - if DB mapping missing, allow X-FM-Anon-Id as temporary fallback (optional)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        // Accept: "Bearer <token>"
        $token = '';
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $m)) {
            $token = trim((string)($m[1] ?? ''));
        }

        // Minimal format check: "fm_" + UUID
        $isOk =
            $token !== '' &&
            preg_match(
                '/^fm_[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $token
            );

        if (!$isOk) {
            return response()->json([
                'ok'      => false,
                'error'   => 'UNAUTHORIZED',
                'message' => 'Missing or invalid fm_token. Please login.',
            ], 401)->withHeaders([
                'WWW-Authenticate' => 'Bearer realm="Fermat API", error="invalid_token"',
            ]);
        }

        // ✅ Always attach token for downstream use
        $request->attributes->set('fm_token', $token);

        // ✅ Resolve identity from DB: fm_tokens.token -> anon_id
        $row = DB::table('fm_tokens')
            ->select(['anon_id', 'expires_at'])
            ->where('token', $token)
            ->first();

        $anonId = $row && !empty($row->anon_id) ? trim((string)$row->anon_id) : '';

        // Optional: expires check (目前你是 null，不会触发)
        if ($row && !empty($row->expires_at)) {
            $exp = strtotime((string)$row->expires_at);
            if ($exp !== false && $exp < time()) {
                return response()->json([
                    'ok'      => false,
                    'error'   => 'UNAUTHORIZED',
                    'message' => 'fm_token expired. Please login again.',
                ], 401)->withHeaders([
                    'WWW-Authenticate' => 'Bearer realm="Fermat API", error="invalid_token"',
                ]);
            }
        }

        // ✅ Compatibility fallback (可选)：如果 DB 里没有映射，允许旧 header 临时兜底
        if ($anonId === '') {
            $anonId = trim((string) $request->header('X-FM-Anon-Id', ''));
            if ($anonId === '') $anonId = trim((string) $request->header('X-Anon-Id', ''));
            if ($anonId === '') $anonId = trim((string) $request->header('X-Device-Anon-Id', ''));
        }

        if ($anonId === '') {
            return response()->json([
                'ok'      => false,
                'error'   => 'UNAUTHORIZED',
                'message' => 'Invalid fm_token (no identity). Please login again.',
            ], 401)->withHeaders([
                'WWW-Authenticate' => 'Bearer realm="Fermat API", error="invalid_token"',
            ]);
        }

        // basic sanity
        if (strlen($anonId) > 128) {
            return response()->json([
                'ok'      => false,
                'error'   => 'UNAUTHORIZED',
                'message' => 'Invalid identity on token.',
            ], 401);
        }

        // ✅ Attach canonical + fm_* aliases so controllers can read either
        $request->attributes->set('anon_id', $anonId);
        $request->attributes->set('fm_anon_id', $anonId);

        // Phase B 才会用到 user_id，这里先留空位
        // $request->attributes->set('fm_user_id', $userId);

        return $next($request);
    }
}