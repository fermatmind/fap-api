<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EntitlementManager
{
    public function hasFullAccess(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $attemptId,
        string $benefitCode
    ): bool {
        if (!Schema::hasTable('benefit_grants')) {
            return false;
        }

        $benefitCode = strtoupper(trim($benefitCode));
        $attemptId = trim($attemptId);

        if ($benefitCode === '' || $attemptId === '') {
            return false;
        }

        $userId = $userId !== null ? trim($userId) : '';
        $anonId = $anonId !== null ? trim($anonId) : '';

        // 安全：没有任何 viewer 身份时，不允许命中 grant（避免误放行）
        if ($userId === '' && $anonId === '') {
            return false;
        }

        $query = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->where('status', 'active')
            ->where(function ($q) use ($attemptId) {
                $q->where('attempt_id', $attemptId)
                  ->orWhere('scope', 'org');
            });

        if (Schema::hasColumn('benefit_grants', 'expires_at')) {
            $query->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
        }

        if ($userId !== '') {
            $query->where(function ($q) use ($userId, $anonId) {
                $q->where('user_id', $userId);

                // 允许同一个用户携带 anon_id 的情况下，通过 benefit_ref 命中
                if ($anonId !== '' && Schema::hasColumn('benefit_grants', 'benefit_ref')) {
                    $q->orWhere('benefit_ref', $anonId);
                }
            });
        } else {
            // 纯匿名：只允许用 anonId 命中 benefit_ref（不会靠 user_id 兜底）
            if (Schema::hasColumn('benefit_grants', 'benefit_ref')) {
                $query->where('benefit_ref', $anonId);
            } else {
                return false;
            }
        }

        return $query->exists();
    }

    public function grantAttemptUnlock(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $benefitCode,
        string $attemptId,
        ?string $orderNo,
        ?string $scopeOverride = null,
        ?string $expiresAt = null
    ): array {
        if (!Schema::hasTable('benefit_grants')) {
            return $this->tableMissing('benefit_grants');
        }

        $benefitCode = strtoupper(trim($benefitCode));
        $attemptId = trim($attemptId);

        if ($benefitCode === '' || $attemptId === '') {
            return $this->badRequest('BENEFIT_REQUIRED', 'benefit_code and attempt_id are required.');
        }

        $scope = trim((string) ($scopeOverride ?? ''));
        if ($scope === '') {
            $scope = 'attempt';
        }

        $userId = $userId !== null ? trim($userId) : '';
        $anonId = $anonId !== null ? trim($anonId) : '';

        // 兼容旧表( user_id / benefit_ref 可能 NOT NULL )：永远写入非空值
        $userIdToStore = $userId !== '' ? $userId : ($anonId !== '' ? $anonId : ('attempt:' . $attemptId));

        // benefit_ref 必须稳定且非空：优先 anonId，其次 userId，最后用 attemptId 派生
        $benefitRef = $anonId !== '' ? $anonId : ($userId !== '' ? $userId : ('attempt:' . $attemptId));

        // 幂等：同 org + benefit + scope + attempt_id 的 grant 只能有一份
        $existing = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->where('scope', $scope)
            ->where('attempt_id', $attemptId)
            ->first();

        if ($existing) {
            return [
                'ok' => true,
                'grant' => $existing,
                'idempotent' => true,
            ];
        }

        $now = now();

        $row = [
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => $userIdToStore,
            'benefit_code' => $benefitCode,
            'scope' => $scope,
            'attempt_id' => $attemptId,
            'status' => 'active',
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($expiresAt !== null && Schema::hasColumn('benefit_grants', 'expires_at')) {
            $expiresAt = trim((string) $expiresAt);
            if ($expiresAt !== '') {
                $row['expires_at'] = $expiresAt;
            }
        }

        if (Schema::hasColumn('benefit_grants', 'benefit_ref')) {
            $row['benefit_ref'] = $benefitRef;
        }

        if (Schema::hasColumn('benefit_grants', 'benefit_type')) {
            $row['benefit_type'] = 'report_unlock';
        }

        if (Schema::hasColumn('benefit_grants', 'source_order_id')) {
            // 兼容：order_no 可能不是 UUID（如 ord_xxx），则用 attempt_id(UUID) 作为稳定 source_order_id
            $sourceOrderId = null;

            if ($orderNo !== null) {
                $orderNo = trim((string) $orderNo);
                if ($orderNo !== '' && preg_match('/^[0-9a-f\\-]{36}$/i', $orderNo)) {
                    $sourceOrderId = $orderNo;
                }
            }

            if ($sourceOrderId === null && preg_match('/^[0-9a-f\\-]{36}$/i', $attemptId)) {
                $sourceOrderId = $attemptId;
            }

            if ($sourceOrderId === null) {
                $sourceOrderId = (string) Str::uuid();
            }

            $row['source_order_id'] = $sourceOrderId;
        }

        if (Schema::hasColumn('benefit_grants', 'source_event_id')) {
            $row['source_event_id'] = null;
        }

        DB::table('benefit_grants')->insert($row);

        $grant = DB::table('benefit_grants')->where('id', $row['id'])->first();

        return [
            'ok' => true,
            'grant' => $grant,
            'idempotent' => false,
        ];
    }

    public function revokeByOrderNo(int $orgId, string $orderNo): array
    {
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }
        if (!Schema::hasTable('benefit_grants')) {
            return $this->tableMissing('benefit_grants');
        }

        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no is required.');
        }

        $query = DB::table('orders')->where('order_no', $orderNo);
        if (Schema::hasColumn('orders', 'org_id')) {
            $query->where('org_id', $orgId);
        }
        $order = $query->first();
        if (!$order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $orderOrgId = Schema::hasColumn('orders', 'org_id') ? (int) ($order->org_id ?? $orgId) : $orgId;
        $sku = strtoupper((string) ($order->effective_sku ?? $order->sku ?? $order->item_sku ?? ''));
        if ($sku === '') {
            return [
                'ok' => true,
                'revoked' => 0,
            ];
        }

        $benefitCode = '';
        if (Schema::hasTable('skus')) {
            $skuRow = DB::table('skus')->where('sku', $sku)->first();
            if ($skuRow) {
                $benefitCode = strtoupper((string) ($skuRow->benefit_code ?? ''));
            }
        }

        if ($benefitCode === '') {
            return [
                'ok' => true,
                'revoked' => 0,
            ];
        }

        $attemptId = trim((string) ($order->target_attempt_id ?? ''));
        if ($attemptId === '') {
            return [
                'ok' => true,
                'revoked' => 0,
            ];
        }

        $now = now();
        $updates = [
            'status' => 'revoked',
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('benefit_grants', 'revoked_at')) {
            $updates['revoked_at'] = $now;
        }

        $revoked = DB::table('benefit_grants')
            ->where('org_id', $orderOrgId)
            ->where('benefit_code', $benefitCode)
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->update($updates);

        return [
            'ok' => true,
            'revoked' => $revoked,
            'benefit_code' => $benefitCode,
            'attempt_id' => $attemptId,
        ];
    }

    private function tableMissing(string $table): array
    {
        return [
            'ok' => false,
            'error' => 'TABLE_MISSING',
            'message' => "{$table} table missing.",
        ];
    }

    private function badRequest(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function notFound(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }
}
