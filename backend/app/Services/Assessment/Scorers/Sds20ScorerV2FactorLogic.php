<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

use App\Services\Assessment\Norms\SdsNormGroupResolver;

final class Sds20ScorerV2FactorLogic
{
    private const QUESTION_COUNT = 20;

    /**
     * @var list<string>
     */
    private const ALLOWED_CODES = ['A', 'B', 'C', 'D'];

    public function __construct(
        private readonly SdsNormGroupResolver $normResolver,
    ) {}

    /**
     * @param  array<int|string,mixed>  $answersByQuestionId
     * @param  array<int,array<string,mixed>>  $questionIndex
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    public function score(
        array $answersByQuestionId,
        array $questionIndex,
        array $policy,
        array $ctx = []
    ): array {
        $answers = $this->normalizeAnswerMap($answersByQuestionId);
        $baseMap = $this->resolveBaseMap($policy);

        $itemScores = [];
        $rawCodes = [];

        for ($qid = 1; $qid <= self::QUESTION_COUNT; $qid++) {
            if (! array_key_exists($qid, $answers)) {
                throw new \InvalidArgumentException('INVALID_ANSWER_SET: missing question_id='.$qid);
            }

            $code = strtoupper(trim((string) $answers[$qid]));
            if (! in_array($code, self::ALLOWED_CODES, true)) {
                throw new \InvalidArgumentException('INVALID_ANSWER_SET: invalid code for question_id='.$qid);
            }

            $direction = (int) data_get($questionIndex, $qid.'.direction', 0);
            if (! in_array($direction, [1, -1], true)) {
                throw new \InvalidArgumentException('INVALID_ANSWER_SET: invalid direction for question_id='.$qid);
            }

            $base = (int) ($baseMap[$code] ?? 0);
            if ($base <= 0) {
                throw new \InvalidArgumentException('INVALID_ANSWER_SET: base map missing for code='.$code);
            }

            $itemScores[$qid] = $direction === 1 ? $base : (5 - $base);
            $rawCodes[$qid] = $code;
        }

        ksort($itemScores);

        $completionSeconds = $this->resolveCompletionSeconds($ctx);
        $quality = $this->buildQuality($rawCodes, $completionSeconds, $policy);

        $crisisQuestionId = (int) data_get($policy, 'crisis_rules.item_id', 19);
        $crisisThreshold = (int) data_get($policy, 'crisis_rules.mapped_score_gte', 3);
        $crisisAlert = ((int) ($itemScores[$crisisQuestionId] ?? 0)) >= $crisisThreshold;

        $reportTags = [];
        if ($crisisAlert) {
            $crisisTag = trim((string) data_get($policy, 'crisis_rules.tag', 'crisis:self_harm_ideation'));
            if ($crisisTag !== '') {
                $reportTags[] = $crisisTag;
            }
            $quality['flags'][] = 'CRISIS_Q19';
            $quality['flags'] = array_values(array_unique($quality['flags']));
        }

        $rawTotal = array_sum($itemScores);
        $indexScore = $this->computeIndexScore($rawTotal, $policy);
        $clinicalLevel = $this->resolveClinicalLevel($indexScore, $policy);
        $policyHash = $this->hashPolicy($policy);
        $norms = $this->buildNormsPayload($this->normResolver->resolve([
            'locale' => (string) ($ctx['locale'] ?? ''),
            'region' => (string) ($ctx['region'] ?? ($ctx['country'] ?? '')),
            'country' => (string) ($ctx['country'] ?? ($ctx['region'] ?? '')),
            'gender' => (string) ($ctx['gender'] ?? 'ALL'),
            'age_band' => (string) ($ctx['age_band'] ?? ''),
            'age' => isset($ctx['age']) ? (int) $ctx['age'] : 0,
        ]));
        $percentile = $this->resolvePercentile($indexScore, $norms);

        $factors = $this->buildFactors($itemScores, $policy);

        $gateIndexMin = (int) data_get($policy, 'somatic_exhaustion_mask_gate.index_score_gte', 53);
        $coreItems = array_values(array_filter(
            array_map('intval', (array) data_get($policy, 'somatic_exhaustion_mask_gate.core_items', [1, 20])),
            static fn (int $qid): bool => $qid > 0
        ));
        $coreMax = (int) data_get($policy, 'somatic_exhaustion_mask_gate.core_items_mapped_score_lte', 2);

        if (
            $indexScore >= $gateIndexMin
            && $coreItems !== []
            && $this->allCoreScoresAtMost($itemScores, $coreItems, $coreMax)
        ) {
            $maskTags = (array) data_get($policy, 'somatic_exhaustion_mask_gate.tags', [
                'profile:somatic_exhaustion_mask',
                'symptom:physical_drain',
                'recommendation:rest_and_recovery',
            ]);
            foreach ($maskTags as $tag) {
                $tag = trim((string) $tag);
                if ($tag !== '') {
                    $reportTags[] = $tag;
                }
            }
        }

        $engineVersion = trim((string) ($policy['engine_version'] ?? 'v2.0_Factor_Logic'));
        if ($engineVersion === '') {
            $engineVersion = 'v2.0_Factor_Logic';
        }

        $quality['crisis_alert'] = $crisisAlert;

        $reportTags = array_values(array_unique($reportTags));

        return [
            'scale_code' => 'SDS_20',
            'engine_version' => $engineVersion,
            'quality' => [
                'level' => (string) ($quality['level'] ?? 'A'),
                'flags' => array_values(array_unique(array_map(
                    static fn ($flag): string => strtoupper(trim((string) $flag)),
                    (array) ($quality['flags'] ?? [])
                ))),
                'crisis_alert' => (bool) ($quality['crisis_alert'] ?? false),
                'completion_time_seconds' => $completionSeconds,
            ],
            'scores' => [
                'global' => [
                    'raw' => $rawTotal,
                    'index_score' => $indexScore,
                    'clinical_level' => $clinicalLevel,
                    'percentile' => $percentile,
                ],
                'factors' => $factors,
            ],
            'norms' => $norms,
            'report_tags' => $reportTags,
            'version_snapshot' => [
                'pack_id' => (string) ($ctx['pack_id'] ?? 'SDS_20'),
                'pack_version' => (string) ($ctx['dir_version'] ?? 'v1'),
                'policy_version' => (string) ($policy['scoring_spec_version'] ?? ''),
                'policy_hash' => $policyHash,
                'engine_version' => $engineVersion,
                'scoring_spec_version' => (string) ($policy['scoring_spec_version'] ?? 'v2.0_Factor_Logic'),
                'content_manifest_hash' => (string) ($ctx['content_manifest_hash'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<int|string,mixed>  $answers
     * @return array<int,string>
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

                $code = strtoupper(trim((string) ($answer['code'] ?? ($answer['value'] ?? $answer['answer'] ?? ''))));
                $out[$qid] = $code;
            }

            ksort($out, SORT_NUMERIC);

            return $out;
        }

        foreach ($answers as $key => $value) {
            $qidRaw = trim((string) $key);
            if ($qidRaw === '' || preg_match('/^\d+$/', $qidRaw) !== 1) {
                continue;
            }
            $qid = (int) $qidRaw;
            if ($qid <= 0) {
                continue;
            }

            if (is_array($value)) {
                $code = strtoupper(trim((string) ($value['code'] ?? ($value['value'] ?? $value['answer'] ?? ''))));
            } else {
                $code = strtoupper(trim((string) $value));
            }

            $out[$qid] = $code;
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,int>
     */
    private function resolveBaseMap(array $policy): array
    {
        $map = is_array($policy['base_map'] ?? null) ? $policy['base_map'] : [];

        $resolved = [];
        foreach (self::ALLOWED_CODES as $code) {
            $resolved[$code] = (int) ($map[$code] ?? 0);
        }

        if ($resolved['A'] === 1 && $resolved['B'] === 2 && $resolved['C'] === 3 && $resolved['D'] === 4) {
            return $resolved;
        }

        return [
            'A' => 1,
            'B' => 2,
            'C' => 3,
            'D' => 4,
        ];
    }

    /**
     * @param  array<int,string>  $rawCodes
     * @param  array<string,mixed>  $policy
     * @return array{level:string,flags:list<string>}
     */
    private function buildQuality(array $rawCodes, int $completionSeconds, array $policy): array
    {
        $flags = [];
        $level = 'A';

        $speedingSeconds = (int) data_get($policy, 'quality_rules.speeding_seconds_lt', 30);
        if ($completionSeconds < $speedingSeconds) {
            $flags[] = 'SPEEDING';
            $level = 'D';
        }

        $straightlineThreshold = (int) data_get($policy, 'quality_rules.straightlining_run_len_gte', 10);
        $longestRun = $this->longestRun($rawCodes);
        if ($longestRun >= $straightlineThreshold) {
            $flags[] = 'STRAIGHTLINING';
            if ($level !== 'D') {
                $level = 'C';
            }
        }

        return [
            'level' => $level,
            'flags' => array_values(array_unique($flags)),
        ];
    }

    /**
     * @param  array<int,string>  $rawCodes
     */
    private function longestRun(array $rawCodes): int
    {
        if ($rawCodes === []) {
            return 0;
        }

        ksort($rawCodes, SORT_NUMERIC);

        $max = 0;
        $current = 0;
        $last = null;
        foreach ($rawCodes as $code) {
            if ($last !== null && $last === $code) {
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

            if ($number > 2_000_000_000_000) {
                return (int) floor($number / 1000);
            }
            if ($number > 2_000_000_000) {
                return (int) floor($number / 1000);
            }

            return $number;
        }

        $ts = strtotime($normalized);

        return $ts === false ? null : $ts;
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function computeIndexScore(int $rawScore, array $policy): int
    {
        $num = (int) data_get($policy, 'index_score.multiplier_num', 125);
        $den = (int) data_get($policy, 'index_score.multiplier_den', 100);
        if ($den <= 0) {
            $den = 100;
        }

        $score = intdiv($rawScore * $num, $den);

        return max(0, min(100, $score));
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function resolveClinicalLevel(int $indexScore, array $policy): string
    {
        $buckets = (array) ($policy['clinical_buckets'] ?? []);
        foreach ($buckets as $bucket) {
            if (! is_array($bucket)) {
                continue;
            }

            $min = (int) ($bucket['min'] ?? 0);
            $max = (int) ($bucket['max'] ?? 0);
            if ($indexScore < $min || $indexScore > $max) {
                continue;
            }

            $code = trim((string) ($bucket['code'] ?? ''));
            if ($code !== '') {
                return $code;
            }
        }

        if ($indexScore < 53) {
            return 'normal';
        }
        if ($indexScore <= 62) {
            return 'mild_depression';
        }
        if ($indexScore <= 72) {
            return 'moderate_depression';
        }

        return 'severe_depression';
    }

    /**
     * @param  array<int,int>  $itemScores
     * @param  array<string,mixed>  $policy
     * @return array<string,array{score:int,max:int,severity:string}>
     */
    private function buildFactors(array $itemScores, array $policy): array
    {
        $map = is_array($policy['factor_map'] ?? null) ? $policy['factor_map'] : [];
        $groups = [
            'psycho_affective' => [1, 3],
            'somatic' => [2, 4, 5, 6, 7, 8, 9, 10],
            'psychomotor' => [11, 12, 16],
            'cognitive' => [13, 14, 15, 17, 18, 19, 20],
        ];

        $out = [];
        foreach ($groups as $key => $fallbackQids) {
            $qids = array_values(array_filter(
                array_map('intval', (array) ($map[$key] ?? $fallbackQids)),
                static fn (int $v): bool => $v > 0
            ));
            if ($qids === []) {
                $qids = $fallbackQids;
            }

            $score = 0;
            foreach ($qids as $qid) {
                $score += (int) ($itemScores[$qid] ?? 0);
            }

            $max = count($qids) * 4;
            $ratio = $max > 0 ? ($score / $max) : 0.0;
            if ($ratio < 0.40) {
                $severity = 'low';
            } elseif ($ratio <= 0.65) {
                $severity = 'medium';
            } else {
                $severity = 'high';
            }

            $out[$key] = [
                'score' => $score,
                'max' => $max,
                'severity' => $severity,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,int>  $itemScores
     * @param  list<int>  $coreItems
     */
    private function allCoreScoresAtMost(array $itemScores, array $coreItems, int $max): bool
    {
        foreach ($coreItems as $qid) {
            if ((int) ($itemScores[$qid] ?? 0) > $max) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $resolved
     * @return array<string,mixed>
     */
    private function buildNormsPayload(array $resolved): array
    {
        $status = strtoupper(trim((string) ($resolved['status'] ?? 'MISSING')));
        if (! in_array($status, ['CALIBRATED', 'PROVISIONAL', 'MISSING'], true)) {
            $status = 'MISSING';
        }

        $metric = is_array($resolved['metric'] ?? null) ? $resolved['metric'] : [];
        $mean = (float) ($metric['mean'] ?? 0.0);
        $sd = (float) ($metric['sd'] ?? 0.0);
        $sampleN = (int) ($metric['sample_n'] ?? 0);

        if ($mean <= 0.0 || $sd <= 0.0 || $sampleN <= 0) {
            $status = 'MISSING';
        }

        return [
            'status' => $status,
            'group_id' => (string) ($resolved['group_id'] ?? ''),
            'norms_version' => (string) ($resolved['norms_version'] ?? ''),
            'source_id' => (string) ($resolved['source_id'] ?? ''),
            'source_type' => (string) ($resolved['source_type'] ?? ''),
            'metric' => [
                'metric_level' => (string) ($metric['metric_level'] ?? 'global'),
                'metric_code' => (string) ($metric['metric_code'] ?? 'INDEX_SCORE'),
                'mean' => round($mean, 4),
                'sd' => round($sd, 4),
                'sample_n' => $sampleN,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $norms
     */
    private function resolvePercentile(int $indexScore, array $norms): ?int
    {
        $status = strtoupper(trim((string) ($norms['status'] ?? 'MISSING')));
        if ($status === 'MISSING') {
            return null;
        }

        $metric = is_array($norms['metric'] ?? null) ? $norms['metric'] : [];
        $mean = (float) ($metric['mean'] ?? 0.0);
        $sd = (float) ($metric['sd'] ?? 0.0);
        if ($mean <= 0.0 || $sd <= 0.0) {
            return null;
        }

        $z = ($indexScore - $mean) / $sd;
        $pct = (int) round($this->normalCdfApprox($z) * 100);

        return max(0, min(100, $pct));
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

    /**
     * @param  array<string,mixed>  $policy
     */
    private function hashPolicy(array $policy): string
    {
        $normalized = $this->sortRecursive($policy);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', is_string($encoded) ? $encoded : '{}');
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $idx => $item) {
                $value[$idx] = $this->sortRecursive($item);
            }

            return $value;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        return $value;
    }
}
