<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Jobs\Ops\TouchFmTokenLastUsedAtJob;
use App\Support\Logging\SensitiveDiagnosticRedactor;
use App\Support\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FmTokenAuth
{
    private const PUBLIC_ATTEMPT_ROUTE_NAMES = [
        'api.v0_3.attempts.start',
        'api.v0_3.attempts.submit',
        'api.v0_3.attempts.submission',
        'api.v0_3.attempts.show',
        'api.v0_3.attempts.result',
        'api.v0_3.attempts.report',
        'api.v0_3.attempts.report_access',
        'api.v0_3.attempts.report_pdf',
    ];

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

        $row = $this->findTokenRow($token, $tokenHash, $select);

        if (! $row) {
            return $this->unauthorizedResponse($request, 'token_not_found');
        }

        if (! empty($row->revoked_at)) {
            return $this->unauthorizedResponse($request, 'token_revoked');
        }

        if (! empty($row->expires_at)) {
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

            if (! $this->userExists($request, $resolvedUserId)) {
                return $this->unauthorizedResponse($request, 'token_user_not_found');
            }

            $request->attributes->set('user_id', (string) $resolvedUserId);
            if (! $this->assertInjectedUserIdentity($request, $resolvedUserId)) {
                return $this->unauthorizedResponse($request, 'token_user_inject_mismatch');
            }
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
        $request->attributes->set('org_context_resolved', true);
        $request->attributes->set('org_context_kind', OrgContext::deriveContextKind($orgId));

        if ($orgId <= 0 && $this->isOpsSystemBypass($request, $role)) {
            $request->attributes->set('org_context_bypass', true);
        }

        TouchFmTokenLastUsedAtJob::dispatch($tokenHash)->onQueue('ops');

        $ctx = app(OrgContext::class);
        $ctx->set(
            $orgId,
            $resolvedUserId,
            $role,
            $anonId,
            OrgContext::deriveContextKind($orgId)
        );
        app()->instance(OrgContext::class, $ctx);

        $this->logAuthResult($request, true);

        return $next($request);
    }

    /**
     * @param  array<int,string>  $select
     */
    private function findTokenRow(string $token, string $tokenHash, array $select): ?object
    {
        try {
            $authRow = DB::table('auth_tokens')
                ->select($select)
                ->where('token_hash', $tokenHash)
                ->first();
            if ($authRow) {
                return $authRow;
            }
        } catch (\Throwable $e) {
            Log::warning('[SEC] auth_tokens_lookup_failed', [
                'path' => 'middleware.fm_token_auth',
                'exception' => $e::class,
            ]);
        }

        if (! $this->shouldAllowLegacyTestingTokenFallback()) {
            return null;
        }

        try {
            $legacyRow = DB::table('fm_tokens')
                ->select($select)
                ->where('token', $token)
                ->where('token_hash', $tokenHash)
                ->first();
            if ($legacyRow) {
                return $legacyRow;
            }
        } catch (\Throwable $e) {
            Log::warning('[SEC] fm_tokens_legacy_lookup_failed', [
                'path' => 'middleware.fm_token_auth',
                'exception' => $e::class,
            ]);
        }

        return null;
    }

    private function shouldAllowLegacyTestingTokenFallback(): bool
    {
        return app()->environment(['testing', 'ci']);
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
        if ($this->shouldForcePublicAttemptRealm($request)) {
            return 0;
        }

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

    private function shouldForcePublicAttemptRealm(Request $request): bool
    {
        if ($request->attributes->get('force_public_attempt_realm') === true) {
            return true;
        }

        $route = $request->route();
        $routeName = is_object($route) ? trim((string) $route->getName()) : '';
        $flag = is_object($route) ? ($route->defaults['public_realm'] ?? null) : null;

        $isPublicAttemptRoute = in_array($routeName, self::PUBLIC_ATTEMPT_ROUTE_NAMES, true);
        $hasPublicRealmDefault = $flag === true || $flag === 1 || $flag === '1';

        return ($hasPublicRealmDefault || $isPublicAttemptRoute || $this->isPublicAttemptPath($request))
            && ! $this->hasExplicitTenantOrgSignal($request);
    }

    private function isPublicAttemptPath(Request $request): bool
    {
        return $request->is('api/v0.3/attempts/start')
            || $request->is('api/v0.3/attempts/submit')
            || $request->is('api/v0.3/attempts/*/submission')
            || $request->is('api/v0.3/attempts/*/result')
            || $request->is('api/v0.3/attempts/*/report')
            || $request->is('api/v0.3/attempts/*/report-access')
            || $request->is('api/v0.3/attempts/*/report.pdf')
            || preg_match('#^api/v0\.3/attempts/[0-9a-fA-F-]+$#', ltrim($request->path(), '/')) === 1;
    }

    private function hasExplicitTenantOrgSignal(Request $request): bool
    {
        $candidates = [
            $request->header('X-FM-Org-Id'),
            $request->header('X-Org-Id'),
            $request->query('org_id'),
            $request->route('org_id'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->resolveNumeric($candidate);
            if ($normalized !== null && $normalized > 0) {
                return true;
            }
        }

        return false;
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

    /**
     * Explicit post-injection guard to prevent accidental mutation/regression.
     */
    private function assertInjectedUserIdentity(Request $request, int $userId): bool
    {
        $fmUserId = trim((string) $request->attributes->get('fm_user_id', ''));
        $plainUserId = trim((string) $request->attributes->get('user_id', ''));

        if ($fmUserId === '' || $plainUserId === '') {
            Log::warning('[SEC] fm_token_user_inject_empty', [
                'path' => $request->path(),
                'fm_user_id' => $fmUserId !== '' ? $fmUserId : null,
                'user_id' => $plainUserId !== '' ? $plainUserId : null,
            ]);

            return false;
        }

        if ($fmUserId !== (string) $userId || $plainUserId !== (string) $userId) {
            Log::warning('[SEC] fm_token_user_inject_mismatch', [
                'path' => $request->path(),
                'expected_user_id' => (string) $userId,
                'fm_user_id' => $fmUserId,
                'user_id' => $plainUserId,
            ]);

            return false;
        }

        return true;
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
            'attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($this->extractAttemptId($request)),
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

    private function isOpsSystemBypass(Request $request, string $role): bool
    {
        if (! $request->is('ops*')) {
            return false;
        }

        $normalizedRole = strtolower(trim($role));

        return in_array($normalizedRole, ['system', 'ops', 'admin'], true);
    }
}
