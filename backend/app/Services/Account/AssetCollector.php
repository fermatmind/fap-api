<?php

namespace App\Services\Account;

use App\Support\OrgContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AssetCollector
{
    public function __construct(
        private readonly OrgContext $orgContext,
    ) {
    }

    /**
     * MVP：APPEND 归集（anon_id -> user_id）
     * 规则：
     * - 只更新 attempts.user_id IS NULL 且 attempts.anon_id = $anonId 的记录
     * - 不做账号合并，不做双向同步
     *
     * @return array {updated:int}
     */
    public function appendByAnonId(string $userId, string $anonId): array
    {
        $userId = trim($userId);
        $anonId = trim($anonId);

        if ($userId === '' || $anonId === '') {
            return ['updated' => 0];
        }

        if (!Schema::hasTable('attempts')) {
            return ['updated' => 0];
        }
        if (!Schema::hasColumn('attempts', 'user_id')) {
            // 你还没做 add_user_id_to_attempts_table 的话，这里不会炸
            return ['updated' => 0];
        }
        if (!Schema::hasColumn('attempts', 'anon_id')) {
            return ['updated' => 0];
        }
        if (!Schema::hasColumn('attempts', 'org_id')) {
            return [
                'ok' => true,
                'collected' => 0,
                'updated' => 0,
                'reason' => 'attempts_org_id_column_missing',
            ];
        }

        $q = DB::table('attempts')
            ->where('org_id', $this->orgContext->orgId())
            ->whereNull('user_id')
            ->where('anon_id', $anonId);

        $update = [
            'user_id' => $userId,
        ];

        if (Schema::hasColumn('attempts', 'updated_at')) {
            $update['updated_at'] = now();
        }

        $n = $q->update($update);

        return ['updated' => (int) $n];
    }

    /**
     * 可选：device_key_hash -> user_id（你后面有 device_key 再启用）
     * @return array {updated:int}
     */
    public function appendByDeviceKeyHash(string $userId, string $deviceKeyHash): array
    {
        $userId = trim($userId);
        $deviceKeyHash = trim($deviceKeyHash);

        if ($userId === '' || $deviceKeyHash === '') {
            return ['updated' => 0];
        }

        if (!Schema::hasTable('attempts')) return ['updated' => 0];
        if (!Schema::hasColumn('attempts', 'user_id')) return ['updated' => 0];
        if (!Schema::hasColumn('attempts', 'org_id')) {
            return [
                'ok' => true,
                'collected' => 0,
                'updated' => 0,
                'reason' => 'attempts_org_id_column_missing',
            ];
        }

        // 兼容字段名
        $col = null;
        foreach (['device_key_hash', 'device_hash', 'device_key'] as $c) {
            if (Schema::hasColumn('attempts', $c)) {
                $col = $c;
                break;
            }
        }
        if ($col === null) return ['updated' => 0];

        $q = DB::table('attempts')
            ->where('org_id', $this->orgContext->orgId())
            ->whereNull('user_id')
            ->where($col, $deviceKeyHash);

        $update = ['user_id' => $userId];
        if (Schema::hasColumn('attempts', 'updated_at')) {
            $update['updated_at'] = now();
        }

        $n = $q->update($update);

        return ['updated' => (int) $n];
    }
}
