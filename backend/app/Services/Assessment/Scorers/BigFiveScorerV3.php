<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

use App\Services\Assessment\Norms\BigFiveNormGroupResolver;
use App\Services\Psychometrics\Big5\Big5Standardizer;

final class BigFiveScorerV3
{
    private const FACET_ORDER_30 = [
        'N1', 'E1', 'O1', 'A1', 'C1',
        'N2', 'E2', 'O2', 'A2', 'C2',
        'N3', 'E3', 'O3', 'A3', 'C3',
        'N4', 'E4', 'O4', 'A4', 'C4',
        'N5', 'E5', 'O5', 'A5', 'C5',
        'N6', 'E6', 'O6', 'A6', 'C6',
    ];

    /**
     * @var array<string,string>
     */
    private const FACET_NAME_SLUG = [
        'N1' => 'anxiety',
        'N2' => 'anger',
        'N3' => 'depression',
        'N4' => 'self_consciousness',
        'N5' => 'immoderation',
        'N6' => 'vulnerability',
        'E1' => 'friendliness',
        'E2' => 'gregariousness',
        'E3' => 'assertiveness',
        'E4' => 'activity_level',
        'E5' => 'excitement_seeking',
        'E6' => 'cheerfulness',
        'O1' => 'imagination',
        'O2' => 'artistic_interests',
        'O3' => 'emotionality',
        'O4' => 'adventurousness',
        'O5' => 'intellect',
        'O6' => 'liberalism',
        'A1' => 'trust',
        'A2' => 'morality',
        'A3' => 'altruism',
        'A4' => 'cooperation',
        'A5' => 'modesty',
        'A6' => 'sympathy',
        'C1' => 'self_efficacy',
        'C2' => 'orderliness',
        'C3' => 'dutifulness',
        'C4' => 'achievement_striving',
        'C5' => 'self_discipline',
        'C6' => 'cautiousness',
    ];

    public function __construct(
        private readonly BigFiveNormGroupResolver $normResolver,
        private readonly Big5Standardizer $standardizer,
    ) {
    }

    /**
     * @param array<int,int> $answersByQuestionId
     * @param array<int,array<string,mixed>> $questionIndex
     * @param array<string,mixed> $normsCompiled
     * @param array<string,mixed> $policy
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    public function score(
        array $answersByQuestionId,
        array $questionIndex,
        array $normsCompiled,
        array $policy,
        array $ctx = []
    ): array {
        $facetValues = [];
        $rawSequence = [];

        for ($id = 1; $id <= 120; $id++) {
            if (!array_key_exists($id, $answersByQuestionId)) {
                throw new \InvalidArgumentException("missing answer: {$id}");
            }

            $value = (int) $answersByQuestionId[$id];
            if ($value < 1 || $value > 5) {
                throw new \InvalidArgumentException("invalid answer value: {$id}");
            }

            $meta = $questionIndex[$id] ?? null;
            if (!is_array($meta)) {
                throw new \InvalidArgumentException("question meta missing: {$id}");
            }

            $direction = (int) ($meta['direction'] ?? 1);
            if (!in_array($direction, [1, -1], true)) {
                throw new \InvalidArgumentException("question direction invalid: {$id}");
            }

            $facet = strtoupper(trim((string) ($meta['facet_code'] ?? '')));
            if ($facet === '') {
                throw new \InvalidArgumentException("facet missing: {$id}");
            }

            $keyedValue = $direction === -1 ? (6 - $value) : $value;
            $facetValues[$facet] = $facetValues[$facet] ?? [];
            $facetValues[$facet][] = $keyedValue;
            $rawSequence[] = $value;
        }

        $facetMeans = [];
        $facetSd = [];
        foreach (self::FACET_ORDER_30 as $facet) {
            $vals = $facetValues[$facet] ?? [];
            if (count($vals) !== 4) {
                throw new \InvalidArgumentException("facet item count invalid: {$facet}");
            }

            $mean = array_sum($vals) / 4.0;
            $sd = $this->populationSd($vals, $mean);
            $facetMeans[$facet] = round($mean, 2);
            $facetSd[$facet] = round($sd, 3);
        }

        $domainToFacets = $this->domainToFacets();
        $domainMeans = [];
        $domainSdOfFacets = [];
        foreach ($domainToFacets as $domain => $facets) {
            $vals = [];
            foreach ($facets as $facet) {
                $vals[] = (float) ($facetMeans[$facet] ?? 0.0);
            }
            $mean = count($vals) > 0 ? array_sum($vals) / count($vals) : 0.0;
            $domainMeans[$domain] = round($mean, 2);
            $domainSdOfFacets[$domain] = round($this->populationSd($vals, $mean), 3);
        }

        $normResolved = $this->normResolver->resolve($normsCompiled, [
            'locale' => (string) ($ctx['locale'] ?? ''),
            'country' => (string) ($ctx['country'] ?? ($ctx['region'] ?? '')),
            'age_band' => (string) ($ctx['age_band'] ?? 'all'),
            'gender' => (string) ($ctx['gender'] ?? 'ALL'),
        ]);

        $domainsPercentile = [];
        $facetsPercentile = [];
        $domainsZ = [];
        $facetsZ = [];
        $domainsT = [];
        $facetsT = [];

        $usedFallback = false;

        foreach (['O', 'C', 'E', 'A', 'N'] as $domain) {
            $normRow = $normResolved['domains'][$domain] ?? null;
            $normalized = $this->normalizeWithNorm(
                (float) ($domainMeans[$domain] ?? 0.0),
                is_array($normRow) ? $normRow : null,
                $policy
            );
            if (($normalized['fallback'] ?? false) === true) {
                $usedFallback = true;
            }

            $domainsPercentile[$domain] = $normalized['pct'];
            $domainsZ[$domain] = $normalized['z'];
            $domainsT[$domain] = $normalized['t'];
        }

        foreach (self::FACET_ORDER_30 as $facet) {
            $normRow = $normResolved['facets'][$facet] ?? null;
            $normalized = $this->normalizeWithNorm(
                (float) ($facetMeans[$facet] ?? 0.0),
                is_array($normRow) ? $normRow : null,
                $policy
            );
            if (($normalized['fallback'] ?? false) === true) {
                $usedFallback = true;
            }

            $facetsPercentile[$facet] = $normalized['pct'];
            $facetsZ[$facet] = $normalized['z'];
            $facetsT[$facet] = $normalized['t'];
        }

        $normStatus = strtoupper((string) ($normResolved['status'] ?? 'MISSING'));
        if ($normStatus === 'CALIBRATED' && $usedFallback) {
            $normStatus = 'PROVISIONAL';
        }
        if (!in_array($normStatus, ['CALIBRATED', 'PROVISIONAL', 'MISSING'], true)) {
            $normStatus = 'MISSING';
        }

        $domainBuckets = [];
        foreach ($domainsPercentile as $domain => $pct) {
            $domainBuckets[$domain] = $this->bucketFromPercentile((int) $pct, $policy);
        }

        $facetBuckets = [];
        foreach ($facetsPercentile as $facet => $pct) {
            $facetBuckets[$facet] = $this->bucketFromPercentile((int) $pct, $policy);
        }

        $topStrength = $this->sortFacetByPercentile($facetsPercentile, true);
        $topGrowth = $this->sortFacetByPercentile($facetsPercentile, false);

        $quality = $this->buildQuality($rawSequence, $facetValues, $facetMeans, $policy, $ctx);
        if ($normStatus === 'MISSING') {
            $flags = is_array($quality['flags'] ?? null) ? $quality['flags'] : [];
            $flags[] = 'NORM_MISSING';
            $quality['flags'] = array_values(array_unique(array_map(
                static fn ($v): string => trim((string) $v),
                $flags
            )));
        }
        $reportTone = in_array((string) ($quality['level'] ?? 'D'), ['A', 'B'], true)
            ? 'confident'
            : 'cautious';

        $facts = [
            'domain_buckets' => $domainBuckets,
            'facet_buckets' => $facetBuckets,
            'extreme_facets_high' => array_values(array_filter(self::FACET_ORDER_30, fn (string $facet): bool => (int) ($facetsPercentile[$facet] ?? 0) >= 90)),
            'extreme_facets_low' => array_values(array_filter(self::FACET_ORDER_30, fn (string $facet): bool => (int) ($facetsPercentile[$facet] ?? 0) <= 10)),
            'top_strength_facets' => array_slice($topStrength, 0, 3),
            'top_growth_facets' => array_slice($topGrowth, 0, 3),
            'top_growth_facets_text' => implode(', ', array_slice($topGrowth, 0, 3)),
            'report_tone' => $reportTone,
            'domain_sd_of_facets' => $domainSdOfFacets,
        ];

        $tags = $this->buildTags($domainBuckets, $facetBuckets, $facetsPercentile, $policy);

        $normSourceId = (string) ($normResolved['source_id'] ?? '');
        $normsVersion = (string) ($normResolved['norms_version'] ?? '');
        if ($normSourceId === '' || $normsVersion === '') {
            foreach ($normResolved['domains'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($normSourceId === '') {
                    $normSourceId = (string) ($row['source_id'] ?? '');
                }
                if ($normsVersion === '') {
                    $normsVersion = (string) ($row['norms_version'] ?? '');
                }
                if ($normSourceId !== '' && $normsVersion !== '') {
                    break;
                }
            }
        }

        return [
            'scale_code' => 'BIG5_OCEAN',
            'engine_version' => (string) ($policy['engine_version'] ?? 'big5_ipipneo120_v3.0.0'),
            'spec_version' => (string) ($policy['spec_version'] ?? 'big5_spec_2026Q1_v1'),
            'item_bank_version' => (string) ($policy['item_bank_version'] ?? 'big5_ipipneo120_bilingual_v1'),
            'raw_scores' => [
                'domains_mean' => $domainMeans,
                'facets_mean' => $facetMeans,
            ],
            'scores_0_100' => [
                'domains_percentile' => $domainsPercentile,
                'facets_percentile' => $facetsPercentile,
            ],
            'standardized_scores' => [
                'domains_z' => $domainsZ,
                'facets_z' => $facetsZ,
                'domains_t' => $domainsT,
                'facets_t' => $facetsT,
            ],
            'facts' => $facts,
            'tags' => $tags,
            'norms' => [
                'status' => $normStatus,
                'group_id' => (string) ($normResolved['group_id'] ?? 'global_all'),
                'norms_version' => $normsVersion,
                'source_id' => $normSourceId,
            ],
            'quality' => $quality,
        ];
    }

    /**
     * @param array<string,string> $domainBuckets
     * @param array<string,string> $facetBuckets
     * @param array<string,int> $facetsPercentile
     * @param array<string,mixed> $policy
     * @return list<string>
     */
    private function buildTags(array $domainBuckets, array $facetBuckets, array $facetsPercentile, array $policy): array
    {
        $tags = [];
        foreach (['O', 'C', 'E', 'A', 'N'] as $domain) {
            $bucket = strtolower((string) ($domainBuckets[$domain] ?? 'mid'));
            $tags[] = sprintf('big5:%s_%s', strtolower($domain), $bucket);
        }

        foreach (self::FACET_ORDER_30 as $facet) {
            $bucket = strtolower((string) ($facetBuckets[$facet] ?? 'mid'));
            $pct = (int) ($facetsPercentile[$facet] ?? 0);
            if ($pct >= 90) {
                $bucket = 'extreme_high';
            } elseif ($pct <= 10) {
                $bucket = 'extreme_low';
            }
            $slug = self::FACET_NAME_SLUG[$facet] ?? strtolower($facet);
            $tags[] = sprintf('facet:%s_%s_%s', strtolower($facet), $slug, $bucket);
        }

        $profileRules = is_array($policy['tags_rules']['profiles'] ?? null)
            ? $policy['tags_rules']['profiles']
            : [];

        foreach ($profileRules as $profileTag => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $ok = true;
            foreach ($rule as $domain => $allowedBuckets) {
                $domain = strtoupper(trim((string) $domain));
                if ($domain === '') {
                    continue;
                }

                $current = strtolower((string) ($domainBuckets[$domain] ?? 'mid'));
                $allowed = is_array($allowedBuckets)
                    ? array_map(static fn ($v): string => strtolower(trim((string) $v)), $allowedBuckets)
                    : [];

                if ($allowed === [] || !in_array($current, $allowed, true)) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                $tags[] = (string) $profileTag;
            }
        }

        $unique = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $unique[$tag] = true;
        }

        return array_keys($unique);
    }

    /**
     * @param list<float|int> $values
     */
    private function populationSd(array $values, float $mean): float
    {
        if ($values === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($values as $value) {
            $d = ((float) $value) - $mean;
            $sum += $d * $d;
        }

        return sqrt($sum / count($values));
    }

    /**
     * @param array<string,mixed>|null $normRow
     * @param array<string,mixed> $policy
     * @return array{pct:int,z:float,t:int,fallback:bool}
     */
    private function normalizeWithNorm(float $score, ?array $normRow, array $policy): array
    {
        if ($normRow !== null) {
            $mean = (float) ($normRow['mean'] ?? 0.0);
            $sd = (float) ($normRow['sd'] ?? 0.0);
            $sampleN = (int) ($normRow['sample_n'] ?? 0);
            if ($sd > 0.0 && $sampleN > 0) {
                $std = $this->standardizer->standardize($score, $mean, $sd);
                $pct = (int) ($std['pct'] ?? 50);
                $z = (float) ($std['z'] ?? 0.0);
                $t = (int) ($std['t'] ?? 50);

                return [
                    'pct' => max(0, min(100, $pct)),
                    'z' => $z,
                    't' => $t,
                    'fallback' => false,
                ];
            }
        }

        $fallback = is_array($policy['norm_fallback']['linear_from_raw_mean'] ?? null)
            ? $policy['norm_fallback']['linear_from_raw_mean']
            : [];
        $minMean = (float) ($fallback['min_mean'] ?? 1.0);
        $maxMean = (float) ($fallback['max_mean'] ?? 5.0);
        if ($maxMean <= $minMean) {
            $maxMean = $minMean + 1.0;
        }

        $ratio = ($score - $minMean) / ($maxMean - $minMean);
        $ratio = max(0.0, min(1.0, $ratio));
        $pct = (int) round($ratio * 100);

        return [
            'pct' => $pct,
            'z' => 0.0,
            't' => 50,
            'fallback' => true,
        ];
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function bucketFromPercentile(int $pct, array $policy): string
    {
        $pct = max(0, min(100, $pct));

        $bucketRanges = is_array($policy['percentile_buckets'] ?? null)
            ? $policy['percentile_buckets']
            : [];

        $lowMax = (int) (($bucketRanges['low'][1] ?? 30));
        $midMax = (int) (($bucketRanges['mid'][1] ?? 69));

        if ($pct <= $lowMax) {
            return 'low';
        }

        if ($pct <= $midMax) {
            return 'mid';
        }

        return 'high';
    }

    /**
     * @param array<string,int> $facetsPercentile
     * @return list<string>
     */
    private function sortFacetByPercentile(array $facetsPercentile, bool $desc): array
    {
        $facets = self::FACET_ORDER_30;

        usort($facets, function (string $a, string $b) use ($facetsPercentile, $desc): int {
            $pa = (int) ($facetsPercentile[$a] ?? 0);
            $pb = (int) ($facetsPercentile[$b] ?? 0);
            if ($pa === $pb) {
                $oa = array_search($a, self::FACET_ORDER_30, true);
                $ob = array_search($b, self::FACET_ORDER_30, true);

                return (int) $oa <=> (int) $ob;
            }

            return $desc ? ($pb <=> $pa) : ($pa <=> $pb);
        });

        return $facets;
    }

    /**
     * @param list<int> $rawSequence
     * @param array<string,list<int>> $facetValues
     * @param array<string,float> $facetMeans
     * @param array<string,mixed> $policy
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function buildQuality(
        array $rawSequence,
        array $facetValues,
        array $facetMeans,
        array $policy,
        array $ctx
    ): array {
        $answeredCount = count($rawSequence);
        $completionRate = $answeredCount > 0 ? $answeredCount / 120.0 : 0.0;

        $durationMs = (int) ($ctx['duration_ms'] ?? 0);
        $timeSecondsTotal = isset($ctx['time_seconds_total'])
            ? (float) $ctx['time_seconds_total']
            : ($durationMs > 0 ? round($durationMs / 1000.0, 3) : 0.0);
        $timePerItemAvg = $answeredCount > 0 ? ($timeSecondsTotal / 120.0) : 0.0;

        $neutralCount = 0;
        $extremeCount = 0;
        foreach ($rawSequence as $value) {
            if ($value === 3) {
                $neutralCount++;
            }
            if ($value === 1 || $value === 5) {
                $extremeCount++;
            }
        }

        $neutralRate = $answeredCount > 0 ? ($neutralCount / 120.0) : 0.0;
        $extremeRate = $answeredCount > 0 ? ($extremeCount / 120.0) : 0.0;

        $longstringMax = 0;
        $currLen = 0;
        $prev = null;
        foreach ($rawSequence as $value) {
            if ($prev === null || $value !== $prev) {
                $currLen = 1;
                $prev = $value;
            } else {
                $currLen++;
            }
            if ($currLen > $longstringMax) {
                $longstringMax = $currLen;
            }
        }

        $madList = [];
        foreach (self::FACET_ORDER_30 as $facet) {
            $vals = $facetValues[$facet] ?? [];
            $mean = (float) ($facetMeans[$facet] ?? 0.0);
            if ($vals === []) {
                continue;
            }
            $sumAbs = 0.0;
            foreach ($vals as $val) {
                $sumAbs += abs(((float) $val) - $mean);
            }
            $madList[] = $sumAbs / count($vals);
        }
        $facetInconsistency = $madList !== [] ? array_sum($madList) / count($madList) : 0.0;

        $rawMean = $rawSequence !== [] ? array_sum($rawSequence) / count($rawSequence) : 3.0;
        $acquiescence = $rawMean - 3.0;

        $metrics = [
            'completion_rate' => round($completionRate, 4),
            'time_seconds_total' => round($timeSecondsTotal, 3),
            'time_per_item_avg' => round($timePerItemAvg, 3),
            'neutral_rate' => round($neutralRate, 4),
            'extreme_rate' => round($extremeRate, 4),
            'longstring_max' => $longstringMax,
            'facet_inconsistency_mean' => round($facetInconsistency, 4),
            'acquiescence_index' => round($acquiescence, 4),
        ];

        $checks = is_array($policy['validity_checks'] ?? null) ? $policy['validity_checks'] : [];
        $gradeA = is_array($checks['grade_A'] ?? null) ? $checks['grade_A'] : [];
        $gradeB = is_array($checks['grade_B'] ?? null) ? $checks['grade_B'] : [];
        $gradeC = is_array($checks['grade_C'] ?? null) ? $checks['grade_C'] : [];

        $level = 'D';
        if ($this->matchQualityGrade($metrics, $gradeA)) {
            $level = 'A';
        } elseif ($this->matchQualityGrade($metrics, $gradeB)) {
            $level = 'B';
        } elseif ($this->matchQualityGrade($metrics, $gradeC)) {
            $level = 'C';
        }

        $flags = [];
        if ($metrics['time_per_item_avg'] < 0.8) {
            $flags[] = 'SPEEDING';
        }
        if ($metrics['longstring_max'] > 30) {
            $flags[] = 'STRAIGHTLINING';
        }
        if ($metrics['facet_inconsistency_mean'] > 1.3) {
            $flags[] = 'INCONSISTENT';
        }
        if ($metrics['extreme_rate'] > 0.8) {
            $flags[] = 'EXTREME_RESPONDING';
        }
        if ($metrics['neutral_rate'] > 0.6) {
            $flags[] = 'NEUTRAL_OVERUSE';
        }

        return [
            'level' => $level,
            'metrics' => $metrics,
            'flags' => array_values(array_unique($flags)),
        ];
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $grade
     */
    private function matchQualityGrade(array $metrics, array $grade): bool
    {
        $requiredCompletion = (float) ($grade['completion_rate'] ?? 1.0);
        $minTime = (float) ($grade['time_per_item_avg_min'] ?? 0.0);
        $maxLongstring = (int) ($grade['longstring_max_max'] ?? PHP_INT_MAX);
        $maxInconsistency = (float) ($grade['facet_inconsistency_mean_max'] ?? INF);

        return (float) $metrics['completion_rate'] >= $requiredCompletion
            && (float) $metrics['time_per_item_avg'] >= $minTime
            && (int) $metrics['longstring_max'] <= $maxLongstring
            && (float) $metrics['facet_inconsistency_mean'] <= $maxInconsistency;
    }

    /**
     * @return array<string,list<string>>
     */
    private function domainToFacets(): array
    {
        $out = [
            'O' => [],
            'C' => [],
            'E' => [],
            'A' => [],
            'N' => [],
        ];

        foreach (self::FACET_ORDER_30 as $facet) {
            $domain = $facet[0];
            $out[$domain][] = $facet;
        }

        return $out;
    }
}
