<?php

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;

class GenericLikertDriver implements DriverInterface
{
    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $optionScores = $spec['options_score_map'] ?? $spec['option_scores'] ?? [];
        if (!is_array($optionScores)) {
            $optionScores = [];
        }

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

            $qid = trim((string) ($answer['question_id'] ?? ''));
            $code = strtoupper((string) ($answer['code'] ?? ''));
            if ($qid === '' || $code === '') {
                continue;
            }

            $value = $optionScores[$code] ?? null;
            if (!is_numeric($value)) {
                continue;
            }
            $value = (float) $value;

            foreach ($dimensions as $dim => $conf) {
                if (!is_array($conf)) {
                    continue;
                }
                $itemsMap = $conf['items'] ?? [];
                if (!is_array($itemsMap) || !array_key_exists($qid, $itemsMap)) {
                    continue;
                }

                $weight = $itemsMap[$qid] ?? 1;
                if (!is_numeric($weight)) {
                    $weight = 1;
                }
                $weight = (float) $weight;

                $dimScores[$dim] = ($dimScores[$dim] ?? 0) + ($value * $weight);
                $dimCounts[$dim] = ($dimCounts[$dim] ?? 0) + 1;

                $items[] = [
                    'question_id' => $qid,
                    'code' => $code,
                    'dimension' => (string) $dim,
                    'value' => $value,
                    'weight' => $weight,
                ];
            }
        }

        $total = 0.0;
        $dimsOut = [];
        foreach ($dimScores as $dim => $score) {
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
}
