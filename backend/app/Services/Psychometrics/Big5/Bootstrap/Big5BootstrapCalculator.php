<?php

declare(strict_types=1);

namespace App\Services\Psychometrics\Big5\Bootstrap;

final class Big5BootstrapCalculator
{
    private const DOMAINS = ['O', 'C', 'E', 'A', 'N'];

    private const FACETS = [
        'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
        'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
        'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
        'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
    ];

    /**
     * @return list<string>
     */
    public static function metricCodes(): array
    {
        return [...self::DOMAINS, ...self::FACETS];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $qualityFilters
     * @return array{
     *   ok: bool,
     *   errors: list<string>,
     *   stats?: array{domain: array<string,array{mean: float,sd: float,sample_n: int}>, facet: array<string,array{mean: float,sd: float,sample_n: int}>},
     *   sample_n_raw?: int,
     *   sample_n_kept?: int
     * }
     */
    public function calculateFromAttemptRows(array $rows, array $qualityFilters): array
    {
        $codes = self::metricCodes();
        $filters = array_values(array_unique(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            $qualityFilters
        )));
        if ($filters === []) {
            $filters = ['A', 'B'];
        }

        $sampleNRaw = count($rows);
        $sampleNKept = 0;
        $samples = [];
        foreach ($codes as $code) {
            $samples[$code] = [];
        }

        $errors = [];
        foreach ($rows as $index => $row) {
            $qualityLevel = strtoupper(trim((string) ($row['quality_level'] ?? 'A')));
            if (!in_array($qualityLevel, $filters, true)) {
                continue;
            }

            $values = [];
            foreach ($codes as $code) {
                $rawValue = $row[$code] ?? null;
                if ($rawValue === null || trim((string) $rawValue) === '') {
                    $errors[] = sprintf('row %d missing metric=%s', $index + 2, $code);
                    continue 2;
                }
                if (!is_numeric($rawValue)) {
                    $errors[] = sprintf('row %d non-numeric metric=%s value=%s', $index + 2, $code, (string) $rawValue);
                    continue 2;
                }
                $values[$code] = (float) $rawValue;
            }

            $sampleNKept++;
            foreach ($values as $code => $value) {
                $samples[$code][] = $value;
            }
        }

        if ($sampleNKept === 0) {
            $errors[] = 'no rows remained after quality filters';
        }

        $domainStats = [];
        foreach (self::DOMAINS as $domain) {
            if (($samples[$domain] ?? []) === []) {
                $errors[] = sprintf('coverage missing for domain=%s', $domain);
                continue;
            }
            $domainStats[$domain] = $this->toStat($samples[$domain]);
        }

        $facetStats = [];
        foreach (self::FACETS as $facet) {
            if (($samples[$facet] ?? []) === []) {
                $errors[] = sprintf('coverage missing for facet=%s', $facet);
                continue;
            }
            $facetStats[$facet] = $this->toStat($samples[$facet]);
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        return [
            'ok' => true,
            'errors' => [],
            'stats' => [
                'domain' => $domainStats,
                'facet' => $facetStats,
            ],
            'sample_n_raw' => $sampleNRaw,
            'sample_n_kept' => $sampleNKept,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{
     *   ok: bool,
     *   errors: list<string>,
     *   stats?: array{domain: array<string,array{mean: float,sd: float,sample_n: int}>, facet: array<string,array{mean: float,sd: float,sample_n: int}>},
     *   sample_n_raw?: int,
     *   sample_n_kept?: int
     * }
     */
    public function calculateFromNormRows(array $rows): array
    {
        $domainStats = [];
        $facetStats = [];
        $sampleN = 0;
        $errors = [];

        foreach ($rows as $row) {
            $level = strtolower(trim((string) ($row['metric_level'] ?? '')));
            $code = strtoupper(trim((string) ($row['metric_code'] ?? '')));

            if (!in_array($level, ['domain', 'facet'], true)) {
                continue;
            }
            if (!in_array($code, self::metricCodes(), true)) {
                continue;
            }

            $mean = is_numeric($row['mean'] ?? null) ? (float) $row['mean'] : null;
            $sd = is_numeric($row['sd'] ?? null) ? (float) $row['sd'] : null;
            $sample = is_numeric($row['sample_n'] ?? null) ? (int) $row['sample_n'] : null;

            if ($mean === null || $sd === null || $sample === null) {
                $errors[] = sprintf('invalid norm row for %s:%s', $level, $code);
                continue;
            }

            $stat = [
                'mean' => round($mean, 3),
                'sd' => round(max($sd, 0.0001), 3),
                'sample_n' => max($sample, 1),
            ];
            $sampleN = max($sampleN, $stat['sample_n']);

            if ($level === 'domain') {
                $domainStats[$code] = $stat;
            } else {
                $facetStats[$code] = $stat;
            }
        }

        foreach (self::DOMAINS as $domain) {
            if (!isset($domainStats[$domain])) {
                $errors[] = sprintf('coverage missing for domain=%s', $domain);
            }
        }
        foreach (self::FACETS as $facet) {
            if (!isset($facetStats[$facet])) {
                $errors[] = sprintf('coverage missing for facet=%s', $facet);
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => array_values(array_unique($errors)),
            ];
        }

        return [
            'ok' => true,
            'errors' => [],
            'stats' => [
                'domain' => $domainStats,
                'facet' => $facetStats,
            ],
            'sample_n_raw' => $sampleN,
            'sample_n_kept' => $sampleN,
        ];
    }

    /**
     * @param list<float> $values
     * @return array{mean: float, sd: float, sample_n: int}
     */
    private function toStat(array $values): array
    {
        $count = max(count($values), 1);
        $mean = array_sum($values) / $count;
        $varianceAccumulator = 0.0;
        foreach ($values as $value) {
            $delta = $value - $mean;
            $varianceAccumulator += $delta * $delta;
        }
        $sd = sqrt($varianceAccumulator / $count);

        return [
            'mean' => round($mean, 3),
            'sd' => round(max($sd, 0.0001), 3),
            'sample_n' => $count,
        ];
    }
}
