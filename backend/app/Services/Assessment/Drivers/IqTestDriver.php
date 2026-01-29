<?php

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;

class IqTestDriver implements DriverInterface
{
    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $answerKey = $spec['answer_key'] ?? [];
        if (!is_array($answerKey)) {
            $answerKey = [];
        }

        $scoreSpec = $spec['score'] ?? [];
        if (!is_array($scoreSpec)) {
            $scoreSpec = [];
        }

        $correctScore = (int) ($scoreSpec['correct'] ?? 1);
        $wrongScore = (int) ($scoreSpec['wrong'] ?? 0);

        $total = 0;
        $correct = 0;
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

            $total += 1;
            $expected = strtoupper((string) ($answerKey[$qid] ?? ''));
            $isCorrect = $expected !== '' && $expected === $code;

            if ($isCorrect) {
                $correct += 1;
            }

            $items[] = [
                'question_id' => $qid,
                'code' => $code,
                'correct' => $isCorrect,
            ];
        }

        $rawScore = ($correct * $correctScore) + (($total - $correct) * $wrongScore);
        $timeBonus = $this->resolveTimeBonus((int) ($ctx['duration_ms'] ?? 0), $spec['time_bonus'] ?? null);
        $finalScore = $rawScore + $timeBonus['bonus'];

        $accuracy = $total > 0 ? round($correct / $total, 4) : 0.0;

        $breakdown = [
            'score_method' => 'answer_key',
            'correct_count' => $correct,
            'total_count' => $total,
            'accuracy' => $accuracy,
            'duration_ms' => (int) ($ctx['duration_ms'] ?? 0),
            'time_bonus' => $timeBonus['bonus'],
            'time_bonus_rule' => $timeBonus['rule'],
            'items' => $items,
        ];

        $normed = [
            'correct_rate' => $accuracy,
        ];

        return new ScoreResult((float) $rawScore, (float) $finalScore, $breakdown, null, null, $normed);
    }

    private function resolveTimeBonus(int $durationMs, ?array $timeBonusSpec): array
    {
        $bonus = 0;
        $rule = null;

        if ($durationMs <= 0 || !is_array($timeBonusSpec)) {
            return ['bonus' => $bonus, 'rule' => $rule];
        }

        $rules = $timeBonusSpec['rules'] ?? null;
        if (!is_array($rules)) {
            return ['bonus' => $bonus, 'rule' => $rule];
        }

        foreach ($rules as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $maxMs = (int) ($candidate['max_ms'] ?? 0);
            if ($maxMs <= 0) {
                continue;
            }
            if ($durationMs <= $maxMs) {
                $bonus = (int) ($candidate['bonus'] ?? 0);
                $rule = [
                    'max_ms' => $maxMs,
                    'bonus' => $bonus,
                ];
                return ['bonus' => $bonus, 'rule' => $rule];
            }
        }

        $last = end($rules);
        if (is_array($last)) {
            $bonus = (int) ($last['bonus'] ?? 0);
            if (array_key_exists('max_ms', $last)) {
                $rule = [
                    'max_ms' => (int) ($last['max_ms'] ?? 0),
                    'bonus' => $bonus,
                ];
            }
        }

        return ['bonus' => $bonus, 'rule' => $rule];
    }
}
