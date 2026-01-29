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
        if ($benefitCode === '' || trim($attemptId) === '') {
            return false;
        }

        $query = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->where('status', 'active');

        if ($attemptId !== '') {
            $query->where(function ($q) use ($attemptId) {
                $q->where('attempt_id', $attemptId)
                    ->orWhere('scope', 'org');
            });
        }

        $userId = $userId !== null ? trim($userId) : '';
        $anonId = $anonId !== null ? trim($anonId) : '';

        if ($userId !== '') {
            $query->where(function ($q) use ($userId, $anonId) {
                $q->where('user_id', $userId);
                if ($anonId !== '') {
                    $q->orWhere('benefit_ref', $anonId);
                }
            });
        } elseif ($anonId !== '') {
            $query->where('benefit_ref', $anonId);
        }

        return $query->exists();
    }

    public function grantAttemptUnlock(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $benefitCode,
        string $attemptId,
        ?string $orderNo
    ): array {
        if (!Schema::hasTable('benefit_grants')) {
            return $this->tableMissing('benefit_grants');
        }

        $benefitCode = strtoupper(trim($benefitCode));
        $attemptId = trim($attemptId);
        if ($benefitCode === '' || $attemptId === '') {
            return $this->badRequest('BENEFIT_REQUIRED', 'benefit_code and attempt_id are required.');
        }

        $scope = 'attempt';
        $userId = $userId !== null ? trim($userId) : '';
        $anonId = $anonId !== null ? trim($anonId) : '';
        $benefitRef = $userId !== '' ? $userId : ($anonId !== '' ? $anonId : null);

        $existing = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->where('scope', $scope)
            ->where('attempt_id', $attemptId)
            ->when($userId !== '', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
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
            'user_id' => $userId !== '' ? $userId : null,
            'benefit_code' => $benefitCode,
            'scope' => $scope,
            'attempt_id' => $attemptId,
            'status' => 'active',
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('benefit_grants', 'benefit_ref')) {
            $row['benefit_ref'] = $benefitRef;
        }
        if (Schema::hasColumn('benefit_grants', 'benefit_type')) {
            $row['benefit_type'] = 'report_unlock';
        }
        if (Schema::hasColumn('benefit_grants', 'source_order_id')) {
            $sourceOrderId = null;
            if ($orderNo) {
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

        DB::table('benefit_grants')->insert($row);

        $grant = DB::table('benefit_grants')->where('id', $row['id'])->first();

        return [
            'ok' => true,
            'grant' => $grant,
            'idempotent' => false,
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
}
