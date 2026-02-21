<?php

namespace App\Services\Psychometrics\Big5;

class Big5Standardizer
{
    /**
     * @var array<string,float>|null
     */
    private static ?array $cdfTable = null;

    public function standardize(float $scoreMean, float $normMean, float $normSd): array
    {
        if ($normSd <= 0.0) {
            return [
                'z' => 0.0,
                't' => 50,
                'pct' => 50,
            ];
        }

        $z = ($scoreMean - $normMean) / $normSd;
        $z = $this->clampZ($z);

        return [
            'z' => round($z, 2),
            't' => (int) round(50 + 10 * $z),
            'pct' => (int) round($this->normalCdf($z) * 100),
        ];
    }

    private function clampZ(float $z): float
    {
        $min = (float) config('big5_norms.standardizer.z_clamp_min', -3.5);
        $max = (float) config('big5_norms.standardizer.z_clamp_max', 3.5);

        if ($max <= $min) {
            $max = $min + 1.0;
        }

        if ($z < $min) {
            return $min;
        }
        if ($z > $max) {
            return $max;
        }

        return $z;
    }

    private function normalCdf(float $z): float
    {
        $table = $this->cdfTable();
        if ($table === []) {
            // fallback approximation path for safety.
            return $this->normalCdfApprox($z);
        }

        $abs = abs($z);
        $abs = round($abs, 4);

        $maxZ = (float) config('big5_norms.standardizer.z_clamp_max', 3.5);
        if ($abs > $maxZ) {
            $abs = $maxZ;
        }

        $lo = floor($abs * 100.0) / 100.0;
        $hi = ceil($abs * 100.0) / 100.0;

        $loKey = number_format($lo, 2, '.', '');
        $hiKey = number_format($hi, 2, '.', '');

        $loCdf = $table[$loKey] ?? null;
        $hiCdf = $table[$hiKey] ?? null;

        if ($loCdf === null && $hiCdf === null) {
            return $this->normalCdfApprox($z);
        }

        if ($loCdf === null) {
            $loCdf = $hiCdf;
        }
        if ($hiCdf === null) {
            $hiCdf = $loCdf;
        }

        if ($hi <= $lo) {
            $cdfAbs = (float) $loCdf;
        } else {
            $ratio = ($abs - $lo) / ($hi - $lo);
            $cdfAbs = (float) $loCdf + (((float) $hiCdf - (float) $loCdf) * $ratio);
        }

        return $z >= 0 ? $cdfAbs : (1.0 - $cdfAbs);
    }

    private function cdfTable(): array
    {
        if (self::$cdfTable !== null) {
            return self::$cdfTable;
        }

        $path = base_path((string) config('big5_norms.standardizer.cdf_file', 'resources/stats/normal_cdf_0p01.csv'));
        if (!is_file($path)) {
            self::$cdfTable = [];
            return self::$cdfTable;
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            self::$cdfTable = [];
            return self::$cdfTable;
        }

        $header = null;
        $table = [];
        while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            if (!is_array($row)) {
                continue;
            }
            if ($header === null) {
                $header = array_map(static fn ($v): string => trim((string) $v), $row);
                continue;
            }

            $assoc = [];
            foreach ($header as $idx => $key) {
                $assoc[$key] = trim((string) ($row[$idx] ?? ''));
            }

            $z = (string) ($assoc['z'] ?? '');
            $cdf = (string) ($assoc['cdf'] ?? '');
            if ($z === '' || $cdf === '') {
                continue;
            }
            $table[number_format((float) $z, 2, '.', '')] = (float) $cdf;
        }

        fclose($fp);

        self::$cdfTable = $table;

        return self::$cdfTable;
    }

    private function normalCdfApprox(float $z): float
    {
        $x = abs($z);
        $t = 1.0 / (1.0 + 0.2316419 * $x);
        $d = 0.3989423 * exp(-$x * $x / 2.0);
        $p = 1.0 - $d * (
            0.3193815 * $t
            - 0.3565638 * $t * $t
            + 1.781478 * $t * $t * $t
            - 1.821256 * $t * $t * $t * $t
            + 1.330274 * $t * $t * $t * $t * $t
        );

        return $z >= 0 ? $p : 1.0 - $p;
    }
}
