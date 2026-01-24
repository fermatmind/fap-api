<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FmTokenService
{
    /**
     * 统一签发 fm_token（给 wx_phone / phone_verify 共用）
     *
     * @param string $userId 你的主账号 id（建议用 users.uid；也兼容 users.id）
     * @param array  $meta   可选：写入 meta_json 方便审计
     *
     * @return array {token: string, expires_at: string|null, user_id: string}
     */
    public function issueForUser(string $userId, array $meta = []): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('userId is required');
        }

        $token = 'fm_' . (string) Str::uuid();

        $ttlDays = (int) config('fap.fm_token_ttl_days', 30);
        if ($ttlDays <= 0) $ttlDays = 30;

        $expiresAt = now()->addDays($ttlDays);

        $row = [
            'token' => $token,
        ];

        // ✅ 写入 user_id（长期稳定方案）
        // - fm_tokens.user_id 存在时：写 user_id
        // - 否则：兼容旧表字段（uid/user_uid/user）
        // - 不允许把 anon_id 当 user id
        if (Schema::hasColumn('fm_tokens', 'user_id')) {
            $row['user_id'] = $userId;
        } else {
            $userCol = $this->detectUserColumn();
            if ($userCol !== null) {
                $row[$userCol] = $userId;
            }
        }

        if (!array_key_exists('anon_id', $row) && Schema::hasColumn('fm_tokens', 'anon_id')) {
            $anonId = null;
            if (isset($meta['anon_id']) && is_string($meta['anon_id'])) {
                $anonId = trim($meta['anon_id']);
            }
            $row['anon_id'] = $anonId !== null && $anonId !== '' ? $anonId : $userId;
        }

        if (Schema::hasColumn('fm_tokens', 'expires_at')) {
            $row['expires_at'] = $expiresAt;
        }

        if (Schema::hasColumn('fm_tokens', 'meta_json')) {
            $row['meta_json'] = $meta;
        }

        if (Schema::hasColumn('fm_tokens', 'created_at')) $row['created_at'] = now();
        if (Schema::hasColumn('fm_tokens', 'updated_at')) $row['updated_at'] = now();

        // 有些表会有 id 主键
        if (Schema::hasColumn('fm_tokens', 'id')) {
            $row['id'] = (string) Str::uuid();
        }

        DB::table('fm_tokens')->insert($row);

        return [
            'token' => $token,
            'expires_at' => Schema::hasColumn('fm_tokens', 'expires_at') ? $expiresAt->toIso8601String() : null,
            'user_id' => $userId,
        ];
    }

    /**
     * 校验 token（给 FmTokenAuth 中间件用）
     *
     * @return array {ok: bool, user_id?: string, expires_at?: string}
     */
    public function validateToken(string $token): array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^fm_[0-9a-fA-F-]{36}$/', $token)) {
            return ['ok' => false];
        }

        $q = DB::table('fm_tokens')->where('token', $token);

        $row = $q->first();
        if (!$row) {
            return ['ok' => false];
        }

        $expiresAt = null;
        if (property_exists($row, 'expires_at') && $row->expires_at) {
            $expiresAt = (string) $row->expires_at;
            try {
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($row->expires_at))) {
                    return ['ok' => false];
                }
            } catch (\Throwable $e) {
                // expires_at 解析失败就当无效
                return ['ok' => false];
            }
        }

        $userCol = $this->detectUserColumn();
        $uid = $userCol ? (string) ($row->{$userCol} ?? '') : '';
        if ($uid === '') {
            // 没有 user 字段也不算通过（否则 /me 无法知道是谁）
            return ['ok' => false];
        }

        return [
            'ok' => true,
            'user_id' => $uid,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * 自动识别 fm_tokens 里哪一列存 user id
     */
    private function detectUserColumn(): ?string
    {
        $candidates = ['user_id', 'uid', 'user_uid', 'user'];
        foreach ($candidates as $c) {
            if (Schema::hasColumn('fm_tokens', $c)) return $c;
        }
        return null;
    }
}
