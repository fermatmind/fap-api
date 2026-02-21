<?php

namespace App\Services\Psychometrics\Big5;

use App\Models\ScaleNormStat;
use App\Models\ScaleNormsVersion;

class NormStatsRepository
{
    private const DOMAINS = ['O', 'C', 'E', 'A', 'N'];

    private const FACETS = [
        'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
        'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
        'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
        'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
    ];

    public function resolveDbGroup(string $scaleCode, string $groupId): ?array
    {
        $scaleCode = strtoupper(trim($scaleCode));
        $groupId = trim($groupId);
        if ($scaleCode === '' || $groupId === '') {
            return null;
        }

        $version = ScaleNormsVersion::query()
            ->where('scale_code', $scaleCode)
            ->where('group_id', $groupId)
            ->orderByDesc('is_active')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->first();

        if (!$version) {
            return null;
        }

        $stats = ScaleNormStat::query()
            ->where('norm_version_id', (string) $version->id)
            ->get();

        if ($stats->isEmpty()) {
            return null;
        }

        $domains = [];
        $facets = [];

        foreach ($stats as $stat) {
            $level = strtolower((string) $stat->metric_level);
            $code = strtoupper((string) $stat->metric_code);

            $row = [
                'mean' => (float) $stat->mean,
                'sd' => (float) $stat->sd,
                'sample_n' => (int) $stat->sample_n,
                'source_id' => (string) ($version->source_id ?? ''),
                'norms_version' => (string) ($version->version ?? ''),
            ];

            if ($level === 'domain') {
                $domains[$code] = $row;
            } elseif ($level === 'facet') {
                $facets[$code] = $row;
            }
        }

        if (!$this->hasFullCoverage($domains, $facets)) {
            return null;
        }

        return [
            'id' => (string) $version->id,
            'group_id' => (string) $version->group_id,
            'locale' => (string) $version->locale,
            'region' => (string) $version->region,
            'gender' => (string) ($version->gender ?? 'ALL'),
            'age_min' => (int) ($version->age_min ?? 18),
            'age_max' => (int) ($version->age_max ?? 60),
            'source_id' => (string) ($version->source_id ?? ''),
            'source_type' => (string) ($version->source_type ?? ''),
            'norms_version' => (string) ($version->version ?? ''),
            'status' => strtoupper((string) ($version->status ?? 'BOOTSTRAP')),
            'is_active' => (bool) ($version->is_active ?? false),
            'domains' => $domains,
            'facets' => $facets,
        ];
    }

    private function hasFullCoverage(array $domains, array $facets): bool
    {
        if (count($domains) !== 5 || count(array_intersect(array_keys($domains), self::DOMAINS)) !== 5) {
            return false;
        }

        if (count($facets) !== 30 || count(array_intersect(array_keys($facets), self::FACETS)) !== 30) {
            return false;
        }

        foreach ($domains as $row) {
            if ((float) ($row['sd'] ?? 0) <= 0.0 || (int) ($row['sample_n'] ?? 0) <= 0) {
                return false;
            }
        }

        foreach ($facets as $row) {
            if ((float) ($row['sd'] ?? 0) <= 0.0 || (int) ($row['sample_n'] ?? 0) <= 0) {
                return false;
            }
        }

        return true;
    }
}
