<?php

namespace App\Services\Commerce;

use App\Models\UnifiedAccessProjection;
use App\Services\Report\ReportAccess;
use App\Services\Storage\UnifiedAccessProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EntitlementManager
{
    private const MBTI_FULL_BENEFIT_CODE = 'MBTI_REPORT_FULL';

    private const MBTI_PARTIAL_BENEFIT_CODE = 'MBTI_CAREER';

    public function __construct(
        private readonly UnifiedAccessProjectionWriter $accessProjections,
        private readonly OrderManager $orders,
    ) {}

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
        ?array $modules = null,
        ?array $metaPatch = null
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

        $catalog = $this->benefitModuleRuleCatalog();
        $grantedModules = ReportAccess::normalizeModules(
            $modules ?? $catalog->modulesForBenefitCode($orgId, $benefitCode)
        );
        $freeModule = $catalog->freeModuleForBenefitCode($orgId, $benefitCode);
        if ($grantedModules !== [] && $freeModule !== '' && ! in_array($freeModule, $grantedModules, true)) {
            $grantedModules[] = $freeModule;
            $grantedModules = ReportAccess::normalizeModules($grantedModules);
        }

        if ($existing) {
            $meta = $this->decodeMeta($existing->meta_json ?? null);
            $metaChanged = false;
            if ($grantedModules !== []) {
                $mergedModules = ReportAccess::normalizeModules(array_merge(
                    is_array($meta['modules'] ?? null) ? $meta['modules'] : [],
                    $grantedModules
                ));
                $meta['modules'] = $mergedModules;
                $metaChanged = true;
            }

            if (is_array($metaPatch) && $metaPatch !== []) {
                $meta = array_merge($meta, $metaPatch);
                $metaChanged = true;
            }

            if ($metaChanged) {

                DB::table('benefit_grants')
                    ->where('id', (string) ($existing->id ?? ''))
                    ->update([
                        'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);

                $existing = DB::table('benefit_grants')->where('id', (string) ($existing->id ?? ''))->first() ?: $existing;
            }

            $normalizedExistingOrderNo = trim((string) ($orderNo ?? ''));
            if ($normalizedExistingOrderNo !== '') {
                $this->orders->syncGrantState($normalizedExistingOrderNo, $orgId, 'granted');
            }

            $this->refreshAccessProjection($orgId, $attemptId, [
                'source_system' => 'entitlement_manager',
                'source_ref' => $normalizedExistingOrderNo !== '' ? $normalizedExistingOrderNo : $benefitCode,
                'actor_type' => $userId !== '' ? 'user' : 'anon',
                'actor_id' => $userId !== '' ? $userId : $anonId,
                'reason_code' => 'entitlement_granted',
                'org_id' => $orgId,
            ]);

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

        $rowMeta = [];
        if ($grantedModules !== []) {
            $rowMeta['modules'] = $grantedModules;
        }

        if (is_array($metaPatch) && $metaPatch !== []) {
            $rowMeta = array_merge($rowMeta, $metaPatch);
        }

        if (! array_key_exists('granted_via', $rowMeta)) {
            $rowMeta['granted_via'] = 'entitlement_manager';
        }

        if ($rowMeta !== []) {
            $row['meta_json'] = json_encode($rowMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($expiresAt !== null) {
            $expiresAt = trim((string) $expiresAt);
            if ($expiresAt !== '') {
                $row['expires_at'] = $expiresAt;
            }
        }

        DB::table('benefit_grants')->insert($row);

        $grant = DB::table('benefit_grants')->where('id', $row['id'])->first();
        if ($normalizedOrderNo !== '') {
            $this->orders->syncGrantState($normalizedOrderNo, $orgId, 'granted');
        }
        $this->refreshAccessProjection($orgId, $attemptId, [
            'source_system' => 'entitlement_manager',
            'source_ref' => $normalizedOrderNo !== '' ? $normalizedOrderNo : $benefitCode,
            'actor_type' => $userId !== '' ? 'user' : 'anon',
            'actor_id' => $userId !== '' ? $userId : $anonId,
            'reason_code' => 'entitlement_granted',
            'org_id' => $orgId,
        ]);

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

        $rows = $this->activeGrantRowsForAttempt($orgId, $attemptId);

        $modules = ReportAccess::defaultModulesAllowedForLocked();
        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row->meta_json ?? null);
            $modules = array_merge(
                $modules,
                ReportAccess::normalizeModules(is_array($meta['modules'] ?? null) ? $meta['modules'] : [])
            );
            $modules = array_merge(
                $modules,
                $this->benefitModuleRuleCatalog()->modulesForBenefitCode(
                    $orgId,
                    (string) ($row->benefit_code ?? '')
                )
            );
        }

        return ReportAccess::normalizeModules($modules);
    }

    public function hasActiveGrantForAttemptBenefitCode(int $orgId, string $attemptId, string $benefitCode): bool
    {
        $attemptId = trim($attemptId);
        $benefitCode = strtoupper(trim($benefitCode));
        if ($attemptId === '' || $benefitCode === '') {
            return false;
        }

        return DB::table('benefit_grants')
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
            })
            ->exists();
    }

    /**
     * @return array{unlock_stage:string,unlock_source:string,modules_allowed:list<string>,access_level:string,variant:string,has_active_grant:bool,has_full_grant:bool,has_partial_grant:bool}
     */
    public function resolveAttemptUnlockState(int $orgId, string $attemptId): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return [
                'unlock_stage' => ReportAccess::UNLOCK_STAGE_LOCKED,
                'unlock_source' => ReportAccess::UNLOCK_SOURCE_NONE,
                'modules_allowed' => ReportAccess::defaultModulesAllowedForLocked(),
                'access_level' => ReportAccess::REPORT_ACCESS_FREE,
                'variant' => ReportAccess::VARIANT_FREE,
                'has_active_grant' => false,
                'has_full_grant' => false,
                'has_partial_grant' => false,
            ];
        }

        $scaleCode = $this->resolveScaleCodeForAttempt($orgId, $attemptId);
        $rows = $this->activeGrantRowsForAttempt($orgId, $attemptId);
        $benefitCodes = [];
        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row->benefit_code ?? '')));
            if ($code !== '') {
                $benefitCodes[$code] = true;
            }
        }

        $modulesAllowed = $this->getAllowedModulesForAttempt($orgId, $attemptId);
        $freeModule = ReportAccess::freeModuleForScale($scaleCode);
        $fullModule = ReportAccess::fullModuleForScale($scaleCode);
        $hasPaidModuleAccess = count(array_diff($modulesAllowed, [$freeModule])) > 0;

        $hasFullGrant = in_array($fullModule, $modulesAllowed, true);
        $hasPartialGrant = false;
        if ($scaleCode === ReportAccess::SCALE_MBTI) {
            $hasFullGrant = $hasFullGrant || isset($benefitCodes[self::MBTI_FULL_BENEFIT_CODE]);
            $hasPartialGrant = isset($benefitCodes[self::MBTI_PARTIAL_BENEFIT_CODE]);
        } elseif ($scaleCode !== '') {
            $hasFullGrant = $hasFullGrant || $hasPaidModuleAccess;
        }

        $unlockStage = ReportAccess::UNLOCK_STAGE_LOCKED;
        if ($hasFullGrant) {
            $unlockStage = ReportAccess::UNLOCK_STAGE_FULL;
        } elseif ($hasPaidModuleAccess || $hasPartialGrant) {
            $unlockStage = ReportAccess::UNLOCK_STAGE_PARTIAL;
        }

        $unlockSource = $this->resolveUnlockSource($rows, $unlockStage);
        $accessLevel = match ($unlockStage) {
            ReportAccess::UNLOCK_STAGE_PARTIAL => ReportAccess::REPORT_ACCESS_PARTIAL,
            ReportAccess::UNLOCK_STAGE_FULL => ReportAccess::REPORT_ACCESS_FULL,
            default => ReportAccess::REPORT_ACCESS_FREE,
        };
        $variant = match ($unlockStage) {
            ReportAccess::UNLOCK_STAGE_PARTIAL => ReportAccess::VARIANT_PARTIAL,
            ReportAccess::UNLOCK_STAGE_FULL => ReportAccess::VARIANT_FULL,
            default => ReportAccess::VARIANT_FREE,
        };

        return [
            'unlock_stage' => $unlockStage,
            'unlock_source' => $unlockSource,
            'modules_allowed' => $modulesAllowed,
            'access_level' => $accessLevel,
            'variant' => $variant,
            'has_active_grant' => $rows->count() > 0,
            'has_full_grant' => $hasFullGrant,
            'has_partial_grant' => $hasPartialGrant || ($unlockStage === ReportAccess::UNLOCK_STAGE_PARTIAL),
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array{unlock_stage:string,unlock_source:string,modules_allowed:list<string>,access_level:string,variant:string,has_active_grant:bool,has_full_grant:bool,has_partial_grant:bool}
     */
    public function syncAttemptProjectionFromEntitlements(int $orgId, string $attemptId, array $meta = []): array
    {
        $attemptId = trim($attemptId);
        $state = $this->resolveAttemptUnlockState($orgId, $attemptId);
        if ($attemptId === '') {
            return $state;
        }

        $unlockStage = ReportAccess::normalizeUnlockStage((string) ($state['unlock_stage'] ?? ReportAccess::UNLOCK_STAGE_LOCKED));
        $resultExists = DB::table('results')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->exists();
        $existingProjection = UnifiedAccessProjection::query()
            ->where('attempt_id', $attemptId)
            ->first();
        $existingPayload = is_array($existingProjection?->payload_json) ? $existingProjection->payload_json : [];
        $existingActions = is_array($existingProjection?->actions_json) ? $existingProjection->actions_json : [];

        $payload = array_merge($existingPayload, [
            'attempt_id' => $attemptId,
            'result_exists' => $resultExists,
            'has_active_grant' => (bool) ($state['has_active_grant'] ?? false),
            'unlock_stage' => $unlockStage,
            'unlock_source' => ReportAccess::normalizeUnlockSource((string) ($state['unlock_source'] ?? ReportAccess::UNLOCK_SOURCE_NONE)),
            'modules_allowed' => ReportAccess::normalizeModules((array) ($state['modules_allowed'] ?? [])),
            'access_level' => ReportAccess::normalizeReportAccessLevel((string) ($state['access_level'] ?? ReportAccess::REPORT_ACCESS_FREE)),
            'variant' => ReportAccess::normalizeVariant((string) ($state['variant'] ?? ReportAccess::VARIANT_FREE)),
            'modules_preview' => ReportAccess::normalizeModules((array) ($state['modules_allowed'] ?? [])),
        ]);

        $patch = [
            'access_state' => $resultExists
                ? ($unlockStage === ReportAccess::UNLOCK_STAGE_LOCKED ? 'locked' : 'ready')
                : 'pending',
            'report_state' => $resultExists ? 'ready' : 'pending',
            'pdf_state' => $unlockStage === ReportAccess::UNLOCK_STAGE_FULL
                ? (string) ($existingProjection?->pdf_state ?? 'missing')
                : 'missing',
            'reason_code' => trim((string) ($meta['reason_code'] ?? '')) !== ''
                ? (string) $meta['reason_code']
                : ($unlockStage === ReportAccess::UNLOCK_STAGE_LOCKED ? 'projection_locked_from_entitlement' : 'entitlement_granted'),
            'actions_json' => array_merge($existingActions, [
                'report' => true,
                'pdf' => $unlockStage === ReportAccess::UNLOCK_STAGE_FULL,
                'unlock' => $unlockStage !== ReportAccess::UNLOCK_STAGE_LOCKED,
            ]),
            'payload_json' => $payload,
        ];

        $this->accessProjections->refreshAttemptProjection($attemptId, $patch, [
            'source_system' => trim((string) ($meta['source_system'] ?? 'entitlement_manager')),
            'source_ref' => trim((string) ($meta['source_ref'] ?? $attemptId)),
            'actor_type' => isset($meta['actor_type']) ? (string) $meta['actor_type'] : null,
            'actor_id' => isset($meta['actor_id']) ? (string) $meta['actor_id'] : null,
            'reason_code' => (string) $patch['reason_code'],
        ]);

        return $state;
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

        $skuRow = app(SkuCatalog::class)->getActiveSku($sku, null, $orderOrgId);
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
            $this->orders->syncGrantState($orderNo, $orderOrgId, 'revoked');

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

        if ($revoked > 0) {
            $this->orders->syncGrantState($orderNo, $orderOrgId, 'revoked');
        }

        return [
            'ok' => true,
            'revoked' => $revoked,
            'benefit_code' => $benefitCode,
            'attempt_id' => $attemptId,
        ];
    }

    private function activeGrantRowsForAttempt(int $orgId, string $attemptId): \Illuminate\Support\Collection
    {
        return DB::table('benefit_grants')
            ->select(['benefit_code', 'meta_json', 'scope', 'attempt_id', 'order_no'])
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
    }

    private function resolveScaleCodeForAttempt(int $orgId, string $attemptId): string
    {
        $attemptScale = DB::table('attempts')
            ->where('org_id', $orgId)
            ->where('id', $attemptId)
            ->value('scale_code');

        return strtoupper(trim((string) $attemptScale));
    }

    private function resolveUnlockSource(\Illuminate\Support\Collection $rows, string $unlockStage): string
    {
        $unlockStage = ReportAccess::normalizeUnlockStage($unlockStage);
        if ($unlockStage === ReportAccess::UNLOCK_STAGE_LOCKED) {
            return ReportAccess::UNLOCK_SOURCE_NONE;
        }

        $hasPaymentSource = false;
        $hasInviteSource = false;
        foreach ($rows as $row) {
            if (trim((string) ($row->order_no ?? '')) !== '') {
                $hasPaymentSource = true;
            }

            $benefitCode = strtoupper(trim((string) ($row->benefit_code ?? '')));
            $meta = $this->decodeMeta($row->meta_json ?? null);
            $grantedVia = strtolower(trim((string) ($meta['granted_via'] ?? '')));
            if ($grantedVia === 'invite_unlock' || $benefitCode === self::MBTI_PARTIAL_BENEFIT_CODE) {
                $hasInviteSource = true;
            }
        }

        if ($hasInviteSource && $hasPaymentSource) {
            return ReportAccess::UNLOCK_SOURCE_MIXED;
        }
        if ($hasInviteSource) {
            return ReportAccess::UNLOCK_SOURCE_INVITE;
        }
        if ($hasPaymentSource) {
            return ReportAccess::UNLOCK_SOURCE_PAYMENT;
        }

        return ReportAccess::UNLOCK_SOURCE_MIXED;
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

    /**
     * @param  array<string,mixed>  $meta
     */
    private function refreshAccessProjection(int $orgId, string $attemptId, array $meta): void
    {
        try {
            $this->syncAttemptProjectionFromEntitlements($orgId, $attemptId, $meta);
        } catch (\Throwable $e) {
            Log::error('ENTITLEMENT_ACCESS_PROJECTION_REFRESH_FAILED', [
                'attempt_id' => $attemptId,
                'source_ref' => $meta['source_ref'] ?? null,
                'source_system' => $meta['source_system'] ?? 'entitlement_manager',
                'actor_type' => $meta['actor_type'] ?? null,
                'actor_id' => $meta['actor_id'] ?? null,
                'exception' => $e,
            ]);
        }
    }

    private function benefitModuleRuleCatalog(): BenefitModuleRuleCatalog
    {
        return app(BenefitModuleRuleCatalog::class);
    }
}
