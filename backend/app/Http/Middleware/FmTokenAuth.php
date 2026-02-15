<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Jobs\Ops\TouchFmTokenLastUsedAtJob;
use App\Support\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FmTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        $token = '';
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $m) === 1) {
            $token = trim((string) ($m[1] ?? ''));
        }

        if ($token === '' || preg_match('/^fm_[0-9a-fA-F-]{36}$/', $token) !== 1) {
            return $this->unauthorizedResponse($request, 'token_missing_or_invalid');
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
            return $this->unauthorizedResponse($request, 'token_not_found');
        }

        if (!empty($row->revoked_at)) {
            return $this->unauthorizedResponse($request, 'token_revoked');
        }

        if (!empty($row->expires_at)) {
            $exp = strtotime((string) $row->expires_at);
            if ($exp !== false && $exp < time()) {
                return $this->unauthorizedResponse($request, 'token_expired');
            }
        }

        $request->attributes->set('fm_user_id', null);
        $request->attributes->set('user_id', null);

        $rawUserId = $row->user_id ?? null;
        $resolvedUserId = $this->resolvePositiveNumeric($rawUserId);
        $rawUserIdString = trim((string) $rawUserId);
        if ($rawUserIdString !== '' && $resolvedUserId === null) {
            Log::warning('[SEC] fm_token_user_invalid', [
                'path' => $request->path(),
                'user_id_raw' => $rawUserIdString,
            ]);

            return $this->unauthorizedResponse($request, 'token_user_invalid');
        }

        if ($resolvedUserId !== null) {
            $request->attributes->set('fm_user_id', (string) $resolvedUserId);

            if (!$this->userExists($request, $resolvedUserId)) {
                return $this->unauthorizedResponse($request, 'token_user_not_found');
            }

            $request->attributes->set('user_id', (string) $resolvedUserId);
        }

        $anonId = $this->resolveAnonId($row->anon_id ?? null);
        if ($anonId !== null) {
            $request->attributes->set('anon_id', $anonId);
            $request->attributes->set('fm_anon_id', $anonId);
        }

        $orgId = $this->resolveOrgId($request, $row);
        $role = $this->resolveRole($row->role ?? null, $row->meta_json ?? null);
        $existingRole = trim((string) $request->attributes->get('org_role', ''));
        if ($orgId > 0 && $existingRole !== '') {
            $role = $existingRole;
        }

        $request->attributes->set('fm_org_id', $orgId);
        $request->attributes->set('org_id', $orgId);
        $request->attributes->set('org_role', $role);

        $dispatchHash = trim((string) ($row->token_hash ?? ''));
        if ($dispatchHash === '') {
            $dispatchHash = $tokenHash;
        }
        TouchFmTokenLastUsedAtJob::dispatch($dispatchHash)->onQueue('ops');

        $ctx = new OrgContext();
        $ctx->set(
            $orgId,
            $resolvedUserId,
            $role,
            $anonId
        );
        app()->instance(OrgContext::class, $ctx);

        $this->logAuthResult($request, true);

        return $next($request);
    }

    private function userExists(Request $request, int $userId): bool
    {
        try {
            return DB::table('users')->where('id', $userId)->exists();
        } catch (\Throwable $e) {
            $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
            if ($requestId === '') {
                $requestId = trim((string) $request->header('X-Request-Id', ''));
            }
            if ($requestId === '') {
                $requestId = trim((string) $request->header('X-Request-ID', ''));
            }

            Log::error('[SEC] fm_token_user_exists_failed', [
                'user_id' => $userId,
                'path' => $request->path(),
                'request_id' => $requestId !== '' ? $requestId : null,
                'exception' => $e,
            ]);

            return false;
        }
    }

    private function resolveRole(mixed $roleCandidate, mixed $metaCandidate): string
    {
        $role = trim((string) $roleCandidate);
        if ($role !== '') {
            return $role;
        }

        $meta = $this->decodeMeta($metaCandidate);
        $metaRole = trim((string) ($meta['role'] ?? ''));
        if ($metaRole !== '') {
            return $metaRole;
        }

        return 'public';
    }

    private function resolveOrgId(Request $request, object $row): int
    {
        $fromToken = $this->resolveNumeric($row->org_id ?? null);
        if ($fromToken !== null && $fromToken > 0) {
            return $fromToken;
        }

        $meta = $this->decodeMeta($row->meta_json ?? null);
        $metaOrgId = $this->resolveNumeric($meta['org_id'] ?? null);
        if ($metaOrgId !== null && $metaOrgId > 0) {
            return $metaOrgId;
        }

        $attrOrgId = $this->resolveNumeric($request->attributes->get('org_id'));
        if ($attrOrgId === null) {
            $attrOrgId = $this->resolveNumeric($request->attributes->get('fm_org_id'));
        }
        if ($attrOrgId !== null && $attrOrgId > 0) {
            return $attrOrgId;
        }

        $headerOrgId = trim((string) $request->header('X-FM-Org-Id', ''));
        if ($headerOrgId === '') {
            $headerOrgId = trim((string) $request->header('X-Org-Id', ''));
        }
        if ($headerOrgId === '') {
            $headerOrgId = trim((string) $request->query('org_id', ''));
        }

        $headerOrg = $this->resolveNumeric($headerOrgId);
        if ($headerOrg !== null && $headerOrg > 0) {
            Log::warning('[SEC] fm_token_org_override_blocked', [
                'token_org_id' => $fromToken ?? $metaOrgId ?? 0,
                'requested_org_id' => $headerOrg,
                'path' => $request->path(),
            ]);
        }

        return 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function resolveNumeric(mixed $candidate): ?int
    {
        $raw = trim((string) $candidate);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    private function resolvePositiveNumeric(mixed $candidate): ?int
    {
        $value = $this->resolveNumeric($candidate);
        if ($value === null || $value <= 0) {
            return null;
        }

        return $value;
    }

    private function resolveAnonId(mixed $candidate): ?string
    {
        $anonId = trim((string) $candidate);
        if ($anonId === '') {
            return null;
        }

        if (strlen($anonId) > 128) {
            return null;
        }

        $lower = mb_strtolower($anonId, 'UTF-8');
        foreach (['todo', 'placeholder', 'fixme', 'tbd', '填这里'] as $bad) {
            if (mb_strpos($lower, $bad) !== false) {
                return null;
            }
        }

        return $anonId;
    }

    private function unauthorizedResponse(Request $request, string $reason): Response
    {
        $this->logAuthResult($request, false, $reason);

        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId === '') {
            $requestId = trim((string) $request->header('X-Request-Id', ''));
        }
        if ($requestId === '') {
            $requestId = trim((string) $request->header('X-Request-ID', ''));
        }
        if ($requestId === '') {
            $requestId = trim((string) $request->input('request_id', ''));
        }
        if ($requestId === '') {
            $requestId = (string) Str::uuid();
        }

        return response()->json([
            'ok' => false,
            'error_code' => 'UNAUTHENTICATED',
            'message' => 'Missing or invalid fm_token. Please login.',
            'details' => (object) [],
            'request_id' => $requestId,
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
            $value = trim((string) $routeAttemptId);
            if ($value !== '') {
                return $value;
            }
        }

        $routeId = $request->route('id');
        if (is_string($routeId) || is_numeric($routeId)) {
            $value = trim((string) $routeId);
            if ($value !== '') {
                return $value;
            }
        }

        $bodyAttemptId = $request->input('attempt_id');
        if (is_string($bodyAttemptId) || is_numeric($bodyAttemptId)) {
            $value = trim((string) $bodyAttemptId);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
