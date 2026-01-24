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

        // 2) anon_id: separate identity, used for anon-only flows (CI accept_phone)
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

    private function resolveAnonId(object $row): string
    {
        if (property_exists($row, 'anon_id')) {
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