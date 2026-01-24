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
     * Bearer token gate + resolve identity.
     *
     * Usage:
     * - required: middleware(FmTokenAuth::class . ':required')
     * - optional: middleware(FmTokenAuth::class)  // default optional
     *
     * Expect:
     *   Authorization: Bearer fm_xxxxx
     *
     * Attach to request attributes:
     *   fm_token, fm_user_id, user_id, anon_id, fm_anon_id
     */
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $mode = strtolower(trim($mode));
        if ($mode !== 'required') {
            $mode = 'optional';
        }

        $header = trim((string) $request->header('Authorization', ''));

        // no auth header
        if ($header === '') {
            if ($mode === 'required') {
                return $this->unauthorizedResponse();
            }
            return $next($request);
        }

        // Accept: "Bearer <token>"
        $token = '';
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $m)) {
            $token = trim((string) ($m[1] ?? ''));
        }

        // Invalid header format
        if ($token === '') {
            return $this->unauthorizedResponse();
        }

        // Minimal format check: "fm_" + UUID
        $isOk = (bool) preg_match(
            '/^fm_[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $token
        );
        if (!$isOk) {
            return $this->unauthorizedResponse();
        }

        // attach token for downstream use
        $request->attributes->set('fm_token', $token);

        // resolve token row
        $row = DB::table('fm_tokens')->where('token', $token)->first();
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

        // user_id: only from fm_tokens.user_id
        $userId = $this->resolveUserId($row);
        if ($userId !== '') {
            // basic sanity
            if (strlen($userId) > 128) {
                return $this->unauthorizedResponse();
            }
            $request->attributes->set('fm_user_id', $userId);
            $request->attributes->set('user_id', $userId);
        } else {
            // required mode expects identity
            if ($mode === 'required') {
                return $this->unauthorizedResponse();
            }
        }

        // anon_id
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
        if (Schema::hasColumn('fm_tokens', 'user_id')) {
            $v = trim((string) ($row->user_id ?? ''));
            return $v;
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