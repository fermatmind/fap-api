<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;

class GenericLikertDriver implements DriverInterface
{
    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $optionScores = $spec['options_score_map'] ?? ($spec['option_scores'] ?? []);
        if (!is_array($optionScores)) {
            $optionScores = [];
        }

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

            $rawValue = $optionScores[$code] ?? null;
            if (!is_numeric($rawValue)) {
                continue;
            }
            $rawValue = (float) $rawValue;

            foreach ($dimensions as $dim => $conf) {
                if (!is_array($conf)) {
                    continue;
                }

                $itemsMap = $conf['items'] ?? [];
                if (!is_array($itemsMap) || !array_key_exists($qid, $itemsMap)) {
                    continue;
                }

                [$weight, $reverse] = $this->resolveItemRule($itemsMap[$qid]);

                $effectiveValue = $reverse
                    ? (($minScore + $maxScore) - $rawValue)
                    : $rawValue;

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
        }

        $total = 0.0;
        $dimsOut = [];
        foreach ($dimScores as $dim => $score) {
            $score = (float) $score;
            $total += $score;
            $dimsOut[$dim] = [
                'score' => $score,
                'count' => (int) ($dimCounts[$dim] ?? 0),
            ];
        }

        $breakdown = [
            'score_method' => 'dimension_sum',
            'dimensions' => $dimsOut,
            'total_score' => $total,
            'items' => $items,
            'time_bonus' => 0,
        ];

        return new ScoreResult($total, $total, $breakdown, null, null, null);
    }

    /**
     * @param mixed $itemConf
     * @return array{0:float,1:bool}
     */
    private function resolveItemRule($itemConf): array
    {
        if (is_numeric($itemConf)) {
            $weight = (float) $itemConf;
            if ($weight < 0) {
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
        if ($weight < 0) {
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
}
