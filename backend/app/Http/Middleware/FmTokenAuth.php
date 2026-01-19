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
     * Phase B:
     * - validate fm_token format
     * - resolve user_id from fm_tokens table
     * - attach fm_token / fm_user_id to request attributes
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
            return $this->unauthorizedResponse();
        }

        // ✅ Always attach token for downstream use
        $request->attributes->set('fm_token', $token);

        // ✅ Resolve identity from DB: fm_tokens.token -> user_id
        $row = DB::table('fm_tokens')
            ->where('token', $token)
            ->first();

        if (!$row) {
            return $this->unauthorizedResponse();
        }

        // Optional: expires check (目前你是 null，不会触发)
        if ($row && !empty($row->expires_at)) {
            $exp = strtotime((string)$row->expires_at);
            if ($exp !== false && $exp < time()) {
                return $this->unauthorizedResponse();
            }
        }

        $userId = $this->resolveUserId($row);
        if ($userId === '') {
            return $this->unauthorizedResponse();
        }

        // basic sanity
        if (strlen($userId) > 128) {
            return $this->unauthorizedResponse();
        }

        $request->attributes->set('fm_user_id', $userId);

        return $next($request);
    }

    private function resolveUserId(object $row): string
    {
        if (Schema::hasColumn('fm_tokens', 'user_id')) {
            return trim((string) ($row->user_id ?? ''));
        }

        if (Schema::hasColumn('fm_tokens', 'anon_id')) {
            return trim((string) ($row->anon_id ?? ''));
        }

        $candidates = ['uid', 'user_uid', 'user'];
        foreach ($candidates as $c) {
            if (property_exists($row, $c)) {
                $val = trim((string) ($row->{$c} ?? ''));
                if ($val !== '') return $val;
            }
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
