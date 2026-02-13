<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FmTokenService
{
    /**
     * @return array{token:string,expires_at:?string,user_id:string,org_id:int,role:string}
     */
    public function issueForUser(string $userId, array $meta = []): array
    {
        $userId = trim($userId);
        if ($userId === '' || preg_match('/^\d+$/', $userId) !== 1) {
            throw new \InvalidArgumentException('userId is required and must be numeric');
        }

        $token = 'fm_' . (string) Str::uuid();
        $tokenHash = hash('sha256', $token);

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

        $anonId = $userId;
        if (isset($meta['anon_id']) && is_string($meta['anon_id'])) {
            $candidateAnonId = trim($meta['anon_id']);
            if ($candidateAnonId !== '') {
                $anonId = $candidateAnonId;
            }
        }

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => $tokenHash,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'org_id' => $orgId,
            'role' => $role,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'meta_json' => $meta,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'user_id' => $userId,
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
        $row = DB::table('fm_tokens')
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$row) {
            return ['ok' => false];
        }

        if (!empty($row->revoked_at)) {
            return ['ok' => false];
        }

        $expiresAt = null;
        if (!empty($row->expires_at)) {
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

        DB::table('fm_tokens')->where('token_hash', $tokenHash)->update([
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'ok' => true,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
            'org_id' => $orgId,
            'role' => $role,
            'anon_id' => $anonId,
        ];
    }
}
