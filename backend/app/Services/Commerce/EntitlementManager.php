<?php

namespace App\Services\Commerce;

use App\Services\Report\ReportAccess;
use Illuminate\Support\Facades\DB;
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
        $benefitCode = strtoupper(trim($benefitCode));
        $attemptId = trim($attemptId);

        if ($benefitCode === '' || $attemptId === '') {
            return false;
        }

        $userId = $userId !== null ? trim($userId) : '';
        $anonId = $anonId !== null ? trim($anonId) : '';

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
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        if ($userId !== '') {
            $query->where(function ($q) use ($userId, $anonId) {
                $q->where('user_id', $userId);

                if ($anonId !== '') {
                    $q->orWhere('benefit_ref', $anonId);
                }
            });
        } else {
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
        ?string $orderNo,
        ?string $scopeOverride = null,
        ?string $expiresAt = null,
        ?array $modules = null
    ): array {
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

        $userIdToStore = $userId !== '' ? $userId : ($anonId !== '' ? $anonId : ('attempt:'.$attemptId));
        $benefitRef = $anonId !== '' ? $anonId : ($userId !== '' ? $userId : ('attempt:'.$attemptId));

        $existing = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->where('scope', $scope)
            ->where('attempt_id', $attemptId)
            ->first();

        $grantedModules = ReportAccess::normalizeModules($modules ?? $this->modulesFromBenefitCode($benefitCode));
        if ($grantedModules !== [] && !in_array(ReportAccess::MODULE_CORE_FREE, $grantedModules, true)) {
            $grantedModules[] = ReportAccess::MODULE_CORE_FREE;
            $grantedModules = ReportAccess::normalizeModules($grantedModules);
        }

        if ($existing) {
            if ($grantedModules !== []) {
                $meta = $this->decodeMeta($existing->meta_json ?? null);
                $mergedModules = ReportAccess::normalizeModules(array_merge(
                    is_array($meta['modules'] ?? null) ? $meta['modules'] : [],
                    $grantedModules
                ));
                $meta['modules'] = $mergedModules;

                DB::table('benefit_grants')
                    ->where('id', (string) ($existing->id ?? ''))
                    ->update([
                        'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);

                $existing = DB::table('benefit_grants')->where('id', (string) ($existing->id ?? ''))->first() ?: $existing;
            }

            return [
                'ok' => true,
                'grant' => $existing,
                'idempotent' => true,
            ];
        }

        $now = now();

        $normalizedOrderNo = trim((string) ($orderNo ?? ''));

        $sourceOrderId = null;
        if ($normalizedOrderNo !== '' && preg_match('/^[0-9a-f\-]{36}$/i', $normalizedOrderNo)) {
            $sourceOrderId = $normalizedOrderNo;
        }

        if ($sourceOrderId === null) {
            $sourceOrderId = (string) Str::uuid();
        }

        $row = [
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'user_id' => $userIdToStore,
            'benefit_code' => $benefitCode,
            'scope' => $scope,
            'attempt_id' => $attemptId,
            'order_no' => $normalizedOrderNo !== '' ? $normalizedOrderNo : null,
            'status' => 'active',
            'expires_at' => null,
            'benefit_ref' => $benefitRef,
            'benefit_type' => 'report_unlock',
            'source_order_id' => $sourceOrderId,
            'source_event_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($grantedModules !== []) {
            $row['meta_json'] = json_encode([
                'modules' => $grantedModules,
                'granted_via' => 'entitlement_manager',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($expiresAt !== null) {
            $expiresAt = trim((string) $expiresAt);
            if ($expiresAt !== '') {
                $row['expires_at'] = $expiresAt;
            }
        }

        DB::table('benefit_grants')->insert($row);

        $grant = DB::table('benefit_grants')->where('id', $row['id'])->first();

        return [
            'ok' => true,
            'grant' => $grant,
            'idempotent' => false,
        ];
    }

    /**
     * @return list<string>
     */
    public function getAllowedModulesForAttempt(int $orgId, string $attemptId): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return ReportAccess::defaultModulesAllowedForLocked();
        }

        $rows = DB::table('benefit_grants')
            ->select(['benefit_code', 'meta_json', 'scope', 'attempt_id'])
            ->where('org_id', $orgId)
            ->where('status', 'active')
            ->where(function ($q) use ($attemptId) {
                $q->where('attempt_id', $attemptId)
                    ->orWhere('scope', 'org');
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();

        $modules = ReportAccess::defaultModulesAllowedForLocked();
        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row->meta_json ?? null);
            $modules = array_merge(
                $modules,
                ReportAccess::normalizeModules(is_array($meta['modules'] ?? null) ? $meta['modules'] : [])
            );
            $modules = array_merge(
                $modules,
                $this->modulesFromBenefitCode((string) ($row->benefit_code ?? ''))
            );
        }

        return ReportAccess::normalizeModules($modules);
    }

    public function revokeByOrderNo(int $orgId, string $orderNo): array
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no is required.');
        }

        $order = DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->first();
        if (! $order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $orderOrgId = (int) ($order->org_id ?? $orgId);
        $sku = strtoupper((string) ($order->effective_sku ?? $order->sku ?? $order->item_sku ?? ''));
        if ($sku === '') {
            return [
                'ok' => true,
                'revoked' => 0,
            ];
        }

        $skuRow = DB::table('skus')->where('sku', $sku)->first();
        $benefitCode = $skuRow ? strtoupper((string) ($skuRow->benefit_code ?? '')) : '';

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
        $byOrderNo = DB::table('benefit_grants')
            ->where('org_id', $orderOrgId)
            ->where('order_no', $orderNo)
            ->where('status', 'active')
            ->update([
                'status' => 'revoked',
                'updated_at' => $now,
                'revoked_at' => $now,
            ]);

        if ($byOrderNo > 0) {
            return [
                'ok' => true,
                'revoked' => $byOrderNo,
                'benefit_code' => $benefitCode,
                'attempt_id' => $attemptId,
            ];
        }

        $revoked = DB::table('benefit_grants')
            ->where('org_id', $orderOrgId)
            ->where('benefit_code', $benefitCode)
            ->where('attempt_id', $attemptId)
            ->where('status', 'active')
            ->update([
                'status' => 'revoked',
                'updated_at' => $now,
                'revoked_at' => $now,
            ]);

        return [
            'ok' => true,
            'revoked' => $revoked,
            'benefit_code' => $benefitCode,
            'attempt_id' => $attemptId,
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

    /**
     * @return list<string>
     */
    private function modulesFromBenefitCode(string $benefitCode): array
    {
        $benefitCode = strtoupper(trim($benefitCode));
        if ($benefitCode === '') {
            return [];
        }

        return match ($benefitCode) {
            'MBTI_REPORT_FULL' => [
                ReportAccess::MODULE_CORE_FULL,
                ReportAccess::MODULE_CAREER,
                ReportAccess::MODULE_RELATIONSHIPS,
            ],
            'MBTI_CAREER' => [ReportAccess::MODULE_CAREER],
            'MBTI_RELATIONSHIP', 'MBTI_RELATIONSHIPS' => [ReportAccess::MODULE_RELATIONSHIPS],
            default => [],
        };
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
}
