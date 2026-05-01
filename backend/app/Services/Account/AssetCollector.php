<?php

namespace App\Services\Account;

use App\Models\Attempt;
use App\Services\Attempts\AttemptProgressService;
use App\Support\OrgContext;
use Illuminate\Support\Facades\DB;

class AssetCollector
{
    public function __construct(
        private readonly OrgContext $orgContext,
        private readonly AttemptProgressService $progressService,
    ) {}

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

        $q = DB::table('attempts')
            ->where('org_id', $this->orgContext->orgId())
            ->whereNull('user_id')
            ->where('anon_id', $anonId);

        $n = $q->update([
            'user_id' => $userId,
            'updated_at' => now(),
        ]);

        return ['updated' => (int) $n];
    }

    /**
     * Claim only attempts for which the caller proves possession of that
     * attempt's current resume token. A raw anon_id is not enough.
     *
     * @return array {updated:int}
     */
    public function appendByAnonIdWithResumeToken(string $userId, string $anonId, string $resumeToken): array
    {
        $userId = trim($userId);
        $anonId = trim($anonId);
        $resumeToken = trim($resumeToken);

        if ($userId === '' || $anonId === '' || $resumeToken === '') {
            return ['updated' => 0];
        }

        $attempts = Attempt::withoutGlobalScopes()
            ->where('org_id', $this->orgContext->orgId())
            ->whereNull('user_id')
            ->where('anon_id', $anonId)
            ->orderByDesc('started_at')
            ->limit(16)
            ->get(['id']);

        $claimableIds = [];
        foreach ($attempts as $attempt) {
            $attemptId = trim((string) ($attempt->id ?? ''));
            if ($attemptId === '') {
                continue;
            }
            if ($this->progressService->hasValidResumeToken($attemptId, $resumeToken)) {
                $claimableIds[] = $attemptId;
            }
        }

        if ($claimableIds === []) {
            return ['updated' => 0];
        }

        $n = DB::table('attempts')
            ->where('org_id', $this->orgContext->orgId())
            ->whereNull('user_id')
            ->whereIn('id', $claimableIds)
            ->update([
                'user_id' => $userId,
                'updated_at' => now(),
            ]);

        return ['updated' => (int) $n];
    }

    /**
     * 可选：device_key_hash -> user_id（你后面有 device_key 再启用）
     *
     * @return array {updated:int}
     */
    public function appendByDeviceKeyHash(string $userId, string $deviceKeyHash): array
    {
        $userId = trim($userId);
        $deviceKeyHash = trim($deviceKeyHash);

        if ($userId === '' || $deviceKeyHash === '') {
            return ['updated' => 0];
        }

        $q = DB::table('attempts')
            ->where('org_id', $this->orgContext->orgId())
            ->whereNull('user_id')
            ->where('device_key_hash', $deviceKeyHash);

        $n = $q->update([
            'user_id' => $userId,
            'updated_at' => now(),
        ]);

        return ['updated' => (int) $n];
    }
}
