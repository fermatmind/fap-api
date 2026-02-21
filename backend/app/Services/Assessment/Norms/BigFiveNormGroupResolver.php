<?php

declare(strict_types=1);

namespace App\Services\Assessment\Norms;

final class BigFiveNormGroupResolver
{
    /**
     * @param array<string,mixed> $normsCompiled
     * @param array<string,mixed> $ctx
     * @return array{
     *   group_id:string,
     *   status:string,
     *   domain_group_id:string,
     *   facet_group_id:string,
     *   domains:array<string,array<string,mixed>>,
     *   facets:array<string,array<string,mixed>>
     * }
     */
    public function resolve(array $normsCompiled, array $ctx): array
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

        $candidates = $this->buildCandidates($locale, $country, $ageBand, $gender);

        $domainGroupId = '';
        foreach ($candidates as $candidate) {
            $resolvedCandidate = $this->matchGroupId($candidate, $groupLookup);
            if ($resolvedCandidate === '' || !isset($groups[$resolvedCandidate]) || !is_array($groups[$resolvedCandidate])) {
                continue;
            }
            if ($this->hasDomainCoverage($groups[$resolvedCandidate])) {
                $domainGroupId = $resolvedCandidate;
                break;
            }
        }
        if ($domainGroupId === '' && isset($groups['global_all']) && $this->hasDomainCoverage($groups['global_all'])) {
            $domainGroupId = 'global_all';
        }

        $facetGroupId = '';
        if ($domainGroupId !== '' && $this->hasFacetCoverage($groups[$domainGroupId] ?? [])) {
            $facetGroupId = $domainGroupId;
        } elseif (isset($groups['global_all']) && $this->hasFacetCoverage($groups['global_all'])) {
            $facetGroupId = 'global_all';
        }

        $domains = $this->extractMetrics($groups[$domainGroupId] ?? [], 'domain');
        $facets = $this->extractMetrics($groups[$facetGroupId] ?? [], 'facet');

        $status = 'MISSING';
        if ($domains !== [] || $facets !== []) {
            $status = ($domainGroupId !== '' && $domainGroupId === $facetGroupId)
                ? 'CALIBRATED'
                : 'PROVISIONAL';
        }

        return [
            'group_id' => $domainGroupId !== '' ? $domainGroupId : ($facetGroupId !== '' ? $facetGroupId : 'global_all'),
            'status' => $status,
            'domain_group_id' => $domainGroupId,
            'facet_group_id' => $facetGroupId,
            'domains' => $domains,
            'facets' => $facets,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildCandidates(string $locale, string $country, string $ageBand, string $gender): array
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

    /**
     * @param array<string,string> $groupLookup
     */
    private function matchGroupId(string $candidate, array $groupLookup): string
    {
        $key = strtolower(trim($candidate));
        if ($key === '') {
            return '';
        }

        return (string) ($groupLookup[$key] ?? '');
    }

    /**
     * @param array<string,mixed> $group
     */
    private function hasDomainCoverage(array $group): bool
    {
        $domains = $this->extractMetrics($group, 'domain');
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

    /**
     * @param array<string,mixed> $group
     */
    private function hasFacetCoverage(array $group): bool
    {
        $facets = $this->extractMetrics($group, 'facet');

        return count($facets) === 30;
    }

    /**
     * @param array<string,mixed> $group
     * @return array<string,array<string,mixed>>
     */
    private function extractMetrics(array $group, string $level): array
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
