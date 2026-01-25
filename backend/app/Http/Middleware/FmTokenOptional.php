<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class FmTokenOptional
{
    /**
     * Optional token resolver:
     * - Try parse Authorization: Bearer fm_xxx
     * - When valid: attach fm_token / fm_user_id / user_id / anon_id / fm_anon_id
     * - Never blocks the request (always pass through)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        $token = '';
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $m)) {
            $token = trim((string) ($m[1] ?? ''));
        }

        // No token -> pass through
        if ($token === '') {
            return $next($request);
        }

        // Minimal format check: "fm_" + UUID
        $isOk = preg_match(
            '/^fm_[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $token
        ) === 1;

        if (!$isOk) {
            return $next($request);
        }

        $request->attributes->set('fm_token', $token);

        $row = DB::table('fm_tokens')->where('token', $token)->first();
        if (!$row) {
            return $next($request);
        }

        // expires_at check (only when present)
        if (property_exists($row, 'expires_at') && !empty($row->expires_at)) {
            $exp = strtotime((string) $row->expires_at);
            if ($exp !== false && $exp < time()) {
                return $next($request);
            }
        }

        // user_id (numeric)
        $userId = '';
        if (property_exists($row, 'user_id')) {
            $userId = trim((string) ($row->user_id ?? ''));
        }
        if ($userId !== '' && preg_match('/^\d+$/', $userId) === 1) {
            $request->attributes->set('fm_user_id', $userId);
            $request->attributes->set('user_id', $userId);
        }

        // anon_id (string)
        $anonId = '';
        if (property_exists($row, 'anon_id')) {
            $anonId = trim((string) ($row->anon_id ?? ''));
        }
        if ($anonId !== '') {
            $request->attributes->set('anon_id', $anonId);
            $request->attributes->set('fm_anon_id', $anonId);
        }

        return $next($request);
    }
}