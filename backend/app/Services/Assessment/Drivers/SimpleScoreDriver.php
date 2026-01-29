<?php

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;

class SimpleScoreDriver implements DriverInterface
{
    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $answerScores = $spec['answer_scores'] ?? [];
        if (!is_array($answerScores)) {
            $answerScores = [];
        }

        $optionScores = $spec['options_score_map'] ?? [];
        if (!is_array($optionScores)) {
            $optionScores = [];
        }

        $items = [];
        $sum = 0;
        $maxScore = 0;

        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                continue;
            }

            $qid = trim((string) ($answer['question_id'] ?? ''));
            $code = strtoupper((string) ($answer['code'] ?? ''));
            if ($qid === '' || $code === '') {
                continue;
            }

            $score = 0;
            if (isset($answerScores[$qid]) && is_array($answerScores[$qid])) {
                $score = (int) ($answerScores[$qid][$code] ?? 0);
                $maxScore += $this->maxScoreForMap($answerScores[$qid]);
            } elseif (isset($answerScores['*']) && is_array($answerScores['*'])) {
                $score = (int) ($answerScores['*'][$code] ?? 0);
                $maxScore += $this->maxScoreForMap($answerScores['*']);
            } elseif (!empty($optionScores)) {
                $score = (int) ($optionScores[$code] ?? 0);
                $maxScore += $this->maxScoreForMap($optionScores);
            }

            $sum += $score;
            $items[] = [
                'question_id' => $qid,
                'code' => $code,
                'score' => $score,
            ];
        }

        $severity = $this->resolveSeverity($sum, $spec['severity_levels'] ?? $spec['severity'] ?? []);

        $breakdown = [
            'score_method' => 'sum',
            'max_score' => $maxScore,
            'severity' => $severity,
            'items' => $items,
            'time_bonus' => 0,
        ];

        return new ScoreResult((float) $sum, (float) $sum, $breakdown, null, null, null);
    }

    private function resolveSeverity(int $score, array $levels): ?array
    {
        if (!is_array($levels)) {
            return null;
        }

        foreach ($levels as $level) {
            if (!is_array($level)) {
                continue;
            }
            $min = $level['min'] ?? null;
            $max = $level['max'] ?? null;
            if ($min === null || $max === null) {
                continue;
            }
            $minVal = (int) $min;
            $maxVal = (int) $max;
            if ($score >= $minVal && $score <= $maxVal) {
                return [
                    'label' => (string) ($level['label'] ?? ''),
                    'min' => $minVal,
                    'max' => $maxVal,
                ];
            }
        }

        return null;
    }

    private function maxScoreForMap(array $map): int
    {
        $max = null;
        foreach ($map as $val) {
            if (!is_numeric($val)) {
                continue;
            }
            $num = (int) $val;
            $max = ($max === null) ? $num : max($max, $num);
        }

        return $max ?? 0;
    }
}
