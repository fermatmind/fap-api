<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

final class Eq60ScorerV1NormedValidity
{
    /**
     * @param  array<int|string,mixed>  $answersByQid
     * @param  array<int,array<string,mixed>>  $questionIndex
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    public function score(array $answersByQid, array $questionIndex, array $policy, array $ctx): array
    {
        $scoreMap = $this->normalizeScoreMap((array) ($ctx['score_map'] ?? []));
        if ($scoreMap === []) {
            $scoreMap = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
        }

        $normalizedAnswers = $this->normalizeAnswerMap($answersByQid);
        ksort($questionIndex, SORT_NUMERIC);

        $dimensionCodes = $this->normalizeDimensionCodes((array) ($policy['dimension_codes'] ?? []));
        if ($dimensionCodes === []) {
            $dimensionCodes = ['SA', 'ER', 'EM', 'RM'];
        }

        $resolvedByQid = [];
        $rawByQid = [];
        $dimRawSum = [];
        foreach ($dimensionCodes as $code) {
            $dimRawSum[$code] = 0;
        }

        foreach ($questionIndex as $qid => $meta) {
            $questionId = (int) $qid;
            if ($questionId <= 0 || ! is_array($meta)) {
                continue;
            }

            if (! array_key_exists($questionId, $normalizedAnswers)) {
                throw new \InvalidArgumentException('EQ_60 missing answer for question_id='.$questionId);
            }

            $rawValue = $this->resolveRawValue($normalizedAnswers[$questionId], $scoreMap);
            if ($rawValue < 1 || $rawValue > 5) {
                throw new \InvalidArgumentException('EQ_60 invalid answer for question_id='.$questionId);
            }

            $direction = (int) ($meta['direction'] ?? 0);
            if (! in_array($direction, [1, -1], true)) {
                throw new \InvalidArgumentException('EQ_60 invalid direction for question_id='.$questionId);
            }

            $dimension = strtoupper(trim((string) ($meta['dimension'] ?? '')));
            if (! in_array($dimension, $dimensionCodes, true)) {
                throw new \InvalidArgumentException('EQ_60 invalid dimension for question_id='.$questionId);
            }

            $resolved = $direction === -1 ? 6 - $rawValue : $rawValue;
            $rawByQid[$questionId] = $rawValue;
            $resolvedByQid[$questionId] = $resolved;
            $dimRawSum[$dimension] = (int) ($dimRawSum[$dimension] ?? 0) + $resolved;
        }

        $answerCount = count($resolvedByQid);
        if ($answerCount <= 0) {
            throw new \InvalidArgumentException('EQ_60 answer set is empty.');
        }

        $completionSeconds = $this->resolveCompletionSeconds($ctx);
        $quality = $this->buildQuality($rawByQid, $resolvedByQid, $completionSeconds, $policy);

        $scoreConfig = is_array($policy['std_score'] ?? null) ? $policy['std_score'] : [];
        $stdMean = (float) ($scoreConfig['mean'] ?? 100.0);
        $stdSd = (float) ($scoreConfig['sd'] ?? 15.0);
        if ($stdSd <= 0.0) {
            $stdSd = 15.0;
        }
        $stdClampMin = (float) ($scoreConfig['clamp_min'] ?? 55.0);
        $stdClampMax = (float) ($scoreConfig['clamp_max'] ?? 145.0);
        if ($stdClampMax <= $stdClampMin) {
            $stdClampMax = $stdClampMin + 1.0;
        }

        $bootstrap = is_array($policy['bootstrap_norms'] ?? null) ? $policy['bootstrap_norms'] : [];
        $muDim = (float) ($bootstrap['mu_dim'] ?? 53.5);
        $sigmaDim = (float) ($bootstrap['sigma_dim'] ?? 7.5);
        if ($sigmaDim <= 0.0) {
            $sigmaDim = 7.5;
        }

        $dimensionScores = [];
        $dimensionZ = [];
        $totalResolved = 0;
        foreach ($dimensionCodes as $dimensionCode) {
            $rawSum = (int) ($dimRawSum[$dimensionCode] ?? 0);
            $totalResolved += $rawSum;

            $z = ($rawSum - $muDim) / $sigmaDim;
            $dimensionZ[$dimensionCode] = $z;
            $stdScore = $this->clamp($stdMean + ($stdSd * $z), $stdClampMin, $stdClampMax);
            $percentile = $this->percentileFromZ($z);
            $dimensionScores[$dimensionCode] = [
                'raw_sum' => $rawSum,
                'raw_mean' => round($rawSum / 15.0, 4),
                'pomp' => round((($rawSum - 15.0) / 60.0) * 100.0, 2),
                'std_score' => round($stdScore, 2),
                'percentile' => $percentile,
                'level' => $this->resolveLevel($stdScore, $policy),
                'z' => round($z, 6),
            ];
        }

        $globalZ = $dimensionZ === [] ? 0.0 : array_sum($dimensionZ) / count($dimensionZ);
        $globalStd = $this->clamp($stdMean + ($stdSd * $globalZ), $stdClampMin, $stdClampMax);
        $globalPercentile = $this->percentileFromZ($globalZ);

        $scores = [
            'global' => [
                'raw_sum' => $totalResolved,
                'raw_mean' => round($totalResolved / 60.0, 4),
                'pomp' => round((($totalResolved - 60.0) / 240.0) * 100.0, 2),
                'std_score' => round($globalStd, 2),
                'percentile' => $globalPercentile,
                'level' => $this->resolveLevel($globalStd, $policy),
                'z' => round($globalZ, 6),
            ],
        ];
        foreach ($dimensionCodes as $dimensionCode) {
            $scores[$dimensionCode] = $dimensionScores[$dimensionCode] ?? [];
        }

        $norms = [
            'status' => strtoupper(trim((string) ($bootstrap['status'] ?? 'PROVISIONAL'))),
            'version' => trim((string) ($bootstrap['version'] ?? 'bootstrap_v1')),
            'group' => trim((string) ($bootstrap['group'] ?? 'locale_all_18-60')),
        ];
        if (! in_array($norms['status'], ['CALIBRATED', 'PROVISIONAL', 'MISSING'], true)) {
            $norms['status'] = 'PROVISIONAL';
        }

        $reportTags = $this->buildReportTags($scores, $policy, $quality);
        $policyHash = trim((string) ($ctx['policy_hash'] ?? ''));
        if ($policyHash === '') {
            $policyHash = hash(
                'sha256',
                (string) json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $engineVersion = trim((string) ($policy['engine_version'] ?? 'v1.0_normed_validity'));
        if ($engineVersion === '') {
            $engineVersion = 'v1.0_normed_validity';
        }
        $scoringSpecVersion = trim((string) ($ctx['scoring_spec_version'] ?? ($policy['scoring_spec_version'] ?? 'eq60_spec_2026_v2')));
        if ($scoringSpecVersion === '') {
            $scoringSpecVersion = 'eq60_spec_2026_v2';
        }

        return [
            'scale_code' => 'EQ_60',
            'engine_version' => $engineVersion,
            'version_snapshot' => [
                'pack_id' => trim((string) ($ctx['pack_id'] ?? 'EQ_60')),
                'pack_version' => trim((string) ($ctx['dir_version'] ?? 'v1')),
                'policy_version' => $scoringSpecVersion,
                'policy_hash' => $policyHash,
                'engine_version' => $engineVersion,
                'scoring_spec_version' => $scoringSpecVersion,
                'content_manifest_hash' => trim((string) ($ctx['content_manifest_hash'] ?? '')),
                'norms_version' => $norms['version'],
            ],
            'quality' => [
                'level' => $quality['level'],
                'flags' => $quality['flags'],
                'completion_time_seconds' => $completionSeconds,
                'metrics' => [
                    'completion_time_seconds' => $completionSeconds,
                    'longstring_max' => $quality['longstring_max'],
                    'extreme_rate' => $quality['extreme_rate'],
                    'neutral_rate' => $quality['neutral_rate'],
                    'inconsistency_index' => $quality['inconsistency_index'],
                ],
            ],
            'norms' => $norms,
            'scores' => $scores,
            'report_tags' => $reportTags,
        ];
    }

    /**
     * @param  array<int|string,mixed>  $raw
     * @return array<string,int>
     */
    private function normalizeScoreMap(array $raw): array
    {
        $map = [];
        foreach ($raw as $code => $value) {
            $key = strtoupper(trim((string) $code));
            if ($key === '') {
                continue;
            }
            $score = (int) $value;
            if ($score < 1 || $score > 5) {
                continue;
            }
            $map[$key] = $score;
        }

        return $map;
    }

    /**
     * @param  array<int|string,mixed>  $answers
     * @return array<int,mixed>
     */
    private function normalizeAnswerMap(array $answers): array
    {
        $out = [];

        if ($answers === []) {
            return $out;
        }

        $isList = array_keys($answers) === range(0, count($answers) - 1);
        if ($isList) {
            foreach ($answers as $answer) {
                if (! is_array($answer)) {
                    continue;
                }
                $qidRaw = trim((string) ($answer['question_id'] ?? ''));
                if ($qidRaw === '' || preg_match('/^\d+$/', $qidRaw) !== 1) {
                    continue;
                }
                $qid = (int) $qidRaw;
                if ($qid <= 0) {
                    continue;
                }

                $out[$qid] = $answer['code'] ?? ($answer['value'] ?? ($answer['answer'] ?? null));
            }

            ksort($out, SORT_NUMERIC);

            return $out;
        }

        foreach ($answers as $qidRaw => $value) {
            $key = trim((string) $qidRaw);
            if ($key === '' || preg_match('/^\d+$/', $key) !== 1) {
                continue;
            }
            $qid = (int) $key;
            if ($qid <= 0) {
                continue;
            }
            $out[$qid] = $value;
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * @param  array<string,int>  $scoreMap
     */
    private function resolveRawValue(mixed $value, array $scoreMap): int
    {
        if (is_array($value)) {
            $value = $value['code'] ?? ($value['value'] ?? ($value['answer'] ?? null));
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return 0;
            }

            $code = strtoupper($trimmed);
            if (array_key_exists($code, $scoreMap)) {
                return (int) $scoreMap[$code];
            }

            if (preg_match('/^-?\d+$/', $trimmed) === 1) {
                $value = (int) $trimmed;
            }
        }

        if (is_int($value) || is_float($value)) {
            $num = (int) $value;
            if ($num >= 1 && $num <= 5) {
                return $num;
            }
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $ctx
     */
    private function resolveCompletionSeconds(array $ctx): int
    {
        $server = (int) ($ctx['server_duration_seconds'] ?? 0);
        if ($server > 0) {
            return $server;
        }

        $startedAt = $this->toTimestamp($ctx['started_at'] ?? null);
        $submittedAt = $this->toTimestamp($ctx['submitted_at'] ?? null);
        if ($startedAt !== null && $submittedAt !== null && $submittedAt >= $startedAt) {
            return $submittedAt - $startedAt;
        }

        $durationMs = (int) ($ctx['duration_ms'] ?? 0);
        if ($durationMs > 0) {
            return max(0, (int) floor($durationMs / 1000));
        }

        return 0;
    }

    private function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $normalized) === 1) {
            $number = (int) $normalized;
            if ($number > 2_000_000_000_000 || $number > 2_000_000_000) {
                return (int) floor($number / 1000);
            }

            return $number;
        }

        $timestamp = strtotime($normalized);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @param  array<int,int>  $rawByQid
     * @param  array<int,int>  $resolvedByQid
     * @param  array<string,mixed>  $policy
     * @return array{
     *     level:string,
     *     flags:list<string>,
     *     longstring_max:int,
     *     extreme_rate:float,
     *     neutral_rate:float,
     *     inconsistency_index:int
     * }
     */
    private function buildQuality(array $rawByQid, array $resolvedByQid, int $completionSeconds, array $policy): array
    {
        ksort($rawByQid, SORT_NUMERIC);
        $answerCount = max(1, count($rawByQid));

        $longstringMax = $this->longstringMax($rawByQid);
        $extremeCount = 0;
        $neutralCount = 0;
        foreach ($rawByQid as $raw) {
            if ($raw === 1 || $raw === 5) {
                $extremeCount++;
            }
            if ($raw === 3) {
                $neutralCount++;
            }
        }
        $extremeRate = round($extremeCount / $answerCount, 4);
        $neutralRate = round($neutralCount / $answerCount, 4);
        $inconsistencyIndex = $this->inconsistencyIndex($resolvedByQid, (array) ($policy['inconsistency_pairs'] ?? []));

        $flags = [];
        $level = 'A';
        $validity = is_array($policy['validity_rules'] ?? null) ? $policy['validity_rules'] : [];

        $speeding = is_array($validity['speeding'] ?? null) ? $validity['speeding'] : [];
        $speedingC = (int) ($speeding['c_lt_seconds'] ?? 120);
        $speedingD = (int) ($speeding['d_lt_seconds'] ?? 75);
        if ($completionSeconds < $speedingC) {
            $flags[] = 'SPEEDING';
            $level = $this->degradeLevel($level, 'C');
        }
        if ($completionSeconds < $speedingD) {
            $level = $this->degradeLevel($level, 'D');
        }

        $longstring = is_array($validity['longstring'] ?? null) ? $validity['longstring'] : [];
        $longstringC = (int) ($longstring['c_gte'] ?? 25);
        $longstringD = (int) ($longstring['d_gte'] ?? 35);
        if ($longstringMax >= $longstringC) {
            $flags[] = 'LONGSTRING';
            $level = $this->degradeLevel($level, 'C');
        }
        if ($longstringMax >= $longstringD) {
            $level = $this->degradeLevel($level, 'D');
        }

        $extremeCfg = is_array($validity['extreme_rate'] ?? null) ? $validity['extreme_rate'] : [];
        $extremeC = (float) ($extremeCfg['c_gte'] ?? 0.85);
        if ($extremeRate >= $extremeC) {
            $flags[] = 'EXTREME_RESPONSE_BIAS';
            $level = $this->degradeLevel($level, 'C');
        }

        $neutralCfg = is_array($validity['neutral_rate'] ?? null) ? $validity['neutral_rate'] : [];
        $neutralC = (float) ($neutralCfg['c_gte'] ?? 0.70);
        if ($neutralRate >= $neutralC) {
            $flags[] = 'NEUTRAL_RESPONSE_BIAS';
            $level = $this->degradeLevel($level, 'C');
        }

        $inconsistencyCfg = is_array($validity['inconsistency'] ?? null) ? $validity['inconsistency'] : [];
        $inconsistencyC = (int) ($inconsistencyCfg['c_gte'] ?? 18);
        $inconsistencyD = (int) ($inconsistencyCfg['d_gte'] ?? 24);
        if ($inconsistencyIndex >= $inconsistencyC) {
            $flags[] = 'INCONSISTENT';
            $level = $this->degradeLevel($level, 'C');
        }
        if ($inconsistencyIndex >= $inconsistencyD) {
            $level = $this->degradeLevel($level, 'D');
        }

        $flags = array_values(array_unique(array_map(
            static fn ($flag): string => strtoupper(trim((string) $flag)),
            $flags
        )));

        return [
            'level' => $level,
            'flags' => $flags,
            'longstring_max' => $longstringMax,
            'extreme_rate' => $extremeRate,
            'neutral_rate' => $neutralRate,
            'inconsistency_index' => $inconsistencyIndex,
        ];
    }

    /**
     * @param  array<int,int>  $resolvedByQid
     * @param  array<int,mixed>  $pairs
     */
    private function inconsistencyIndex(array $resolvedByQid, array $pairs): int
    {
        $sum = 0;
        foreach ($pairs as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }
            $a = (int) ($pair[0] ?? 0);
            $b = (int) ($pair[1] ?? 0);
            if ($a <= 0 || $b <= 0) {
                continue;
            }
            if (! array_key_exists($a, $resolvedByQid) || ! array_key_exists($b, $resolvedByQid)) {
                continue;
            }
            $sum += abs((int) $resolvedByQid[$a] - (int) $resolvedByQid[$b]);
        }

        return $sum;
    }

    /**
     * @param  array<int,int>  $rawByQid
     */
    private function longstringMax(array $rawByQid): int
    {
        if ($rawByQid === []) {
            return 0;
        }

        ksort($rawByQid, SORT_NUMERIC);
        $max = 0;
        $current = 0;
        $last = null;
        foreach ($rawByQid as $value) {
            if ($last !== null && $last === $value) {
                $current++;
            } else {
                $current = 1;
                $last = $value;
            }
            if ($current > $max) {
                $max = $current;
            }
        }

        return $max;
    }

    private function degradeLevel(string $current, string $target): string
    {
        $rank = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4];
        $currentRank = (int) ($rank[strtoupper($current)] ?? 1);
        $targetRank = (int) ($rank[strtoupper($target)] ?? 1);

        return $targetRank > $currentRank ? strtoupper($target) : strtoupper($current);
    }

    /**
     * @param  array<int,mixed>  $codes
     * @return list<string>
     */
    private function normalizeDimensionCodes(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $value = strtoupper(trim((string) $code));
            if ($value === '') {
                continue;
            }
            if (! in_array($value, ['SA', 'ER', 'EM', 'RM'], true)) {
                continue;
            }
            $out[$value] = $value;
        }

        return array_values($out);
    }

    private function resolveLevel(float $stdScore, array $policy): string
    {
        $bands = is_array($policy['level_bands'] ?? null) ? $policy['level_bands'] : [];
        $baselineMax = (float) ($bands['baseline_max'] ?? 84.99);
        $developingMax = (float) ($bands['developing_max'] ?? 92.99);
        $competentMax = (float) ($bands['competent_max'] ?? 107.99);
        $proficientMax = (float) ($bands['proficient_max'] ?? 115.0);

        if ($stdScore <= $baselineMax) {
            return 'baseline';
        }
        if ($stdScore <= $developingMax) {
            return 'developing';
        }
        if ($stdScore <= $competentMax) {
            return 'competent';
        }
        if ($stdScore <= $proficientMax) {
            return 'proficient';
        }

        return 'exceptional';
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    private function percentileFromZ(float $z): float
    {
        $cdf = $this->normalCdf($z);

        return round($this->clamp($cdf * 100.0, 0.0, 100.0), 2);
    }

    private function normalCdf(float $z): float
    {
        $t = 1.0 / (1.0 + 0.2316419 * abs($z));
        $d = 0.3989423 * exp(-$z * $z / 2.0);
        $prob = 1.0 - $d * $t * (
            0.3193815
            + $t * (
                -0.3565638
                + $t * (
                    1.781478
                    + $t * (
                        -1.821256
                        + $t * 1.330274
                    )
                )
            )
        );
        if ($z < 0.0) {
            $prob = 1.0 - $prob;
        }

        return $this->clamp($prob, 0.0, 1.0);
    }

    /**
     * @param  array<string,mixed>  $scores
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $quality
     * @return list<string>
     */
    private function buildReportTags(array $scores, array $policy, array $quality): array
    {
        $tags = [];

        $rules = array_values(array_filter(
            (array) ($policy['cross_insight_rules'] ?? []),
            static fn ($rule): bool => is_array($rule)
        ));
        foreach ($rules as $rule) {
            $tag = trim((string) ($rule['tag'] ?? ''));
            $when = is_array($rule['when'] ?? null) ? $rule['when'] : [];
            if ($tag === '' || $when === []) {
                continue;
            }
            if ($this->matchCrossRule($scores, $when)) {
                $tags[] = $tag;
            }
        }

        $strengthMap = [
            'SA' => 'strength:high_self_awareness',
            'ER' => 'strength:high_emotion_regulation',
            'EM' => 'strength:high_empathy',
            'RM' => 'strength:high_relationship_management',
        ];
        foreach ($strengthMap as $dimension => $tag) {
            $stdScore = (float) data_get($scores, $dimension.'.std_score', 0.0);
            if ($stdScore > 115.0) {
                $tags[] = $tag;
            }
        }

        $erStdScore = (float) data_get($scores, 'ER.std_score', 0.0);
        if ($erStdScore <= 92.0) {
            $tags[] = 'focus:regulation_skills';
        }

        $qualityLevel = strtoupper(trim((string) ($quality['level'] ?? 'A')));
        if (in_array($qualityLevel, ['C', 'D'], true)) {
            $tags[] = 'data_quality:warning';
        }

        $tags = array_values(array_unique(array_map(
            static fn ($tag): string => trim((string) $tag),
            $tags
        )));
        $tags = array_values(array_filter($tags, static fn (string $tag): bool => $tag !== ''));

        return $tags;
    }

    /**
     * @param  array<string,mixed>  $scores
     * @param  array<string,mixed>  $when
     */
    private function matchCrossRule(array $scores, array $when): bool
    {
        foreach ($when as $key => $value) {
            $name = strtoupper(trim((string) $key));
            if ($name === '') {
                continue;
            }

            if (preg_match('/^(SA|ER|EM|RM)_(GTE|LTE)$/', $name, $m) !== 1) {
                return false;
            }
            $dimension = $m[1];
            $operator = $m[2];
            $threshold = (float) $value;
            $stdScore = (float) data_get($scores, $dimension.'.std_score', 0.0);
            if ($operator === 'GTE' && $stdScore < $threshold) {
                return false;
            }
            if ($operator === 'LTE' && $stdScore > $threshold) {
                return false;
            }
        }

        return true;
    }
}
