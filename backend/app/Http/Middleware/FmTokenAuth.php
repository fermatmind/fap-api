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

        // Always attach token for downstream use
        $request->attributes->set('fm_token', $token);

        // Resolve identity from DB: fm_tokens.token -> user_id / anon_id
        $row = DB::table('fm_tokens')
            ->select(['user_id', 'anon_id', 'expires_at'])
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

        // user_id must be numeric and > 0 (events.user_id is bigint)
        $uidRaw = trim((string) ($row->user_id ?? ''));
        if ($uidRaw === '' || !ctype_digit($uidRaw)) {
            return $this->unauthorizedResponse();
        }
        $uid = (int) $uidRaw;
        if ($uid <= 0) {
            return $this->unauthorizedResponse();
        }

        // Attach both keys to avoid downstream mismatch
        $request->attributes->set('fm_user_id', $uid);
        $request->attributes->set('user_id', $uid);

        // anon_id (optional)
        $anonId = trim((string) ($row->anon_id ?? ''));
        if ($anonId !== '' && strlen($anonId) <= 128) {
            $request->attributes->set('anon_id', $anonId);
            $request->attributes->set('fm_anon_id', $anonId);
        }

        return $next($request);
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