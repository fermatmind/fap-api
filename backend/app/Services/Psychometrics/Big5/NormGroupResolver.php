<?php

namespace App\Services\Psychometrics\Big5;

class NormGroupResolver
{
    public function __construct(private readonly NormStatsRepository $repository)
    {
    }

    public function resolve(string $scaleCode, array $ctx, array $compiledNorms = []): array
    {
        $locale = $this->normalizeLocale((string) ($ctx['locale'] ?? ''));
        $region = $this->normalizeRegion((string) ($ctx['region'] ?? ($ctx['country'] ?? '')));
        $gender = strtoupper(trim((string) ($ctx['gender'] ?? 'ALL')));
        if ($gender === '') {
            $gender = 'ALL';
        }
        $ageBand = $this->resolveAgeBand($ctx);

        $candidates = $this->buildDbCandidates($locale, $gender, $ageBand);
        foreach ($candidates as $groupId) {
            $hit = $this->repository->resolveDbGroup($scaleCode, $groupId);
            if ($hit === null) {
                continue;
            }

            return [
                'group_id' => (string) ($hit['group_id'] ?? $groupId),
                'status' => $this->mapStatus((string) ($hit['status'] ?? 'BOOTSTRAP')),
                'domain_group_id' => (string) ($hit['group_id'] ?? $groupId),
                'facet_group_id' => (string) ($hit['group_id'] ?? $groupId),
                'norms_version' => (string) ($hit['norms_version'] ?? ''),
                'source_id' => (string) ($hit['source_id'] ?? ''),
                'source_type' => (string) ($hit['source_type'] ?? ''),
                'origin' => 'db',
                'domains' => (array) ($hit['domains'] ?? []),
                'facets' => (array) ($hit['facets'] ?? []),
                'context' => [
                    'locale' => $locale,
                    'region' => $region,
                    'gender' => $gender,
                    'age_band' => $ageBand,
                ],
            ];
        }

        $fallback = $this->resolveCompiled($compiledNorms, [
            'locale' => $locale,
            'country' => $region,
            'age_band' => $ageBand,
            'gender' => $gender,
        ]);
        $fallback['origin'] = 'compiled';
        $fallback['context'] = [
            'locale' => $locale,
            'region' => $region,
            'gender' => $gender,
            'age_band' => $ageBand,
        ];

        return $fallback;
    }

    private function mapStatus(string $status): string
    {
        $status = strtoupper(trim($status));

        return match ($status) {
            'CALIBRATED' => 'CALIBRATED',
            'PROVISIONAL' => 'PROVISIONAL',
            'MISSING' => 'MISSING',
            'BOOTSTRAP' => 'PROVISIONAL',
            'RETIRED' => 'PROVISIONAL',
            default => 'PROVISIONAL',
        };
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return 'en';
        }

        $locale = str_replace('_', '-', $locale);
        $parts = explode('-', $locale);
        if (count($parts) === 1) {
            if (strtolower($parts[0]) === 'zh') {
                return 'zh-CN';
            }

            return strtolower($parts[0]);
        }

        return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
    }

    private function normalizeRegion(string $region): string
    {
        $region = strtoupper(trim($region));
        if ($region === '') {
            return 'GLOBAL';
        }

        return str_replace('-', '_', $region);
    }

    private function resolveAgeBand(array $ctx): string
    {
        $ageBand = trim((string) ($ctx['age_band'] ?? ''));
        if ($ageBand !== '') {
            return $ageBand;
        }

        $default = (string) config('big5_norms.resolver.default_age_band', '18-60');
        $age = (int) ($ctx['age'] ?? 0);
        if ($age <= 0) {
            return $default;
        }

        $bands = (array) config('big5_norms.resolver.age_bands', []);
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

    private function parseAgeBand(string $ageBand): array
    {
        if (preg_match('/^(\d+)\-(\d+)$/', $ageBand, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [18, 60];
    }

    private function buildDbCandidates(string $locale, string $gender, string $ageBand): array
    {
        $chainKey = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
        $templates = (array) config("big5_norms.resolver.chains.{$chainKey}", []);

        $out = [];
        foreach ($templates as $template) {
            if (!is_string($template) || trim($template) === '') {
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

            $lowerGenderGroupId = strtr($template, [
                '{locale}' => $locale,
                '{gender}' => strtolower($gender),
                '{age_band}' => $ageBand,
            ]);
            $lowerGenderGroupId = trim($lowerGenderGroupId);
            if ($lowerGenderGroupId !== '') {
                $out[$lowerGenderGroupId] = true;
            }
        }

        return array_keys($out);
    }

    private function resolveCompiled(array $normsCompiled, array $ctx): array
    {
        $groups = is_array($normsCompiled['groups'] ?? null) ? $normsCompiled['groups'] : [];
        $groupLookup = [];
        foreach (array_keys($groups) as $groupId) {
            $groupLookup[strtolower((string) $groupId)] = (string) $groupId;
        }

        $locale = trim((string) ($ctx['locale'] ?? ''));
        $country = trim((string) ($ctx['country'] ?? ($ctx['region'] ?? '')));
        $ageBand = trim((string) ($ctx['age_band'] ?? 'all'));
        $gender = strtoupper(trim((string) ($ctx['gender'] ?? 'ALL')));
        if ($gender === '') {
            $gender = 'ALL';
        }

        $candidates = $this->buildCompiledCandidates($locale, $country, $ageBand, $gender);

        $domainGroupId = '';
        foreach ($candidates as $candidate) {
            $resolvedCandidate = $this->matchCompiledGroupId($candidate, $groupLookup);
            if ($resolvedCandidate === '' || !isset($groups[$resolvedCandidate]) || !is_array($groups[$resolvedCandidate])) {
                continue;
            }
            if ($this->hasCompiledDomainCoverage($groups[$resolvedCandidate])) {
                $domainGroupId = $resolvedCandidate;
                break;
            }
        }
        if ($domainGroupId === '' && isset($groups['global_all']) && $this->hasCompiledDomainCoverage($groups['global_all'])) {
            $domainGroupId = 'global_all';
        }

        $facetGroupId = '';
        if ($domainGroupId !== '' && $this->hasCompiledFacetCoverage($groups[$domainGroupId] ?? [])) {
            $facetGroupId = $domainGroupId;
        } elseif (isset($groups['global_all']) && $this->hasCompiledFacetCoverage($groups['global_all'])) {
            $facetGroupId = 'global_all';
        }

        $domains = $this->extractCompiledMetrics($groups[$domainGroupId] ?? [], 'domain');
        $facets = $this->extractCompiledMetrics($groups[$facetGroupId] ?? [], 'facet');

        $status = 'MISSING';
        if ($domains !== [] || $facets !== []) {
            $status = ($domainGroupId !== '' && $domainGroupId === $facetGroupId)
                ? 'CALIBRATED'
                : 'PROVISIONAL';
        }

        $normsVersion = '';
        $sourceId = '';
        foreach ($domains as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normsVersion = (string) ($row['norms_version'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');
            if ($normsVersion !== '' || $sourceId !== '') {
                break;
            }
        }

        return [
            'group_id' => $domainGroupId !== '' ? $domainGroupId : ($facetGroupId !== '' ? $facetGroupId : 'global_all'),
            'status' => $status,
            'domain_group_id' => $domainGroupId,
            'facet_group_id' => $facetGroupId,
            'norms_version' => $normsVersion,
            'source_id' => $sourceId,
            'source_type' => '',
            'domains' => $domains,
            'facets' => $facets,
        ];
    }

    private function buildCompiledCandidates(string $locale, string $country, string $ageBand, string $gender): array
    {
        $locale = str_replace('_', '-', trim($locale));
        $locale = $locale !== '' ? $locale : 'en';
        $localeUpper = str_replace('-', '_', strtoupper($locale));
        $ageBand = $ageBand !== '' ? $ageBand : 'all';
        $gender = $gender !== '' ? $gender : 'ALL';
        $country = strtoupper(str_replace('-', '_', $country));

        $candidates = [
            sprintf('%s_%s_%s', $locale, $gender, $ageBand),
            sprintf('%s_%s_all', $locale, $gender),
            sprintf('%s_all', $locale),
            sprintf('%s_%s_%s', $localeUpper, $gender, $ageBand),
            sprintf('%s_%s_all', $localeUpper, $gender),
            sprintf('%s_all', $localeUpper),
        ];

        if ($country !== '' && $country !== 'GLOBAL') {
            $candidates[] = sprintf('%s_%s_%s_%s', $country, $localeUpper, $gender, $ageBand);
            $candidates[] = sprintf('%s_%s_all', $country, $localeUpper);
        }

        $candidates[] = 'global_all';

        $unique = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $unique[$candidate] = true;
        }

        return array_keys($unique);
    }

    private function matchCompiledGroupId(string $candidate, array $groupLookup): string
    {
        $key = strtolower(trim($candidate));
        if ($key === '') {
            return '';
        }

        return (string) ($groupLookup[$key] ?? '');
    }

    private function hasCompiledDomainCoverage(array $group): bool
    {
        $domains = $this->extractCompiledMetrics($group, 'domain');
        $required = ['O', 'C', 'E', 'A', 'N'];
        foreach ($required as $code) {
            if (!isset($domains[$code])) {
                return false;
            }
            $sd = (float) ($domains[$code]['sd'] ?? 0.0);
            $sampleN = (int) ($domains[$code]['sample_n'] ?? 0);
            if ($sd <= 0.0 || $sampleN <= 0) {
                return false;
            }
        }

        return true;
    }

    private function hasCompiledFacetCoverage(array $group): bool
    {
        $facets = $this->extractCompiledMetrics($group, 'facet');

        return count($facets) === 30;
    }

    private function extractCompiledMetrics(array $group, string $level): array
    {
        $metrics = is_array($group['metrics'] ?? null) ? $group['metrics'] : [];
        $slice = is_array($metrics[$level] ?? null) ? $metrics[$level] : [];
        $out = [];
        foreach ($slice as $code => $row) {
            if (!is_array($row)) {
                continue;
            }
            $metricCode = strtoupper(trim((string) $code));
            if ($metricCode === '') {
                continue;
            }

            $out[$metricCode] = [
                'mean' => (float) ($row['mean'] ?? 0.0),
                'sd' => (float) ($row['sd'] ?? 0.0),
                'sample_n' => (int) ($row['sample_n'] ?? 0),
                'source_id' => (string) ($row['source_id'] ?? ''),
                'norms_version' => (string) ($row['norms_version'] ?? ''),
            ];
        }

        return $out;
    }
}
