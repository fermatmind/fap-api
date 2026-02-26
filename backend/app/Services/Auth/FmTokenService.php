<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Jobs\Ops\TouchFmTokenLastUsedAtJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FmTokenService
{
    /**
     * @return array{token:string,expires_at:?string,user_id:string,org_id:int,role:string}
     */
    public function issueForUser(string $userId, array $meta = []): array
    {
        $rawUserId = trim($userId);
        if ($rawUserId === '') {
            throw new \InvalidArgumentException('userId is required');
        }
        $persistedUserId = preg_match('/^\d+$/', $rawUserId) === 1 ? $rawUserId : null;

        $token = 'fm_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);
        $tokenStorageKey = $this->tokenStorageKey($tokenHash);

        $ttlDays = (int) config('fap.fm_token_ttl_days', 30);
        if ($ttlDays <= 0) {
            $ttlDays = 30;
        }

        $expiresAt = now()->addDays($ttlDays);

        $orgId = 0;
        if (isset($meta['org_id']) && is_numeric($meta['org_id'])) {
            $orgId = max(0, (int) $meta['org_id']);
        }

        $role = 'public';
        if (isset($meta['role']) && is_string($meta['role'])) {
            $candidate = trim($meta['role']);
            if ($candidate !== '') {
                $role = $candidate;
            }
        }

        $anonId = $rawUserId;
        if (isset($meta['anon_id']) && is_string($meta['anon_id'])) {
            $candidateAnonId = trim($meta['anon_id']);
            if ($candidateAnonId !== '') {
                $anonId = $candidateAnonId;
            }
        }

        $metaJson = null;
        if ($meta !== []) {
            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $metaJson = is_string($encoded) ? $encoded : '{}';
        }

        $now = now();

        DB::table('fm_tokens')->insert([
            'token' => $tokenStorageKey,
            'token_hash' => $tokenHash,
            'user_id' => $persistedUserId,
            'anon_id' => $anonId,
            'org_id' => $orgId,
            'role' => $role,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'meta_json' => $metaJson,
            'last_used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::table('auth_tokens')->upsert([
                [
                    'token_hash' => $tokenHash,
                    'user_id' => $persistedUserId,
                    'anon_id' => $anonId,
                    'org_id' => $orgId,
                    'role' => $role,
                    'meta_json' => $metaJson,
                    'expires_at' => $expiresAt,
                    'revoked_at' => null,
                    'last_used_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ], ['token_hash'], [
                'user_id',
                'anon_id',
                'org_id',
                'role',
                'meta_json',
                'expires_at',
                'revoked_at',
                'last_used_at',
                'updated_at',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEC] auth_tokens_dual_write_failed', [
                'source' => 'fm_token_service.issue_for_user',
                'exception' => $e::class,
            ]);
        }

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'user_id' => $rawUserId,
            'org_id' => $orgId,
            'role' => $role,
        ];
    }

    /**
     * @return array{ok:bool,user_id:?string,expires_at:?string,org_id:int,role:string,anon_id:?string}
     */
    public function validateToken(string $token): array
    {
        $token = trim($token);
        if ($token === '' || preg_match('/^fm_[0-9a-fA-F-]{36}$/', $token) !== 1) {
            return ['ok' => false];
        }

        $tokenHash = hash('sha256', $token);
        $row = $this->findTokenRow($tokenHash);

        if (! $row) {
            return ['ok' => false];
        }

        if (! empty($row->revoked_at)) {
            return ['ok' => false];
        }

        $expiresAt = null;
        if (! empty($row->expires_at)) {
            $expiresAt = (string) $row->expires_at;
            try {
                if (now()->greaterThan(Carbon::parse($row->expires_at))) {
                    return ['ok' => false];
                }
            } catch (\Throwable) {
                return ['ok' => false];
            }
        }

        $userId = null;
        $rawUserId = trim((string) ($row->user_id ?? ''));
        if ($rawUserId !== '' && preg_match('/^\d+$/', $rawUserId) === 1) {
            $userId = $rawUserId;
        }

        $orgId = 0;
        $rawOrgId = trim((string) ($row->org_id ?? '0'));
        if ($rawOrgId !== '' && preg_match('/^\d+$/', $rawOrgId) === 1) {
            $orgId = (int) $rawOrgId;
        }

        $role = trim((string) ($row->role ?? 'public'));
        if ($role === '') {
            $role = 'public';
        }

        $anonId = trim((string) ($row->anon_id ?? ''));
        if ($anonId === '') {
            $anonId = null;
        }

        TouchFmTokenLastUsedAtJob::dispatch($tokenHash)->onQueue('ops');

        return [
            'ok' => true,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
            'org_id' => $orgId,
            'role' => $role,
            'anon_id' => $anonId,
        ];
    }

    private function tokenStorageKey(string $tokenHash): string
    {
        return 'retired_'.strtolower(trim($tokenHash));
    }

    private function findTokenRow(string $tokenHash): ?object
    {
        $row = null;

        try {
            $row = DB::table('auth_tokens')
                ->where('token_hash', $tokenHash)
                ->first();
        } catch (\Throwable $e) {
            Log::warning('[SEC] auth_tokens_lookup_failed', [
                'source' => 'fm_token_service.validate_token',
                'exception' => $e::class,
            ]);
        }

        if ($row) {
            return $row;
        }

        try {
            $legacy = DB::table('fm_tokens')
                ->where('token_hash', $tokenHash)
                ->first();
            if ($legacy) {
                Log::info('[SEC] fm_token_legacy_hash_fallback_hit', [
                    'source' => 'fm_token_service.validate_token',
                ]);
            }

            return $legacy ?: null;
        } catch (\Throwable $e) {
            Log::warning('[SEC] fm_tokens_lookup_failed', [
                'source' => 'fm_token_service.validate_token',
                'exception' => $e::class,
            ]);
        }

        return null;
    }
}
