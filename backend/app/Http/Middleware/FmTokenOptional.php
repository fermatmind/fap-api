<?php

namespace App\Http\Middleware;

use App\Support\OrgContext;
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

        $row = DB::table('fm_tokens')
            ->select($select)
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$row) {
            $row = DB::table('fm_tokens')
                ->select($select)
                ->where('token', $token)
                ->first();

            if ($row) {
                $currentHash = trim((string) ($row->token_hash ?? ''));
                if ($currentHash === '') {
                    DB::table('fm_tokens')
                        ->where('token', $token)
                        ->update([
                            'token_hash' => $tokenHash,
                            'updated_at' => now(),
                        ]);
                    $row->token_hash = $tokenHash;
                }
            }
        }

        if (!$row) {
            return $next($request);
        }

        if (!empty($row->revoked_at)) {
            return $next($request);
        }

        // expires_at check
        if (!empty($row->expires_at)) {
            $exp = strtotime((string) $row->expires_at);
            if ($exp !== false && $exp < time()) {
                return $next($request);
            }
        }

        $resolvedUserId = $this->resolveNumeric($row->user_id ?? null);
        if ($resolvedUserId !== null) {
            $request->attributes->set('fm_user_id', (string) $resolvedUserId);
            $request->attributes->set('user_id', (string) $resolvedUserId);
        }

        $anonId = $this->resolveAnonId($row->anon_id ?? null);
        if ($anonId !== null) {
            $request->attributes->set('anon_id', $anonId);
            $request->attributes->set('fm_anon_id', $anonId);
        }

        $meta = $this->decodeMeta($row->meta_json ?? null);
        $orgId = $this->resolveNumeric($row->org_id ?? null)
            ?? $this->resolveNumeric($meta['org_id'] ?? null)
            ?? 0;
        $role = $this->resolveRole($row->role ?? null, $meta['role'] ?? null);

        $request->attributes->set('fm_org_id', $orgId);
        $request->attributes->set('org_id', $orgId);
        $request->attributes->set('org_role', $role);

        $ctx = new OrgContext();
        $ctx->set($orgId, $resolvedUserId, $role, $anonId);
        app()->instance(OrgContext::class, $ctx);

        return $next($request);
    }

    private function resolveNumeric(mixed $candidate): ?int
    {
        $raw = trim((string) $candidate);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    private function resolveAnonId(mixed $candidate): ?string
    {
        $anonId = trim((string) $candidate);
        if ($anonId === '' || strlen($anonId) > 128) {
            return null;
        }

        return $anonId;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && trim($meta) !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function resolveRole(mixed $roleCandidate, mixed $metaRoleCandidate): string
    {
        $role = trim((string) $roleCandidate);
        if ($role !== '') {
            return $role;
        }

        $metaRole = trim((string) $metaRoleCandidate);
        if ($metaRole !== '') {
            return $metaRole;
        }

        return 'public';
    }
}
