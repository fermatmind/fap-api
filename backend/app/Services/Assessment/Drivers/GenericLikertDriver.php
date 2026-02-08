<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use Illuminate\Support\Facades\Log;

class GenericLikertDriver implements DriverInterface
{
    /**
     * Backward-compatible compute API used by legacy tests/specs.
     *
     * @param array<int|string,mixed> $answers
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public function compute(array $answers, array $spec): array
    {
        $optionScores = $this->normalizeOptionScores($spec['options_score_map'] ?? ($spec['option_scores'] ?? []));
        $defaultValue = isset($spec['default_value']) && is_numeric($spec['default_value'])
            ? (float) $spec['default_value']
            : 0.0;

        [$minScore, $maxScore] = $this->resolveScoreBounds($optionScores, $defaultValue);

        $itemsMap = is_array($spec['items_map'] ?? null) ? $spec['items_map'] : [];
        $dimensionsMap = is_array($spec['dimensions_map'] ?? null) ? $spec['dimensions_map'] : [];

        $dimensionScores = [];
        $debugItems = [];

        $scaleCode = $this->resolveScaleCode($spec, []);

        foreach ($this->normalizeComputeAnswers($answers) as $qid => $code) {
            if (!array_key_exists($qid, $itemsMap)) {
                continue;
            }

            [$rawValue, $isValidRaw] = $this->resolveRawValue($optionScores, $code);
            [$weight, $reverse] = $this->resolveItemRule($itemsMap[$qid], false);

            $effective = 0.0;
            $weighted = 0.0;
            $matchedDimension = false;

            foreach ($dimensionsMap as $dimension => $questionIds) {
                if (!$this->dimensionContainsQuestion($questionIds, $qid)) {
                    continue;
                }

                $matchedDimension = true;
                $dimension = (string) $dimension;

                if (!$isValidRaw) {
                    $this->logInvalidAnswer($scaleCode, $qid, $dimension);
                    continue;
                }

                $effective = $reverse ? (($minScore + $maxScore) - $rawValue) : $rawValue;
                $weighted = $effective * $weight;
                $dimensionScores[$dimension] = ($dimensionScores[$dimension] ?? 0.0) + $weighted;
            }

            if (!$isValidRaw && !$matchedDimension) {
                $this->logInvalidAnswer($scaleCode, $qid, null);
            }

            $debugItems[$qid] = [
                'reverse' => $reverse,
                'weight' => $weight,
                'effective' => $effective,
                'weighted' => $weighted,
            ];
        }

        $total = 0.0;
        foreach ($dimensionScores as $score) {
            $total += (float) $score;
        }

        return [
            'score_total' => $total,
            'dimensions' => $dimensionScores,
            'debug' => [
                'items' => $debugItems,
            ],
        ];
    }

    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $optionScores = $this->normalizeOptionScores($spec['options_score_map'] ?? ($spec['option_scores'] ?? []));

        $defaultValue = isset($spec['default_value']) && is_numeric($spec['default_value'])
            ? (float) $spec['default_value']
            : 0.0;

        [$minScore, $maxScore] = $this->resolveScoreBounds($optionScores, $defaultValue);

        $dimensions = $spec['dimensions'] ?? [];
        if (!is_array($dimensions)) {
            $dimensions = [];
        }

        $dimScores = [];
        $dimCounts = [];
        $items = [];

        $scaleCode = $this->resolveScaleCode($spec, $ctx);

        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                continue;
            }

            $qid = trim((string) ($answer['question_id'] ?? $answer['qid'] ?? ''));
            if ($qid === '') {
                continue;
            }

            $code = strtoupper(trim((string) ($answer['code'] ?? '')));
            if ($code === '') {
                continue;
            }

            [$rawValue, $isValidRaw] = $this->resolveRawValue($optionScores, $code);
            $matchedDimension = false;

            foreach ($dimensions as $dim => $conf) {
                if (!is_array($conf)) {
                    continue;
                }

                [$itemsMap, $legacySignedWeight] = $this->resolveDimensionItemsMap($conf);
                if (!array_key_exists($qid, $itemsMap)) {
                    continue;
                }

                $matchedDimension = true;
                [$weight, $reverse] = $this->resolveItemRule($itemsMap[$qid], $legacySignedWeight);

                $effectiveValue = 0.0;
                if ($isValidRaw) {
                    $effectiveValue = $reverse
                        ? (($minScore + $maxScore) - $rawValue)
                        : $rawValue;
                } else {
                    $this->logInvalidAnswer($scaleCode, $qid, (string) $dim);
                }

                $weighted = $effectiveValue * $weight;

                $dimScores[$dim] = ($dimScores[$dim] ?? 0.0) + $weighted;
                $dimCounts[$dim] = ($dimCounts[$dim] ?? 0) + 1;

                $items[] = [
                    'question_id' => $qid,
                    'code' => $code,
                    'dimension' => (string) $dim,
                    'raw_value' => $rawValue,
                    'effective_value' => $effectiveValue,
                    'reverse' => $reverse,
                    'weight' => $weight,
                    'weighted_value' => $weighted,
                ];
            }

            if (!$isValidRaw && !$matchedDimension) {
                $this->logInvalidAnswer($scaleCode, $qid, null);
            }
        }

        $total = 0.0;
        $dimsOut = [];
        $dimScoresOut = [];
        foreach ($dimScores as $dim => $score) {
            $score = (float) $score;
            $total += $score;
            $dimScoresOut[$dim] = $score;
            $dimsOut[$dim] = [
                'score' => $score,
                'count' => (int) ($dimCounts[$dim] ?? 0),
            ];
        }

        $breakdown = [
            'score_method' => 'dimension_sum',
            'dim_scores' => $dimScoresOut,
            'dimensions' => $dimsOut,
            'total_score' => $total,
            'score_bounds' => [
                'min' => $minScore,
                'max' => $maxScore,
                'default' => $defaultValue,
            ],
            'items' => $items,
            'time_bonus' => 0,
        ];

        return new ScoreResult($total, $total, $breakdown, null, null, null);
    }

    /**
     * @param mixed $itemConf
     * @return array{0:float,1:bool}
     */
    private function resolveItemRule($itemConf, bool $legacySignedWeight = false): array
    {
        if (is_numeric($itemConf)) {
            $weight = (float) $itemConf;
            if ($legacySignedWeight && $weight < 0) {
                return [abs($weight), true];
            }

            return [$weight, false];
        }

        if (!is_array($itemConf)) {
            return [1.0, false];
        }

        $weight = isset($itemConf['weight']) && is_numeric($itemConf['weight'])
            ? (float) $itemConf['weight']
            : 1.0;

        $reverse = $this->toBool($itemConf['reverse'] ?? false);
        if ($legacySignedWeight && $weight < 0) {
            $weight = abs($weight);
            $reverse = true;
        }

        return [$weight, $reverse];
    }

    /**
     * @param array<string,mixed> $optionScores
     * @return array{0:float,1:float}
     */
    private function resolveScoreBounds(array $optionScores, float $defaultValue): array
    {
        $values = [];
        foreach ($optionScores as $score) {
            if (!is_numeric($score)) {
                continue;
            }
            $values[] = (float) $score;
        }

        if ($values === []) {
            return [$defaultValue, $defaultValue];
        }

        return [min($values), max($values)];
    }

    /**
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param mixed $rawOptionScores
     * @return array<string,float>
     */
    private function normalizeOptionScores($rawOptionScores): array
    {
        if (!is_array($rawOptionScores)) {
            return [];
        }

        $normalized = [];
        foreach ($rawOptionScores as $code => $score) {
            if (!is_numeric($score)) {
                continue;
            }

            $normalized[strtoupper(trim((string) $code))] = (float) $score;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $ctx
     */
    private function resolveScaleCode(array $spec, array $ctx): ?string
    {
        foreach ([$spec['scale_code'] ?? null, $spec['code'] ?? null, $ctx['scale_code'] ?? null] as $candidate) {
            if (!is_string($candidate) && !is_numeric($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function logInvalidAnswer(?string $scaleCode, string $itemId, ?string $dimension): void
    {
        $context = [
            'event' => 'scoring_invalid_answer',
        ];

        $itemId = trim($itemId);
        $dimension = $dimension === null ? null : trim($dimension);

        if ($scaleCode !== null && $scaleCode !== '') {
            $context['scale_code'] = $scaleCode;
        }
        if ($itemId !== '') {
            $context['item_id'] = $itemId;
        }
        if ($dimension !== null && $dimension !== '') {
            $context['dimension'] = $dimension;
        }

        Log::warning('scoring_invalid_answer', $context);
    }

    /**
     * @param array<string,float> $optionScores
     * @return array{0:float,1:bool}
     */
    private function resolveRawValue(array $optionScores, string $code): array
    {
        if (!array_key_exists($code, $optionScores)) {
            return [0.0, false];
        }

        $raw = $optionScores[$code];
        if (!is_numeric($raw)) {
            return [0.0, false];
        }

        return [(float) $raw, true];
    }

    /**
     * @param array<string,mixed> $dimensionConf
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function resolveDimensionItemsMap(array $dimensionConf): array
    {
        $itemsMap = $dimensionConf['items_map'] ?? null;
        if (is_array($itemsMap)) {
            return [$itemsMap, false];
        }

        $legacyItems = $dimensionConf['items'] ?? null;
        if (is_array($legacyItems)) {
            return [$legacyItems, true];
        }

        return [[], false];
    }

    /**
     * @param array<int|string,mixed> $answers
     * @return array<string,string>
     */
    private function normalizeComputeAnswers(array $answers): array
    {
        $normalized = [];
        foreach ($answers as $key => $value) {
            if (is_array($value)) {
                $qid = trim((string) ($value['question_id'] ?? $value['qid'] ?? ''));
                $code = strtoupper(trim((string) ($value['code'] ?? '')));
                if ($qid === '' || $code === '') {
                    continue;
                }
                $normalized[$qid] = $code;
                continue;
            }

            $qid = trim((string) $key);
            $code = strtoupper(trim((string) $value));
            if ($qid === '' || $code === '') {
                continue;
            }

            $normalized[$qid] = $code;
        }

        return $normalized;
    }

    /**
     * @param mixed $questionIds
     */
    private function dimensionContainsQuestion($questionIds, string $qid): bool
    {
        if (!is_array($questionIds)) {
            return false;
        }

        if (array_key_exists($qid, $questionIds)) {
            return true;
        }

        foreach ($questionIds as $candidate) {
            if ($qid === (string) $candidate) {
                return true;
            }
        }

        return false;
    }
}
