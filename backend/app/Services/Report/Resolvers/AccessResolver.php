<?php

namespace App\Services\Report\Resolvers;

use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportAccess;

class AccessResolver
{
    public function __construct(private EntitlementManager $entitlements) {}

    /**
     * @return array{benefit_code:?string,has_full_access:bool,has_entitlement_full_access:bool}
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
        $hasEntitlementFullAccess = $benefitCode !== ''
            ? $this->entitlements->hasFullAccess($orgId, $userId, $anonId, $attemptId, $benefitCode)
            : false;
        $hasFullAccess = $hasEntitlementFullAccess;
        $freeFullReportMode = $this->freeFullReportModeEnabled($scaleCode);

        if ($freeFullReportMode) {
            $hasFullAccess = true;
        } elseif ($forceFreeOnly && ! $this->isForceFreeFullAccessScale($scaleCode)) {
            $hasFullAccess = false;
        } elseif ($forceFreeOnly && $this->isForceFreeFullAccessScale($scaleCode)) {
            $hasFullAccess = true;
        }

        return [
            'benefit_code' => $benefitCode !== '' ? $benefitCode : null,
            'has_full_access' => $hasFullAccess,
            'has_entitlement_full_access' => $hasEntitlementFullAccess,
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
        array $modulesOffered,
        bool $allowAttemptScopedPaidModules = true,
        ?string $userId = null,
        ?string $anonId = null
    ): array {
        $scaleCode = strtoupper(trim($scaleCode));
        if (
            ($forceFreeOnly && $this->isForceFreeFullAccessScale($scaleCode))
            || $this->freeFullReportModeEnabled($scaleCode)
        ) {
            $modulesAllowed = $this->fullRuntimeModulesForScale($scaleCode);

            return [
                'modules_allowed' => $modulesAllowed,
                'modules_preview' => [],
                'has_paid_module_access' => true,
                'has_full_access' => true,
                'unlock_stage' => ReportAccess::UNLOCK_STAGE_FULL,
            ];
        }

        $modulesAllowed = $allowAttemptScopedPaidModules
            ? $this->entitlements->getAllowedModulesForAttemptForActor($orgId, $attemptId, $userId, $anonId)
            : ReportAccess::defaultModulesAllowedForLocked($scaleCode);
        $modulesAllowed = $this->filterModulesForScale($scaleCode, $modulesAllowed);

        if ($modulesAllowed === [] || $forceFreeOnly) {
            $modulesAllowed = ReportAccess::defaultModulesAllowedForLocked($scaleCode);
        }

        $freeModule = ReportAccess::freeModuleForScale($scaleCode);
        $fullModule = ReportAccess::fullModuleForScale($scaleCode);

        $hasFullModuleAccess = in_array($fullModule, $modulesAllowed, true);
        if ($hasFullAccess || (ReportAccess::isIqScale($scaleCode) && $hasFullModuleAccess)) {
            $hasFullAccess = true;
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
            'has_full_access' => $hasFullAccess,
            'unlock_stage' => $unlockStage,
        ];
    }

    public function freeFullReportModeEnabled(string $scaleCode): bool
    {
        $scaleCode = strtoupper(trim($scaleCode));
        $container = \Illuminate\Container\Container::getInstance();
        if (! $container || ! $container->bound('config')) {
            return false;
        }

        $config = $container->make('config');
        if (! (bool) $config->get('fap.features.free_full_report_mode', false)) {
            return false;
        }

        $allowedScales = array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) $config->get('fap.free_full_report_assessments', [])
        );

        return in_array($scaleCode, array_values(array_filter($allowedScales)), true);
    }

    private function resolveBenefitCode(array $commercial): string
    {
        $benefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
        if ($benefitCode === '') {
            $benefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
        }

        return $benefitCode;
    }

    private function isForceFreeFullAccessScale(string $scaleCode): bool
    {
        return in_array($scaleCode, [
            ReportAccess::SCALE_BIG5_OCEAN,
            ReportAccess::SCALE_EQ_60,
            ReportAccess::SCALE_ENNEAGRAM,
            ReportAccess::SCALE_RIASEC,
        ], true);
    }

    /**
     * @return list<string>
     */
    private function fullRuntimeModulesForScale(string $scaleCode): array
    {
        return $scaleCode === ReportAccess::SCALE_EQ_60
            ? ReportAccess::eq60AllRuntimeModules()
            : ReportAccess::normalizeModules(array_merge(
                ReportAccess::defaultModulesAllowedForLocked($scaleCode),
                ReportAccess::allDefaultModulesOffered($scaleCode)
            ));
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
            ReportAccess::SCALE_ENNEAGRAM => array_values(array_filter(
                $modules,
                static fn (string $module): bool => str_starts_with(strtolower($module), 'enneagram_')
            )),
            ReportAccess::SCALE_RIASEC => array_values(array_filter(
                $modules,
                static fn (string $module): bool => str_starts_with(strtolower($module), 'riasec_')
            )),
            ReportAccess::SCALE_IQ_RAVEN, ReportAccess::SCALE_IQ_INTELLIGENCE_QUOTIENT => array_values(array_filter(
                $modules,
                static fn (string $module): bool => str_starts_with(strtolower($module), 'iq_')
            )),
            default => $modules,
        };
    }
}
