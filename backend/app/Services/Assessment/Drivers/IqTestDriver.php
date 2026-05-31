<?php

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use App\Services\Iq\IqNormAuthorityContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IqTestDriver implements DriverInterface
{
    private const DIMENSIONS = ['VSPR', 'VSI', 'NPR'];

    /**
     * @var array<string,string>
     */
    private const DIMENSION_NAMES = [
        'VSPR' => '视觉空间模式推理',
        'VSI' => '视觉空间洞察',
        'NPR' => '数字规律推理',
    ];

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $durationMs = max(0, (int) ($ctx['duration_ms'] ?? 0));
        $normalizedItems = $this->normalizeAnswers($answers);
        $contract = $this->resolveContract($spec, $ctx);
        $quality = $this->buildQuality(
            $durationMs,
            $normalizedItems,
            $contract['quality_rules'],
            $contract['expected_item_count']
        );

        if ($contract['status'] !== 'scored') {
            return $this->blockedUnscoredResult(
                $durationMs,
                $normalizedItems,
                $quality,
                $contract
            );
        }

        $scoredItems = $this->scoreItems($normalizedItems, $contract['items']);
        $dimensionScores = $this->buildDimensionScores($contract['items'], $scoredItems);
        $rawScore = array_reduce(
            $scoredItems,
            static fn (float $carry, array $item): float => $carry + (float) ($item['awarded_points'] ?? 0.0),
            0.0
        );
        $correctCount = count(array_filter(
            $scoredItems,
            static fn (array $item): bool => (bool) ($item['is_correct'] ?? false)
        ));
        $stability = $this->buildResultStability($quality, $normalizedItems, $contract['expected_item_count']);
        $norms = $this->resolveNorms(
            $contract['scale_code'],
            $contract['bank_id'],
            $contract['norm_table_version'],
            $rawScore,
            $ctx
        );
        $scorePayload = [
            'scale_code' => $contract['scale_code'],
            'bank_id' => $contract['bank_id'],
            'status' => 'scored',
            'scoring_mode' => 'scored',
            'answer_key_version' => $contract['answer_key_version'],
            'norm_table_version' => $contract['norm_table_version'],
            'scoring_engine_version' => $contract['scoring_engine_version'],
            'raw_score' => $rawScore,
            'final_score' => $rawScore,
            'answer_count' => count($normalizedItems),
            'expected_item_count' => $contract['expected_item_count'],
            'correct_count' => $correctCount,
            'dimension_scores' => $dimensionScores,
            'quality' => $quality,
            'quality_rules' => $contract['quality_rules'],
            'result_stability' => $stability,
            'norms' => $norms,
            'items' => $scoredItems,
            'version_snapshot' => [
                'pack_id' => (string) ($ctx['pack_id'] ?? ''),
                'pack_version' => (string) ($ctx['content_package_version'] ?? ($ctx['dir_version'] ?? '')),
                'engine_version' => (string) ($spec['engine_version'] ?? ''),
                'scoring_spec_version' => (string) ($ctx['scoring_spec_version'] ?? ($spec['version'] ?? '')),
                'content_manifest_hash' => (string) ($ctx['content_manifest_hash'] ?? ''),
            ],
        ];

        return new ScoreResult(
            rawScore: $rawScore,
            finalScore: $rawScore,
            breakdownJson: [
                'status' => 'scored',
                'reason_code' => null,
                'scoring_mode' => 'scored',
                'duration_ms' => $durationMs,
                'answer_count' => count($normalizedItems),
                'bank_id' => $contract['bank_id'],
                'quality' => $quality,
                'dimension_scores' => $dimensionScores,
                'result_stability' => $stability,
                'score_result' => $scorePayload,
            ],
            typeCode: null,
            axisScoresJson: [
                'scores_json' => array_map(
                    static fn (array $row): float => (float) ($row['raw_score'] ?? 0.0),
                    $dimensionScores
                ),
                'scores_pct' => array_map(
                    static fn (array $row): ?float => isset($row['percent_correct']) ? (float) $row['percent_correct'] : null,
                    $dimensionScores
                ),
                'axis_states' => [],
                'score_result' => $scorePayload,
            ],
            normedJson: $scorePayload,
        );
    }

    /**
     * @return list<array{question_id:string,code:string}>
     */
    private function normalizeAnswers(array $answers): array
    {
        $normalized = [];

        foreach ($answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $questionId = strtoupper(trim((string) ($answer['question_id'] ?? '')));
            $code = strtoupper(trim((string) ($answer['code'] ?? ($answer['option_code'] ?? ''))));
            if ($questionId === '' || $code === '') {
                continue;
            }

            if (! preg_match('/^[A-F]$/', $code)) {
                continue;
            }

            $normalized[$questionId] = [
                'question_id' => $questionId,
                'code' => $code,
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @return array{
     *   status:string,
     *   reason_code:?string,
     *   scale_code:string,
     *   bank_id:string,
     *   answer_key_version:string,
     *   norm_table_version:?string,
     *   scoring_engine_version:string,
     *   expected_item_count:int,
     *   quality_rules:array<string,mixed>,
     *   items:array<string,array{
     *     item_id:string,
     *     question_id:string,
     *     dimension:string,
     *     correct_answer:string,
     *     raw_points:float
     *   }>
     * }
     */
    private function resolveContract(array $spec, array $ctx): array
    {
        $qualityRules = is_array($spec['quality_rules'] ?? null) ? $spec['quality_rules'] : [];
        $scaleCode = strtoupper(trim((string) ($spec['scale_code'] ?? ($ctx['scale_code'] ?? 'IQ_INTELLIGENCE_QUOTIENT'))));
        if ($scaleCode === '') {
            $scaleCode = 'IQ_INTELLIGENCE_QUOTIENT';
        }

        $contract = [
            'status' => 'blocked_unscored',
            'reason_code' => 'ANSWER_KEY_MISSING',
            'scale_code' => $scaleCode,
            'bank_id' => trim((string) data_get($spec, 'item_bank.bank_id', '')),
            'answer_key_version' => trim((string) ($spec['answer_key_version'] ?? '')),
            'norm_table_version' => $this->nullableTrimmedString($spec['norm_table_version'] ?? null),
            'scoring_engine_version' => trim((string) ($spec['scoring_engine_version'] ?? ($spec['engine_version'] ?? ''))),
            'expected_item_count' => (int) data_get($spec, 'item_bank.item_count', 0),
            'quality_rules' => $qualityRules,
            'items' => [],
        ];

        $scoringMode = strtolower(trim((string) ($spec['scoring_mode'] ?? 'pending_answer_key')));
        if ($scoringMode !== 'scored') {
            return $contract;
        }

        $rawItems = is_array($spec['items'] ?? null) ? $spec['items'] : [];
        if ($rawItems === []) {
            $contract['reason_code'] = 'ANSWER_KEY_MISSING';

            return $contract;
        }

        $items = [];
        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                $contract['reason_code'] = 'ANSWER_KEY_INCOMPLETE';

                return $contract;
            }

            $itemId = strtoupper(trim((string) ($item['item_id'] ?? '')));
            $questionId = strtoupper(trim((string) ($item['question_id'] ?? $item['current_id'] ?? $itemId)));
            $dimension = strtoupper(trim((string) ($item['dimension'] ?? '')));
            $correctAnswer = strtoupper(trim((string) ($item['correct_answer'] ?? '')));
            $itemFamily = trim((string) ($item['item_family'] ?? ''));
            $difficultyLevel = trim((string) ($item['difficulty_level'] ?? ''));
            $solutionRule = trim((string) ($item['solution_rule'] ?? ''));
            $distractorLogic = trim((string) ($item['distractor_logic'] ?? ''));
            $assets = $item['assets'] ?? null;
            $assetHashes = $item['asset_hashes'] ?? null;
            $generatorMetadata = $item['generator_metadata'] ?? null;

            if (
                $itemId === ''
                || $questionId === ''
                || ! in_array($dimension, self::DIMENSIONS, true)
                || preg_match('/^[A-F]$/', $correctAnswer) !== 1
                || $itemFamily === ''
                || $difficultyLevel === ''
                || $solutionRule === ''
                || $distractorLogic === ''
                || ! is_array($assets) || $assets === []
                || ! is_array($assetHashes) || $assetHashes === []
                || ! is_array($generatorMetadata) || $generatorMetadata === []
            ) {
                $contract['reason_code'] = 'ANSWER_KEY_INCOMPLETE';

                return $contract;
            }

            $items[$questionId] = [
                'item_id' => $itemId,
                'question_id' => $questionId,
                'dimension' => $dimension,
                'correct_answer' => $correctAnswer,
                'raw_points' => max(0.0, (float) ($item['raw_points'] ?? 1.0)),
            ];
        }

        if ($items === []) {
            $contract['reason_code'] = 'ANSWER_KEY_MISSING';

            return $contract;
        }

        if ($contract['expected_item_count'] <= 0) {
            $contract['expected_item_count'] = count($items);
        }
        if ($contract['answer_key_version'] === '') {
            $contract['answer_key_version'] = 'unversioned';
        }
        if ($contract['scoring_engine_version'] === '') {
            $contract['scoring_engine_version'] = 'iq_scoring_v2';
        }

        $contract['status'] = 'scored';
        $contract['reason_code'] = null;
        $contract['items'] = $items;

        return $contract;
    }

    /**
     * @param  list<array{question_id:string,code:string}>  $items
     * @param  array<string,mixed>  $qualityRules
     * @return array{level:string,flags:list<string>}
     */
    private function buildQuality(int $durationMs, array $items, array $qualityRules, int $expectedItemCount): array
    {
        $flags = [];

        $speedingSecondsLt = (int) ($qualityRules['speeding_seconds_lt'] ?? 0);
        if ($speedingSecondsLt > 0 && $durationMs > 0 && $durationMs < ($speedingSecondsLt * 1000)) {
            $flags[] = 'SPEEDING';
        }

        $straightliningRunLen = (int) ($qualityRules['straightlining_run_len_gte'] ?? 0);
        if ($straightliningRunLen > 1 && $this->maxRunLengthByCode($items) >= $straightliningRunLen) {
            $flags[] = 'STRAIGHTLINING';
        }

        if ($items === []) {
            $flags[] = 'NO_VALID_ANSWERS';
        }

        if ($expectedItemCount > 0 && count($items) < $expectedItemCount) {
            $flags[] = 'PARTIAL_COMPLETION';
        }

        $level = match (true) {
            in_array('NO_VALID_ANSWERS', $flags, true) => 'D',
            in_array('PARTIAL_COMPLETION', $flags, true) || count($flags) >= 2 => 'C',
            $flags === [] => 'A',
            default => 'B',
        };

        return [
            'level' => $level,
            'flags' => array_values(array_unique($flags)),
        ];
    }

    /**
     * @param  list<array{question_id:string,code:string}>  $items
     * @param  array<string,array{item_id:string,question_id:string,dimension:string,correct_answer:string,raw_points:float}>  $answerKey
     * @return list<array{
     *   item_id:string,
     *   question_id:string,
     *   dimension:string,
     *   selected_code:string,
     *   is_correct:bool,
     *   raw_points:float,
     *   awarded_points:float
     * }>
     */
    private function scoreItems(array $items, array $answerKey): array
    {
        $scored = [];

        foreach ($items as $item) {
            $questionId = (string) ($item['question_id'] ?? '');
            $selectedCode = (string) ($item['code'] ?? '');
            $definition = $answerKey[$questionId] ?? null;
            if (! is_array($definition)) {
                continue;
            }

            $isCorrect = $selectedCode === (string) ($definition['correct_answer'] ?? '');
            $rawPoints = (float) ($definition['raw_points'] ?? 0.0);

            $scored[] = [
                'item_id' => (string) ($definition['item_id'] ?? $questionId),
                'question_id' => $questionId,
                'dimension' => (string) ($definition['dimension'] ?? ''),
                'selected_code' => $selectedCode,
                'is_correct' => $isCorrect,
                'raw_points' => $rawPoints,
                'awarded_points' => $isCorrect ? $rawPoints : 0.0,
            ];
        }

        return $scored;
    }

    /**
     * @param  array<string,array{item_id:string,question_id:string,dimension:string,correct_answer:string,raw_points:float}>  $answerKey
     * @param  list<array{
     *   item_id:string,
     *   question_id:string,
     *   dimension:string,
     *   selected_code:string,
     *   is_correct:bool,
     *   raw_points:float,
     *   awarded_points:float
     * }>  $scoredItems
     * @return array<string,array{dimension_name:string,item_count:int,answered_count:int,correct_count:int,raw_score:float,percent_correct:?float}>
     */
    private function buildDimensionScores(array $answerKey, array $scoredItems): array
    {
        $byDimension = [];
        foreach (self::DIMENSIONS as $dimension) {
            $byDimension[$dimension] = [
                'dimension_name' => self::DIMENSION_NAMES[$dimension],
                'item_count' => 0,
                'answered_count' => 0,
                'correct_count' => 0,
                'raw_score' => 0.0,
                'percent_correct' => null,
            ];
        }

        foreach ($answerKey as $item) {
            $dimension = (string) ($item['dimension'] ?? '');
            if (! isset($byDimension[$dimension])) {
                continue;
            }

            $byDimension[$dimension]['item_count']++;
        }

        foreach ($scoredItems as $item) {
            $dimension = (string) ($item['dimension'] ?? '');
            if (! isset($byDimension[$dimension])) {
                continue;
            }

            $byDimension[$dimension]['answered_count']++;
            $byDimension[$dimension]['raw_score'] += (float) ($item['awarded_points'] ?? 0.0);
            if ((bool) ($item['is_correct'] ?? false)) {
                $byDimension[$dimension]['correct_count']++;
            }
        }

        foreach ($byDimension as $dimension => $row) {
            if (($row['item_count'] ?? 0) > 0) {
                $byDimension[$dimension]['percent_correct'] = round(
                    (((int) $row['correct_count']) / ((int) $row['item_count'])) * 100,
                    2
                );
            }
        }

        return $byDimension;
    }

    /**
     * @param  list<array{question_id:string,code:string}>  $items
     * @param  array{level:string,flags:list<string>}  $quality
     * @param  array{
     *   reason_code:?string,
     *   status:string,
     *   scale_code:string,
     *   bank_id:string,
     *   answer_key_version:string,
     *   norm_table_version:?string,
     *   scoring_engine_version:string,
     *   expected_item_count:int,
     *   quality_rules:array<string,mixed>,
     *   items:array<string,array{item_id:string,question_id:string,dimension:string,correct_answer:string,raw_points:float}>
     * }  $contract
     */
    private function blockedUnscoredResult(
        int $durationMs,
        array $items,
        array $quality,
        array $contract
    ): ScoreResult {
        $breakdown = [
            'status' => 'blocked_unscored',
            'reason_code' => $contract['reason_code'],
            'scoring_mode' => 'scored',
            'duration_ms' => $durationMs,
            'answer_count' => count($items),
            'expected_item_count' => $contract['expected_item_count'],
            'bank_id' => $contract['bank_id'],
            'answer_key_version' => $contract['answer_key_version'],
            'norm_table_version' => $contract['norm_table_version'],
            'scoring_engine_version' => $contract['scoring_engine_version'],
            'quality' => $quality,
            'items' => $items,
        ];

        return new ScoreResult(
            0.0,
            0.0,
            $breakdown,
            null,
            null,
            [
                'scale_code' => $contract['scale_code'],
                'status' => 'blocked_unscored',
                'reason_code' => $contract['reason_code'],
                'answer_key_version' => $contract['answer_key_version'],
                'norm_table_version' => $contract['norm_table_version'],
                'scoring_engine_version' => $contract['scoring_engine_version'],
                'quality' => $quality,
            ]
        );
    }

    /**
     * @param  array{level:string,flags:list<string>}  $quality
     * @param  list<array{question_id:string,code:string}>  $items
     * @return array{status:string,reason:string}
     */
    private function buildResultStability(array $quality, array $items, int $expectedItemCount): array
    {
        $flags = $quality['flags'] ?? [];
        $answeredCount = count($items);

        if (in_array('NO_VALID_ANSWERS', $flags, true) || ($expectedItemCount > 0 && $answeredCount === 0)) {
            return [
                'status' => 'unstable',
                'reason' => 'no_valid_answers',
            ];
        }

        if ($expectedItemCount > 0 && $answeredCount < $expectedItemCount) {
            return [
                'status' => 'review_with_caution',
                'reason' => 'partial_completion',
            ];
        }

        if ($flags !== []) {
            return [
                'status' => 'review_with_caution',
                'reason' => 'quality_flags_present',
            ];
        }

        return [
            'status' => 'stable',
            'reason' => 'quality_clear',
        ];
    }

    private function resolveNormStatus(?string $normTableVersion): string
    {
        $normalized = strtolower(trim((string) $normTableVersion));

        return $normalized === '' || in_array($normalized, ['unavailable', 'not_found', 'pending'], true)
            ? 'unavailable_without_norm_table'
            : 'unavailable_without_runtime_calibration';
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    private function resolveNorms(string $scaleCode, string $bankId, ?string $normTableVersion, float $rawScore, array $ctx): array
    {
        $base = [
            'status' => $this->resolveNormStatus($normTableVersion),
            'iq_estimate' => null,
            'percentile' => null,
            'confidence_interval' => null,
            'norm_table_version' => $normTableVersion,
            'claim_policy' => [
                'claim_eligible' => false,
                'reason_code' => null,
                'source' => 'iq_norm_authority',
            ],
        ];

        if ($base['status'] === 'unavailable_without_norm_table') {
            $base['claim_policy']['reason_code'] = 'norm_table_version_unavailable';

            return $base;
        }

        if ($scaleCode !== IqNormAuthorityContract::SCALE_CODE || ! Schema::hasTable('iq_norm_authorities')) {
            $base['claim_policy']['reason_code'] = 'iq_norm_authority_unavailable';

            return $base;
        }

        $locale = trim((string) ($ctx['locale'] ?? 'zh-CN'));
        $locale = $locale === '' ? 'zh-CN' : $locale;
        $populationKey = trim((string) ($ctx['population_key'] ?? IqNormAuthorityContract::DEFAULT_POPULATION_KEY));
        $populationKey = $populationKey === '' ? IqNormAuthorityContract::DEFAULT_POPULATION_KEY : $populationKey;

        $record = DB::table('iq_norm_authorities')
            ->where('org_id', (int) ($ctx['org_id'] ?? 0))
            ->where('scale_code', IqNormAuthorityContract::SCALE_CODE)
            ->where('bank_id', $bankId)
            ->where('norm_table_version', (string) $normTableVersion)
            ->where('population_key', $populationKey)
            ->where('locale', $locale)
            ->whereNull('retired_at')
            ->orderByDesc('effective_at')
            ->orderByDesc('updated_at')
            ->first();

        if (! $record) {
            $base['claim_policy']['reason_code'] = 'iq_norm_authority_not_found';

            return $base;
        }

        $authority = (array) $record;
        $gate = IqNormAuthorityContract::publicClaimGate($authority);
        $minRawScore = (float) ($authority['min_raw_score'] ?? 0.0);
        $maxRawScore = (float) ($authority['max_raw_score'] ?? 0.0);
        if ($rawScore < $minRawScore || $rawScore > $maxRawScore) {
            $gate['claim_eligible'] = false;
            $gate['reason_code'] = 'raw_score_outside_norm_range';
            $gate['errors'][] = 'raw_score_outside_norm_range';
            $gate['errors'] = array_values(array_unique($gate['errors']));
        }

        $base['status'] = (bool) ($gate['claim_eligible'] ?? false)
            ? strtolower(trim((string) ($authority['status'] ?? 'production_normed')))
            : 'unavailable_without_claim_eligible_norm_authority';
        $base['norm_table_version'] = (string) ($authority['norm_table_version'] ?? $normTableVersion);
        $base['population_key'] = (string) ($authority['population_key'] ?? $populationKey);
        $base['locale'] = (string) ($authority['locale'] ?? $locale);
        $base['sample_size'] = (int) ($authority['sample_size'] ?? 0);
        $base['claim_policy'] = [
            'claim_eligible' => (bool) ($gate['claim_eligible'] ?? false),
            'reason_code' => $gate['reason_code'] ?? null,
            'errors' => $gate['errors'] ?? [],
            'source' => 'iq_norm_authority',
        ];

        if (! (bool) ($gate['claim_eligible'] ?? false)) {
            return $base;
        }

        $mean = (float) ($authority['mean'] ?? 0.0);
        $standardDeviation = (float) ($authority['standard_deviation'] ?? 0.0);
        if ($standardDeviation <= 0.0) {
            $base['status'] = 'unavailable_without_claim_eligible_norm_authority';
            $base['claim_policy']['claim_eligible'] = false;
            $base['claim_policy']['reason_code'] = 'standard_deviation_must_be_positive';
            $base['claim_policy']['errors'] = ['standard_deviation_must_be_positive'];

            return $base;
        }

        $zScore = ($rawScore - $mean) / $standardDeviation;
        $iqEstimate = round(100.0 + (15.0 * $zScore), 1);
        $base['iq_estimate'] = $iqEstimate;
        $base['percentile'] = round($this->normalCdf($zScore) * 100.0, 2);
        $base['confidence_interval'] = [
            round($iqEstimate - 4.5, 1),
            round($iqEstimate + 4.5, 1),
        ];

        return $base;
    }

    private function normalCdf(float $zScore): float
    {
        $z = abs($zScore);
        $t = 1.0 / (1.0 + (0.2316419 * $z));
        $density = 0.3989422804014327 * exp(-($z * $z) / 2.0);
        $tail = $density * $t * (
            0.319381530
            + $t * (-0.356563782
            + $t * (1.781477937
            + $t * (-1.821255978
            + $t * 1.330274429)))
        );
        $cdf = $zScore >= 0.0 ? 1.0 - $tail : $tail;

        return max(0.0, min(1.0, $cdf));
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  list<array{question_id:string,code:string}>  $items
     */
    private function maxRunLengthByCode(array $items): int
    {
        $maxRun = 0;
        $currentRun = 0;
        $prev = null;

        foreach ($items as $item) {
            $code = (string) ($item['code'] ?? '');
            if ($code === '') {
                continue;
            }

            if ($code === $prev) {
                $currentRun++;
            } else {
                $currentRun = 1;
                $prev = $code;
            }

            if ($currentRun > $maxRun) {
                $maxRun = $currentRun;
            }
        }

        return $maxRun;
    }
}
