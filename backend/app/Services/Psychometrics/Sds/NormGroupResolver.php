<?php

declare(strict_types=1);

namespace App\Services\Psychometrics\Sds;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NormGroupResolver
{
    public function resolve(string $scaleCode, array $ctx = []): array
    {
        $scaleCode = strtoupper(trim($scaleCode));
        if ($scaleCode === '') {
            $scaleCode = 'SDS_20';
        }

        $locale = $this->normalizeLocale((string) ($ctx['locale'] ?? ''));
        $region = $this->normalizeRegion((string) ($ctx['region'] ?? ($ctx['country'] ?? '')));
        $gender = $this->normalizeGender((string) ($ctx['gender'] ?? 'ALL'));
        $ageBand = $this->resolveAgeBand($ctx);

        if (! Schema::hasTable('scale_norms_versions') || ! Schema::hasTable('scale_norm_stats')) {
            return [
                'status' => 'MISSING',
                'group_id' => $locale.'_all_18-60',
                'norms_version' => '',
                'source_id' => '',
                'source_type' => '',
                'metric' => [
                    'mean' => 0.0,
                    'sd' => 0.0,
                    'sample_n' => 0,
                    'metric_level' => 'global',
                    'metric_code' => 'INDEX_SCORE',
                ],
                'origin' => 'missing',
                'context' => [
                    'locale' => $locale,
                    'region' => $region,
                    'gender' => $gender,
                    'age_band' => $ageBand,
                ],
            ];
        }

        $candidates = $this->buildCandidates($locale, $gender, $ageBand);

        foreach ($candidates as $groupId) {
            $hit = $this->loadGroup($scaleCode, $groupId, $locale, $region);
            if (! is_array($hit)) {
                continue;
            }

            return [
                'status' => $this->mapStatus((string) ($hit['status'] ?? 'MISSING')),
                'group_id' => (string) ($hit['group_id'] ?? $groupId),
                'norms_version' => (string) ($hit['norms_version'] ?? ''),
                'source_id' => (string) ($hit['source_id'] ?? ''),
                'source_type' => (string) ($hit['source_type'] ?? ''),
                'metric' => [
                    'mean' => (float) ($hit['mean'] ?? 0.0),
                    'sd' => (float) ($hit['sd'] ?? 0.0),
                    'sample_n' => (int) ($hit['sample_n'] ?? 0),
                    'metric_level' => (string) ($hit['metric_level'] ?? 'global'),
                    'metric_code' => (string) ($hit['metric_code'] ?? 'INDEX_SCORE'),
                ],
                'origin' => 'db',
                'context' => [
                    'locale' => $locale,
                    'region' => $region,
                    'gender' => $gender,
                    'age_band' => $ageBand,
                ],
            ];
        }

        return [
            'status' => 'MISSING',
            'group_id' => $locale.'_all_18-60',
            'norms_version' => '',
            'source_id' => '',
            'source_type' => '',
            'metric' => [
                'mean' => 0.0,
                'sd' => 0.0,
                'sample_n' => 0,
                'metric_level' => 'global',
                'metric_code' => 'INDEX_SCORE',
            ],
            'origin' => 'missing',
            'context' => [
                'locale' => $locale,
                'region' => $region,
                'gender' => $gender,
                'age_band' => $ageBand,
            ],
        ];
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return 'zh-CN';
        }

        $locale = str_replace('_', '-', $locale);
        $parts = explode('-', $locale);
        if (count($parts) === 1) {
            if (strtolower($parts[0]) === 'zh') {
                return 'zh-CN';
            }

            return strtolower($parts[0]);
        }

        return strtolower($parts[0]).'-'.strtoupper($parts[1]);
    }

    private function normalizeRegion(string $region): string
    {
        $region = strtoupper(trim($region));
        if ($region === '') {
            return 'GLOBAL';
        }

        return str_replace('-', '_', $region);
    }

    private function normalizeGender(string $gender): string
    {
        $gender = strtolower(trim($gender));
        if ($gender === '') {
            return 'all';
        }

        return match ($gender) {
            'm', 'male' => 'm',
            'f', 'female' => 'f',
            default => 'all',
        };
    }

    private function resolveAgeBand(array $ctx): string
    {
        $ageBand = trim((string) ($ctx['age_band'] ?? ''));
        $default = (string) config('sds_norms.resolver.default_age_band', '18-60');
        if ($ageBand !== '' && ! in_array(strtolower($ageBand), ['all', 'any', '*'], true)) {
            return $ageBand;
        }

        $age = (int) ($ctx['age'] ?? 0);
        if ($age <= 0) {
            return $default;
        }

        $bands = (array) config('sds_norms.resolver.age_bands', []);
        foreach ($bands as $band => $def) {
            if (! is_string($band) || ! is_array($def)) {
                continue;
            }

            $min = (int) ($def['min'] ?? 0);
            $max = (int) ($def['max'] ?? 0);
            if ($min <= 0 || $max <= 0 || $max < $min) {
                continue;
            }

            if ($age >= $min && $age <= $max) {
                return $band;
            }
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    private function buildCandidates(string $locale, string $gender, string $ageBand): array
    {
        $chainKey = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
        $templates = (array) config("sds_norms.resolver.chains.{$chainKey}", []);

        $out = [];
        foreach ($templates as $template) {
            if (! is_string($template) || trim($template) === '') {
                continue;
            }

            $groupId = strtr($template, [
                '{locale}' => $locale,
                '{gender}' => $gender,
                '{age_band}' => $ageBand,
            ]);
            $groupId = trim($groupId);
            if ($groupId === '') {
                continue;
            }

            $out[$groupId] = true;
        }

        return array_keys($out);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadGroup(string $scaleCode, string $groupId, string $locale, string $region): ?array
    {
        $version = DB::table('scale_norms_versions')
            ->where('scale_code', $scaleCode)
            ->where('group_id', $groupId)
            ->where('locale', $locale)
            ->where(function ($query) use ($region): void {
                $query->where('region', $region)
                    ->orWhere(function ($sub) use ($region): void {
                        if ($region === 'CN_MAINLAND') {
                            $sub->where('region', 'GLOBAL');
                        }
                    });
            })
            ->orderByDesc('is_active')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $version) {
            return null;
        }

        $metric = DB::table('scale_norm_stats')
            ->where('norm_version_id', (string) $version->id)
            ->whereIn('metric_level', ['global', 'index'])
            ->where('metric_code', 'INDEX_SCORE')
            ->orderByDesc('sample_n')
            ->first();

        if (! $metric) {
            return null;
        }

        $sd = (float) ($metric->sd ?? 0.0);
        $sampleN = (int) ($metric->sample_n ?? 0);
        if ($sd <= 0.0 || $sampleN <= 0) {
            return null;
        }

        return [
            'group_id' => (string) ($version->group_id ?? $groupId),
            'status' => (string) ($version->status ?? 'MISSING'),
            'norms_version' => (string) ($version->version ?? ''),
            'source_id' => (string) ($version->source_id ?? ''),
            'source_type' => (string) ($version->source_type ?? ''),
            'metric_level' => (string) ($metric->metric_level ?? 'global'),
            'metric_code' => (string) ($metric->metric_code ?? 'INDEX_SCORE'),
            'mean' => (float) ($metric->mean ?? 0.0),
            'sd' => $sd,
            'sample_n' => $sampleN,
        ];
    }

    private function mapStatus(string $status): string
    {
        $status = strtoupper(trim($status));

        return match ($status) {
            'CALIBRATED' => 'CALIBRATED',
            'PROVISIONAL', 'BOOTSTRAP', 'RETIRED' => 'PROVISIONAL',
            default => 'MISSING',
        };
    }
}
