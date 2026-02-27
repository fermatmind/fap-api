<?php

namespace App\Services\Report\Resolvers;

use App\Services\Report\ReportAccess;

class CrisisPolicyResolver
{
    /**
     * @param  list<string>  $modulesAllowed
     * @param  list<string>  $modulesOffered
     * @param  list<string>  $modulesPreview
     * @return array{crisis_alert:bool,paywall:array<string,mixed>,modules_allowed:list<string>,modules_offered:list<string>,modules_preview:list<string>,has_full_access:bool,has_paid_module_access:bool}
     */
    public function apply(
        string $scaleCode,
        array $qualityPayload,
        array $paywall,
        array $modulesAllowed,
        array $modulesOffered,
        array $modulesPreview,
        bool $hasFullAccess,
        bool $hasPaidModuleAccess
    ): array {
        $scaleCode = strtoupper(trim($scaleCode));
        $crisisAlert = (bool) ($qualityPayload['crisis_alert'] ?? false);

        if (in_array($scaleCode, [ReportAccess::SCALE_CLINICAL_COMBO_68, ReportAccess::SCALE_SDS_20], true) && $crisisAlert) {
            $paywall['offers'] = [];
            $paywall['upgrade_sku'] = null;
            $paywall['upgrade_sku_effective'] = null;
            $modulesOffered = [];
            $modulesPreview = [];
            $modulesAllowed = ReportAccess::defaultModulesAllowedForLocked($scaleCode);
            $hasFullAccess = false;
            $hasPaidModuleAccess = false;
        }

        return [
            'crisis_alert' => $crisisAlert,
            'paywall' => $paywall,
            'modules_allowed' => $modulesAllowed,
            'modules_offered' => $modulesOffered,
            'modules_preview' => $modulesPreview,
            'has_full_access' => $hasFullAccess,
            'has_paid_module_access' => $hasPaidModuleAccess,
        ];
    }
}
