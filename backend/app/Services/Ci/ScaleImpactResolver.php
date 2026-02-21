<?php

declare(strict_types=1);

namespace App\Services\Ci;

final class ScaleImpactResolver
{
    private const SCALE_MBTI = 'MBTI';
    private const SCALE_BIG5 = 'BIG5_OCEAN';

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

        foreach ($normalized as $path) {
            if ($this->isSharedPath($path)) {
                $sharedChanged = true;
                $mbtiChanged = true;
                $big5Changed = true;
                continue;
            }

            if ($this->isMbtiPath($path)) {
                $mbtiChanged = true;
            }

            if ($this->isBig5Path($path)) {
                $big5Changed = true;
            }
        }

        $runFullScaleRegression = $sharedChanged;
        $runBig5OceanGate = $sharedChanged || $big5Changed;
        $runMbtiSmoke = true;

        $scaleScope = $this->buildScaleScope($runFullScaleRegression, $runBig5OceanGate, $mbtiChanged, $big5Changed);

        $scalesChanged = [];
        if ($mbtiChanged) {
            $scalesChanged[] = self::SCALE_MBTI;
        }
        if ($big5Changed) {
            $scalesChanged[] = self::SCALE_BIG5;
        }

        return [
            'changed_files' => $normalized,
            'shared_changed' => $sharedChanged,
            'mbti_changed' => $mbtiChanged,
            'big5_ocean_changed' => $big5Changed,
            'run_full_scale_regression' => $runFullScaleRegression,
            'run_big5_ocean_gate' => $runBig5OceanGate,
            'run_mbti_smoke' => $runMbtiSmoke,
            'scale_scope' => $scaleScope,
            'scales_changed' => $scalesChanged,
            'reason' => $this->buildReason($sharedChanged, $mbtiChanged, $big5Changed),
        ];
    }

    /**
     * @param array<int,string> $paths
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
            $value = ltrim($value, './');
            $out[$value] = true;
        }

        $result = array_keys($out);
        sort($result);

        return $result;
    }

    private function isSharedPath(string $path): bool
    {
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

    private function buildScaleScope(bool $runFullScaleRegression, bool $runBig5OceanGate, bool $mbtiChanged, bool $big5Changed): string
    {
        if ($runFullScaleRegression) {
            return 'full_regression';
        }

        if ($runBig5OceanGate) {
            if ($mbtiChanged) {
                return 'big5_plus_mbti';
            }

            return 'big5_with_mbti_smoke';
        }

        if ($mbtiChanged) {
            return 'mbti_only';
        }

        if ($big5Changed) {
            return 'big5_with_mbti_smoke';
        }

        return 'mbti_only';
    }

    private function buildReason(bool $sharedChanged, bool $mbtiChanged, bool $big5Changed): string
    {
        if ($sharedChanged) {
            return 'shared-layer changed: run full cross-scale regression';
        }

        if ($big5Changed && $mbtiChanged) {
            return 'both MBTI and BIG5 changed: run BIG5 gate + MBTI smoke';
        }

        if ($big5Changed) {
            return 'BIG5 changed: run BIG5 gate + MBTI smoke';
        }

        if ($mbtiChanged) {
            return 'MBTI changed: keep MBTI chain, skip BIG5 gate';
        }

        return 'no scale-specific changes detected: keep MBTI chain only';
    }
}
