<?php

declare(strict_types=1);

namespace App\Services\Ci;

final class ScaleImpactResolver
{
    private const SCALE_MBTI = 'MBTI';

    private const SCALE_BIG5 = 'BIG5_OCEAN';

    private const SCALE_CLINICAL = 'CLINICAL_COMBO_68';

    private const SCALE_SDS = 'SDS_20';

    private const SCALE_EQ = 'EQ_60';

    /**
     * @var list<string>
     */
    private const SHARED_PREFIXES = [
        'backend/routes/',
        'backend/app/Http/Middleware/',
        'backend/app/Http/Controllers/API/V0_3/',
        'backend/app/Services/Assessment/',
        'backend/app/Services/Report/',
        'backend/app/Services/Template/',
        'backend/app/Services/Attempts/',
        'backend/app/Services/Commerce/',
        'backend/database/migrations/',
        'backend/scripts/ci/',
    ];

    /**
     * @var list<string>
     */
    private const SHARED_EXACT = [
        'backend/routes/api.php',
        'backend/config/fap.php',
        'backend/app/Http/Middleware/FmTokenAuth.php',
        'backend/scripts/ci_verify_mbti.sh',
        '.github/workflows/ci_verify_mbti.yml',
        '.github/workflows/selfcheck.yml',
    ];

    public function resolve(array $paths): array
    {
        $normalized = $this->normalizePaths($paths);

        $sharedChanged = false;
        $mbtiChanged = false;
        $big5Changed = false;
        $clinicalChanged = false;
        $sdsChanged = false;
        $eqChanged = false;
        $sdsNormsChanged = false;

        foreach ($normalized as $path) {
            if ($this->isSharedPath($path)) {
                $sharedChanged = true;
                $mbtiChanged = true;
                $big5Changed = true;
                $clinicalChanged = true;
                $sdsChanged = true;
                $eqChanged = true;

                continue;
            }

            if ($this->isMbtiPath($path)) {
                $mbtiChanged = true;
            }

            if ($this->isBig5Path($path)) {
                $big5Changed = true;
            }

            if ($this->isClinicalPath($path)) {
                $clinicalChanged = true;
            }

            if ($this->isSdsPath($path)) {
                $sdsChanged = true;
            }

            if ($this->isEqPath($path)) {
                $eqChanged = true;
            }

            if ($this->isSdsNormsPath($path)) {
                $sdsNormsChanged = true;
            }
        }

        $runFullScaleRegression = $sharedChanged;
        $runBig5OceanGate = $sharedChanged || $big5Changed;
        $runClinicalCombo68Gate = $sharedChanged || $clinicalChanged;
        $runSds20Gate = $sharedChanged || $sdsChanged;
        $runEq60Gate = $sharedChanged || $eqChanged;
        $runSdsNormsGate = $sharedChanged || $sdsNormsChanged;
        $runMbtiSmoke = true;

        $scaleScope = $this->buildScaleScope(
            $runFullScaleRegression,
            $runBig5OceanGate,
            $runClinicalCombo68Gate,
            $runSds20Gate,
            $runEq60Gate,
            $mbtiChanged,
            $big5Changed,
            $clinicalChanged,
            $sdsChanged,
            $eqChanged
        );

        $scalesChanged = [];
        if ($mbtiChanged) {
            $scalesChanged[] = self::SCALE_MBTI;
        }
        if ($big5Changed) {
            $scalesChanged[] = self::SCALE_BIG5;
        }
        if ($clinicalChanged) {
            $scalesChanged[] = self::SCALE_CLINICAL;
        }
        if ($sdsChanged) {
            $scalesChanged[] = self::SCALE_SDS;
        }
        if ($eqChanged) {
            $scalesChanged[] = self::SCALE_EQ;
        }

        return [
            'changed_files' => $normalized,
            'shared_changed' => $sharedChanged,
            'mbti_changed' => $mbtiChanged,
            'big5_ocean_changed' => $big5Changed,
            'clinical_combo_68_changed' => $clinicalChanged,
            'sds_20_changed' => $sdsChanged,
            'eq_60_changed' => $eqChanged,
            'sds_norms_changed' => $sdsNormsChanged,
            'run_full_scale_regression' => $runFullScaleRegression,
            'run_big5_ocean_gate' => $runBig5OceanGate,
            'run_clinical_combo_68_gate' => $runClinicalCombo68Gate,
            'run_sds_20_gate' => $runSds20Gate,
            'run_eq_60_gate' => $runEq60Gate,
            'run_sds_norms_gate' => $runSdsNormsGate,
            'run_mbti_smoke' => $runMbtiSmoke,
            'scale_scope' => $scaleScope,
            'scales_changed' => $scalesChanged,
            'reason' => $this->buildReason($sharedChanged, $mbtiChanged, $big5Changed, $clinicalChanged, $sdsChanged, $eqChanged, $sdsNormsChanged),
        ];
    }

    /**
     * @param  array<int,string>  $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $value = str_replace('\\', '/', trim((string) $path));
            if ($value === '') {
                continue;
            }
            while (str_starts_with($value, './')) {
                $value = substr($value, 2);
            }
            $value = ltrim($value, '/');
            $out[$value] = true;
        }

        $result = array_keys($out);
        sort($result);

        return $result;
    }

    private function isSharedPath(string $path): bool
    {
        if ($this->isEqIsolatedPath($path)) {
            return false;
        }

        if (in_array($path, self::SHARED_EXACT, true)) {
            return true;
        }

        foreach (self::SHARED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isEqIsolatedPath(string $path): bool
    {
        return str_starts_with($path, 'backend/content_packs/EQ_60/')
            || str_contains($path, 'Eq60')
            || str_contains($path, 'EQ_60');
    }

    private function isMbtiPath(string $path): bool
    {
        $upper = strtoupper($path);

        if (str_contains($upper, 'MBTI')) {
            return true;
        }

        return str_starts_with($path, 'content_packages/default/')
            && str_contains($upper, '/MBTI-');
    }

    private function isBig5Path(string $path): bool
    {
        $upper = strtoupper($path);

        if (str_contains($upper, 'BIG5_OCEAN')) {
            return true;
        }

        if (str_contains($upper, 'BIGFIVE')) {
            return true;
        }

        if (str_contains($upper, 'BIG5-')) {
            return true;
        }

        return false;
    }

    private function isClinicalPath(string $path): bool
    {
        $upper = strtoupper($path);

        return str_contains($upper, 'CLINICAL_COMBO_68')
            || str_contains($upper, 'CDAI68');
    }

    private function isSdsPath(string $path): bool
    {
        $upper = strtoupper($path);

        return str_contains($upper, 'SDS_20')
            || str_contains($upper, 'SDS20');
    }

    private function isEqPath(string $path): bool
    {
        $upper = strtoupper($path);

        return str_contains($upper, 'EQ_60')
            || str_contains($path, 'Eq60');
    }

    private function isSdsNormsPath(string $path): bool
    {
        $upper = strtoupper($path);

        if (str_contains($upper, 'BACKEND/CONFIG/SDS_NORMS.PHP')) {
            return true;
        }
        if (str_contains($upper, 'BACKEND/RESOURCES/NORMS/SDS/')) {
            return true;
        }
        if (str_contains($upper, 'BACKEND/SCRIPTS/CI/VERIFY_SDS_NORMS.SH')) {
            return true;
        }
        if (str_contains($upper, 'BACKEND/APP/CONSOLE/COMMANDS/NORMSSDS')) {
            return true;
        }
        if (str_contains($upper, 'BACKEND/APP/CONSOLE/COMMANDS/SDSPSYCHOMETRICSREPORT')) {
            return true;
        }
        if (str_contains($upper, 'BACKEND/APP/SERVICES/PSYCHOMETRICS/SDS/')) {
            return true;
        }
        if (str_contains($upper, 'BACKEND/APP/SERVICES/ASSESSMENT/NORMS/SDSNORMGROUPRESOLVER.PHP')) {
            return true;
        }
        if (str_contains($upper, 'BACKEND/TESTS/FEATURE/PSYCHOMETRICS/SDS')) {
            return true;
        }

        return false;
    }

    private function buildScaleScope(
        bool $runFullScaleRegression,
        bool $runBig5OceanGate,
        bool $runClinicalCombo68Gate,
        bool $runSds20Gate,
        bool $runEq60Gate,
        bool $mbtiChanged,
        bool $big5Changed,
        bool $clinicalChanged,
        bool $sdsChanged,
        bool $eqChanged
    ): string {
        if ($runFullScaleRegression) {
            return 'full_regression';
        }

        if ($runBig5OceanGate && $runClinicalCombo68Gate && $runSds20Gate) {
            return 'big5_clinical_sds_with_mbti_smoke';
        }

        if ($runBig5OceanGate && $runClinicalCombo68Gate) {
            return 'big5_clinical_with_mbti_smoke';
        }

        if ($runBig5OceanGate && $runSds20Gate) {
            return 'big5_sds_with_mbti_smoke';
        }

        if ($runClinicalCombo68Gate && $runSds20Gate) {
            return 'clinical_sds_with_mbti_smoke';
        }

        if ($runBig5OceanGate) {
            if ($mbtiChanged) {
                return 'big5_plus_mbti';
            }

            return 'big5_with_mbti_smoke';
        }

        if ($runClinicalCombo68Gate) {
            if ($mbtiChanged) {
                return 'clinical_plus_mbti';
            }

            return 'clinical_with_mbti_smoke';
        }

        if ($runSds20Gate) {
            if ($mbtiChanged) {
                return 'sds_plus_mbti';
            }

            return 'sds_with_mbti_smoke';
        }

        if ($runEq60Gate) {
            if ($mbtiChanged) {
                return 'eq60_plus_mbti';
            }

            return 'eq60_with_mbti_smoke';
        }

        if ($mbtiChanged) {
            return 'mbti_only';
        }

        if ($big5Changed) {
            return 'big5_with_mbti_smoke';
        }

        if ($clinicalChanged) {
            return 'clinical_with_mbti_smoke';
        }

        if ($sdsChanged) {
            return 'sds_with_mbti_smoke';
        }

        if ($eqChanged) {
            return 'eq60_with_mbti_smoke';
        }

        return 'mbti_only';
    }

    private function buildReason(
        bool $sharedChanged,
        bool $mbtiChanged,
        bool $big5Changed,
        bool $clinicalChanged,
        bool $sdsChanged,
        bool $eqChanged,
        bool $sdsNormsChanged
    ): string {
        if ($sharedChanged) {
            return 'shared-layer changed: run full cross-scale regression';
        }

        if ($big5Changed && $clinicalChanged && $sdsChanged) {
            return 'BIG5 + CLINICAL + SDS changed: run all gates + MBTI smoke';
        }

        if ($big5Changed && $sdsChanged) {
            return 'BIG5 and SDS changed: run both gates + MBTI smoke';
        }

        if ($clinicalChanged && $sdsChanged) {
            return 'CLINICAL and SDS changed: run both gates + MBTI smoke';
        }

        if ($big5Changed && $clinicalChanged) {
            return 'BIG5 and CLINICAL changed: run both gates + MBTI smoke';
        }

        if ($big5Changed && $mbtiChanged) {
            return 'both MBTI and BIG5 changed: run BIG5 gate + MBTI smoke';
        }

        if ($big5Changed) {
            return 'BIG5 changed: run BIG5 gate + MBTI smoke';
        }

        if ($clinicalChanged && $mbtiChanged) {
            return 'CLINICAL changed with MBTI: run clinical gate + MBTI smoke';
        }

        if ($clinicalChanged) {
            return 'CLINICAL changed: run clinical gate + MBTI smoke';
        }

        if ($sdsChanged && $mbtiChanged) {
            return 'SDS changed with MBTI: run SDS gate + MBTI smoke';
        }

        if ($sdsChanged) {
            return 'SDS changed: run SDS gate + MBTI smoke';
        }

        if ($eqChanged && $mbtiChanged) {
            return 'EQ_60 changed with MBTI: run EQ_60 gate + MBTI smoke';
        }

        if ($eqChanged) {
            return 'EQ_60 changed: run EQ_60 gate + MBTI smoke';
        }

        if ($sdsNormsChanged) {
            return 'SDS norms changed: run SDS norms gate + MBTI smoke';
        }

        if ($mbtiChanged) {
            return 'MBTI changed: keep MBTI chain, skip BIG5 gate';
        }

        return 'no scale-specific changes detected: keep MBTI chain only';
    }
}
