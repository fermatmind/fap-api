<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

final class ClinicalCombo68ScorerV1
{
    /**
     * @param array<int|string,mixed> $answersByQuestion
     * @param array<int,array{module_code:string,options_set_code:string,is_reverse:bool}> $questionIndex
     * @param array<string,array<string,mixed>> $optionSets
     * @param array<string,mixed> $policy
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    public function score(
        array $answersByQuestion,
        array $questionIndex,
        array $optionSets,
        array $policy,
        array $ctx = []
    ): array {
        $x = [];
        $rawCodes = [];

        for ($qid = 1; $qid <= 68; $qid++) {
            $meta = $questionIndex[$qid] ?? null;
            if (!is_array($meta)) {
                throw new \InvalidArgumentException('question meta missing: '.$qid);
            }

            $setCode = trim((string) ($meta['options_set_code'] ?? ''));
            $set = $optionSets[$setCode] ?? null;
            if (!is_array($set)) {
                throw new \InvalidArgumentException('option set missing: '.$setCode);
            }

            $scoringMap = is_array($set['scoring'] ?? null) ? $set['scoring'] : [];
            if ($scoringMap === []) {
                throw new \InvalidArgumentException('scoring map missing: '.$setCode);
            }

            $raw = $answersByQuestion[$qid] ?? null;
            if ($raw === null) {
                throw new \InvalidArgumentException('missing answer: '.$qid);
            }

            $code = $this->normalizeAnswerCode($raw, $scoringMap);
            if ($code === null) {
                throw new \InvalidArgumentException('invalid answer code: '.$qid);
            }

            $rawCodes[$qid] = $code;
            $numeric = (int) ($scoringMap[$code] ?? -9999);
            if (!array_key_exists($code, $scoringMap)) {
                throw new \InvalidArgumentException('invalid scoring option: '.$qid);
            }

            if ((bool) ($meta['is_reverse'] ?? false) === true) {
                $numeric = $this->reverseScore($numeric, $scoringMap);
            }

            $x[$qid] = $numeric;
        }

        $completionSeconds = $this->resolveCompletionSeconds($ctx);

        $qualityRules = is_array($policy['quality_rules'] ?? null) ? $policy['quality_rules'] : [];
        $crisisRules = is_array($policy['crisis_rules'] ?? null) ? $policy['crisis_rules'] : [];
        $scoringRules = is_array($policy['scoring_rules'] ?? null) ? $policy['scoring_rules'] : [];

        $neutralRate = $this->ratio($rawCodes, static fn (string $c): bool => $c === 'C');
        $extremeRate = $this->ratio($rawCodes, static fn (string $c): bool => $c === 'A' || $c === 'E');
        $longstringMax = $this->longstringMax($rawCodes);

        $flags = [];
        $speedingMin = (int) ($qualityRules['speeding_min_seconds'] ?? 100);
        $straightliningMin = (int) ($qualityRules['straightlining_min_run'] ?? 15);
        $extremeRateMin = (float) ($qualityRules['extreme_rate_min'] ?? 0.88);
        $neutralRateMin = (float) ($qualityRules['neutral_rate_min'] ?? 0.60);

        if ($completionSeconds < $speedingMin) {
            $flags[] = 'SPEEDING';
        }
        if ($longstringMax >= $straightliningMin) {
            $flags[] = 'STRAIGHTLINING';
        }
        if ($extremeRate >= $extremeRateMin) {
            $flags[] = 'EXTREME_RESPONDING';
        }
        if ($neutralRate >= $neutralRateMin) {
            $flags[] = 'NEUTRAL_OVERUSE';
        }

        $pair = is_array($qualityRules['inconsistency_pair'] ?? null) ? $qualityRules['inconsistency_pair'] : [];
        $q17Min = (int) ($pair['q17_min'] ?? 3);
        $q18RevMin = (int) ($pair['q18_reversed_min'] ?? 3);
        $inconsistencyFlag = $x[17] >= $q17Min && $x[18] >= $q18RevMin;
        if ($inconsistencyFlag) {
            $flags[] = 'INCONSISTENT';
        }

        $qualityLevel = 'A';
        if (in_array('STRAIGHTLINING', $flags, true)) {
            $qualityLevel = 'D';
        } elseif (in_array('SPEEDING', $flags, true) || in_array('EXTREME_RESPONDING', $flags, true) || in_array('NEUTRAL_OVERUSE', $flags, true)) {
            $qualityLevel = 'C';
        } elseif ($inconsistencyFlag) {
            $qualityLevel = 'B';
        }

        $crisisReasons = [];
        $crisisTriggeredBy = [];
        $q9Min = (int) ($crisisRules['q9_min'] ?? 2);
        $q68Min = (int) ($crisisRules['q68_min'] ?? 3);
        $reasonMap = is_array($crisisRules['reason_map'] ?? null) ? $crisisRules['reason_map'] : [];

        if ($x[9] >= $q9Min) {
            $crisisReasons[] = (string) ($reasonMap['q9'] ?? 'SUICIDAL_IDEATION');
            $crisisTriggeredBy[] = 9;
        }
        if ($x[68] >= $q68Min) {
            $crisisReasons[] = (string) ($reasonMap['q68'] ?? 'FUNCTION_IMPAIRMENT');
            $crisisTriggeredBy[] = 68;
        }

        $crisisReasons = array_values(array_unique(array_filter(array_map('strval', $crisisReasons))));
        $crisisTriggeredBy = array_values(array_unique(array_map('intval', $crisisTriggeredBy)));
        sort($crisisTriggeredBy);

        $rawDep = $this->sumRange($x, 1, 9);
        $rawAnx = $this->sumRange($x, 10, 16);
        $rawStr = $x[17] + $x[18] + $x[19] + $x[20];
        $rawRes = $this->sumRange($x, 21, 30);
        $rawPerf = $this->sumRange($x, 31, 57);
        $rawOcd = $this->sumRange($x, 58, 67);

        $subScores = [
            'PE_parental' => $this->sumIds($x, [31, 37, 44, 49, 57]),
            'ORG_order' => $this->sumIds($x, [32, 34, 35, 50, 52, 54]),
            'PS_standards' => $this->sumIds($x, [33, 38, 43, 47, 53]),
            'CM_mistakes' => $this->sumIds($x, [36, 39, 40, 42, 45, 46, 48]),
            'DA_doubts' => $this->sumIds($x, [41, 51, 55, 56]),
        ];

        $depressionFlags = [];
        $coreDep = max($x[1], $x[2]);
        if ($rawDep >= 14 && $coreDep <= 1) {
            $depressionFlags[] = 'masked_depression';
        }

        $muSigma = is_array($scoringRules['mu_sigma'] ?? null) ? $scoringRules['mu_sigma'] : [];
        $clamp = is_array($scoringRules['t_score_clamp'] ?? null) ? $scoringRules['t_score_clamp'] : [];
        $buckets = is_array($scoringRules['buckets'] ?? null) ? $scoringRules['buckets'] : [];

        $depT = $this->tScore($rawDep, (array) ($muSigma['depression'] ?? []), $clamp);
        $anxT = $this->tScore($rawAnx, (array) ($muSigma['anxiety'] ?? []), $clamp);
        $strT = $this->tScore($rawStr, (array) ($muSigma['stress'] ?? []), $clamp);
        $resT = $this->tScore($rawRes, (array) ($muSigma['resilience'] ?? []), $clamp);
        $perfT = $this->tScore($rawPerf, (array) ($muSigma['perfectionism'] ?? []), $clamp);
        $ocdT = $this->tScore($rawOcd, (array) ($muSigma['ocd'] ?? []), $clamp);

        $depLevel = $this->levelFromT($depT, (array) ($buckets['depression'] ?? []), 'normal');
        $anxLevel = $this->levelFromT($anxT, (array) ($buckets['anxiety'] ?? []), 'normal');
        $strLevel = $this->levelFromT($strT, (array) ($buckets['stress'] ?? []), 'low');
        $resLevel = $this->levelFromT($resT, (array) ($buckets['resilience'] ?? []), 'average');
        $perfLevel = $this->levelFromT($perfT, (array) ($buckets['perfectionism'] ?? []), 'balanced');
        $ocdLevel = $this->levelFromT($ocdT, (array) ($buckets['ocd'] ?? []), 'subclinical');

        $reportTags = [];
        if ($crisisReasons !== []) {
            $reportTags[] = 'crisis:alert';
        }

        $severity = [
            'normal' => 0,
            'mild' => 1,
            'moderate' => 2,
            'severe' => 3,
        ];
        $depSeverity = (int) ($severity[$depLevel] ?? 0);
        $anxSeverity = (int) ($severity[$anxLevel] ?? 0);
        if ($depSeverity >= 1 && $anxSeverity >= 1) {
            $reportTags[] = 'symptom:mild_anxiety_depression';
        }
        if ($depSeverity >= 3 || $anxSeverity >= 3) {
            $reportTags[] = 'symptom:severe_risk';
        }

        if (in_array($resLevel, ['strong', 'very_strong'], true)) {
            $reportTags[] = 'strength:high_resilience';
        }
        if (in_array($resLevel, ['fragile', 'low'], true)) {
            $reportTags[] = 'risk:low_resilience';
        }

        $dominantTrait = $this->dominantTraitTag($subScores, (array) data_get($policy, 'report_tags.dominant_trait_tie_order', []));
        if ($dominantTrait !== null) {
            $reportTags[] = $dominantTrait;
        }

        $reportTags = array_values(array_unique($reportTags));

        $engineVersion = trim((string) ($policy['engine_version'] ?? 'v1.0_2026'));
        if ($engineVersion === '') {
            $engineVersion = 'v1.0_2026';
        }

        $versionSnapshot = [
            'pack_id' => (string) ($ctx['pack_id'] ?? 'CLINICAL_COMBO_68'),
            'pack_version' => (string) ($ctx['dir_version'] ?? 'v1'),
            'policy_version' => (string) ($policy['policy_version'] ?? ''),
            'engine_version' => $engineVersion,
            'scoring_spec_version' => (string) ($policy['scoring_spec_version'] ?? 'v1.0_2026'),
            'content_manifest_hash' => (string) ($ctx['content_manifest_hash'] ?? ''),
        ];

        $functionImpairmentRaw = $x[68];
        $functionImpairmentLevel = $this->functionImpairmentLevel($functionImpairmentRaw);

        return [
            'scale_code' => 'CLINICAL_COMBO_68',
            'engine_version' => $engineVersion,
            'quality' => [
                'level' => $qualityLevel,
                'crisis_alert' => $crisisReasons !== [],
                'crisis_reasons' => $crisisReasons,
                'crisis_triggered_by' => $crisisTriggeredBy,
                'inconsistency_flag' => $inconsistencyFlag,
                'completion_time_seconds' => $completionSeconds,
                'metrics' => [
                    'neutral_rate' => $neutralRate,
                    'extreme_rate' => $extremeRate,
                    'longstring_max' => $longstringMax,
                ],
                'flags' => array_values(array_unique($flags)),
            ],
            'scores' => [
                'depression' => [
                    'raw' => $rawDep,
                    't_score' => $depT,
                    'level' => $depLevel,
                    'flags' => $depressionFlags,
                ],
                'anxiety' => [
                    'raw' => $rawAnx,
                    't_score' => $anxT,
                    'level' => $anxLevel,
                ],
                'stress' => [
                    'raw' => $rawStr,
                    't_score' => $strT,
                    'level' => $strLevel,
                ],
                'resilience' => [
                    'raw' => $rawRes,
                    't_score' => $resT,
                    'level' => $resLevel,
                ],
                'perfectionism' => [
                    'raw' => $rawPerf,
                    't_score' => $perfT,
                    'level' => $perfLevel,
                    'sub_scores' => $subScores,
                ],
                'ocd' => [
                    'raw' => $rawOcd,
                    't_score' => $ocdT,
                    'level' => $ocdLevel,
                ],
            ],
            'facts' => [
                'function_impairment_raw' => $functionImpairmentRaw,
                'function_impairment_level' => $functionImpairmentLevel,
            ],
            'report_tags' => $reportTags,
            'version_snapshot' => $versionSnapshot,
        ];
    }

    /**
     * @param array<string,mixed> $scoreMap
     */
    private function normalizeAnswerCode(mixed $raw, array $scoreMap): ?string
    {
        if (is_array($raw)) {
            $raw = $raw['code'] ?? ($raw['value'] ?? ($raw['answer'] ?? null));
        }

        if (is_string($raw)) {
            $value = strtoupper(trim($raw));
            if (in_array($value, ['A', 'B', 'C', 'D', 'E'], true)) {
                return $value;
            }
            if (preg_match('/^-?\d+$/', $value) === 1) {
                $raw = (int) $value;
            }
        }

        if (is_int($raw) || is_float($raw)) {
            $n = (int) $raw;
            foreach ($scoreMap as $code => $score) {
                if ((int) $score === $n) {
                    $upper = strtoupper(trim((string) $code));
                    if (in_array($upper, ['A', 'B', 'C', 'D', 'E'], true)) {
                        return $upper;
                    }
                }
            }

            if ($n >= 0 && $n <= 4) {
                return ['A', 'B', 'C', 'D', 'E'][$n];
            }
            if ($n >= 1 && $n <= 5) {
                return ['A', 'B', 'C', 'D', 'E'][$n - 1];
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $scoreMap
     */
    private function reverseScore(int $numeric, array $scoreMap): int
    {
        $values = array_map('intval', array_values($scoreMap));
        $min = min($values);
        $max = max($values);

        return $max - ($numeric - $min);
    }

    /**
     * @param array<int,string> $rawCodes
     */
    private function ratio(array $rawCodes, callable $predicate): float
    {
        $total = count($rawCodes);
        if ($total <= 0) {
            return 0.0;
        }

        $hit = 0;
        foreach ($rawCodes as $code) {
            if ($predicate($code)) {
                $hit++;
            }
        }

        return round($hit / $total, 4);
    }

    /**
     * @param array<int,string> $rawCodes
     */
    private function longstringMax(array $rawCodes): int
    {
        if ($rawCodes === []) {
            return 0;
        }

        ksort($rawCodes, SORT_NUMERIC);
        $max = 0;
        $current = 0;
        $last = null;

        foreach ($rawCodes as $code) {
            if ($last !== null && $code === $last) {
                $current++;
            } else {
                $current = 1;
                $last = $code;
            }
            if ($current > $max) {
                $max = $current;
            }
        }

        return $max;
    }

    /**
     * @param array<int,int> $x
     */
    private function sumRange(array $x, int $from, int $to): int
    {
        $sum = 0;
        for ($i = $from; $i <= $to; $i++) {
            $sum += (int) ($x[$i] ?? 0);
        }

        return $sum;
    }

    /**
     * @param array<int,int> $x
     * @param list<int> $ids
     */
    private function sumIds(array $x, array $ids): int
    {
        $sum = 0;
        foreach ($ids as $id) {
            $sum += (int) ($x[$id] ?? 0);
        }

        return $sum;
    }

    /**
     * @param array<string,mixed> $muSigma
     * @param array<string,mixed> $clamp
     */
    private function tScore(int $raw, array $muSigma, array $clamp): int
    {
        $mu = (float) ($muSigma['mu'] ?? 0.0);
        $sigma = (float) ($muSigma['sigma'] ?? 1.0);
        if ($sigma <= 0.0) {
            $sigma = 1.0;
        }

        $t = (int) round(50 + 10 * (($raw - $mu) / $sigma));
        $min = (int) ($clamp['min'] ?? 20);
        $max = (int) ($clamp['max'] ?? 80);

        if ($t < $min) {
            $t = $min;
        }
        if ($t > $max) {
            $t = $max;
        }

        return $t;
    }

    /**
     * @param list<array<string,mixed>> $rules
     */
    private function levelFromT(int $t, array $rules, string $fallback): string
    {
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $min = array_key_exists('min_t', $rule) ? (int) $rule['min_t'] : 20;
            $max = array_key_exists('max_t', $rule) ? (int) $rule['max_t'] : 80;
            if ($t < $min || $t > $max) {
                continue;
            }

            $level = trim((string) ($rule['level'] ?? ''));
            if ($level !== '') {
                return $level;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string,int> $subScores
     * @param list<string> $tieOrder
     */
    private function dominantTraitTag(array $subScores, array $tieOrder): ?string
    {
        if ($subScores === []) {
            return null;
        }

        $labels = [
            'PE' => 'trait:parental_expectation_dominant',
            'ORG' => 'trait:orderliness_dominant',
            'PS' => 'trait:high_standards_dominant',
            'CM' => 'trait:mistake_concern_dominant',
            'DA' => 'trait:doubt_checking_dominant',
        ];

        $values = [
            'PE' => (int) ($subScores['PE_parental'] ?? 0),
            'ORG' => (int) ($subScores['ORG_order'] ?? 0),
            'PS' => (int) ($subScores['PS_standards'] ?? 0),
            'CM' => (int) ($subScores['CM_mistakes'] ?? 0),
            'DA' => (int) ($subScores['DA_doubts'] ?? 0),
        ];

        $max = max($values);
        $candidates = array_keys(array_filter($values, static fn (int $v): bool => $v === $max));

        $priority = $tieOrder !== [] ? $tieOrder : ['CM', 'PS', 'ORG', 'PE', 'DA'];
        foreach ($priority as $key) {
            $key = strtoupper(trim((string) $key));
            if (in_array($key, $candidates, true)) {
                return $labels[$key] ?? null;
            }
        }

        $first = $candidates[0] ?? null;

        return $first !== null ? ($labels[$first] ?? null) : null;
    }

    private function functionImpairmentLevel(int $raw): string
    {
        return match (true) {
            $raw <= 0 => 'none',
            $raw === 1 => 'mild',
            $raw === 2 => 'moderate',
            $raw === 3 => 'severe',
            default => 'extreme',
        };
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function resolveCompletionSeconds(array $ctx): int
    {
        $startedAt = $ctx['started_at'] ?? null;
        $submittedAt = $ctx['submitted_at'] ?? null;

        $startTs = $this->toTimestamp($startedAt);
        $submitTs = $this->toTimestamp($submittedAt);

        if ($startTs !== null && $submitTs !== null && $submitTs >= $startTs) {
            return max(1, $submitTs - $startTs);
        }

        return 1;
    }

    private function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value)) {
            $ts = strtotime($value);

            return $ts === false ? null : $ts;
        }

        return null;
    }
}
