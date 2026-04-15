<?php

namespace App\Services\Report\Resolvers;

use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportAccess;

class AccessResolver
{
    public function __construct(private EntitlementManager $entitlements) {}

    /**
     * @return array{benefit_code:?string,has_full_access:bool}
     */
    public function resolveAccess(
        string $scaleCode,
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $attemptId,
        array $commercial,
        bool $forceFreeOnly
    ): array {
        $scaleCode = strtoupper(trim($scaleCode));
        $benefitCode = $this->resolveBenefitCode($commercial);
        $hasFullAccess = $benefitCode !== ''
            ? $this->entitlements->hasFullAccess($orgId, $userId, $anonId, $attemptId, $benefitCode)
            : false;

        if ($forceFreeOnly && $scaleCode !== ReportAccess::SCALE_BIG5_OCEAN) {
            $hasFullAccess = false;
        }
        if ($forceFreeOnly && $scaleCode === ReportAccess::SCALE_BIG5_OCEAN) {
            $hasFullAccess = true;
        }

        return [
            'benefit_code' => $benefitCode !== '' ? $benefitCode : null,
            'has_full_access' => $hasFullAccess,
        ];
    }

    /**
     * @param  list<string>  $modulesOffered
     * @return array{modules_allowed:list<string>,modules_preview:list<string>,has_paid_module_access:bool,unlock_stage:string}
     */
    public function resolveModules(
        string $scaleCode,
        int $orgId,
        string $attemptId,
        bool $hasFullAccess,
        bool $forceFreeOnly,
        array $modulesOffered
    ): array {
        $scaleCode = strtoupper(trim($scaleCode));
        if ($forceFreeOnly && $scaleCode === ReportAccess::SCALE_BIG5_OCEAN) {
            $modulesAllowed = ReportAccess::normalizeModules(array_merge(
                ReportAccess::defaultModulesAllowedForLocked($scaleCode),
                ReportAccess::allDefaultModulesOffered($scaleCode)
            ));

            return [
                'modules_allowed' => $modulesAllowed,
                'modules_preview' => [],
                'has_paid_module_access' => true,
                'unlock_stage' => ReportAccess::UNLOCK_STAGE_FULL,
            ];
        }

        $modulesAllowed = $this->entitlements->getAllowedModulesForAttempt($orgId, $attemptId);
        $modulesAllowed = $this->filterModulesForScale($scaleCode, $modulesAllowed);

        if ($modulesAllowed === [] || $forceFreeOnly) {
            $modulesAllowed = ReportAccess::defaultModulesAllowedForLocked($scaleCode);
        }

        $freeModule = ReportAccess::freeModuleForScale($scaleCode);
        $fullModule = ReportAccess::fullModuleForScale($scaleCode);

        if ($hasFullAccess) {
            $modulesAllowed = ReportAccess::normalizeModules(array_merge(
                $modulesAllowed,
                $modulesOffered,
                [$fullModule, $freeModule]
            ));
        }

        if (! in_array($freeModule, $modulesAllowed, true)) {
            $modulesAllowed[] = $freeModule;
            $modulesAllowed = ReportAccess::normalizeModules($modulesAllowed);
        }

        $modulesPreview = ReportAccess::normalizeModules($modulesOffered);
        $hasPaidModuleAccess = count(array_diff($modulesAllowed, [$freeModule])) > 0;
        $unlockStage = $hasFullAccess
            ? ReportAccess::UNLOCK_STAGE_FULL
            : ($hasPaidModuleAccess ? ReportAccess::UNLOCK_STAGE_PARTIAL : ReportAccess::UNLOCK_STAGE_LOCKED);

        return [
            'modules_allowed' => $modulesAllowed,
            'modules_preview' => $modulesPreview,
            'has_paid_module_access' => $hasPaidModuleAccess,
            'unlock_stage' => $unlockStage,
        ];
    }

    private function resolveBenefitCode(array $commercial): string
    {
        $benefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
        if ($benefitCode === '') {
            $benefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
        }

        return $benefitCode;
    }

    /**
     * @param  list<string>  $modules
     * @return list<string>
     */
    private function filterModulesForScale(string $scaleCode, array $modules): array
    {
        return match ($scaleCode) {
            ReportAccess::SCALE_BIG5_OCEAN => array_values(array_filter(
                $modules,
                static fn (string $module): bool => str_starts_with(strtolower($module), 'big5_')
            )),
            ReportAccess::SCALE_CLINICAL_COMBO_68 => array_values(array_filter(
                $modules,
                static fn (string $module): bool => str_starts_with(strtolower($module), 'clinical_')
            )),
            ReportAccess::SCALE_SDS_20 => array_values(array_filter(
                $modules,
                static fn (string $module): bool => str_starts_with(strtolower($module), 'sds_')
            )),
            ReportAccess::SCALE_EQ_60 => array_values(array_filter(
                $modules,
                static fn (string $module): bool => str_starts_with(strtolower($module), 'eq_')
            )),
            default => $modules,
        };
    }
}
