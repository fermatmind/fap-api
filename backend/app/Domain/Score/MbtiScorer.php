<?php

namespace App\Domain\Score;

final class MbtiScorer
{
    /**
     * @param array $questions 题库 items（建议用 question_id 做 key）
     *   每题至少需要：axis(=EI/SN/TF/JP/AT), side_a, side_b
     * @param array $answers 形如：
     *   [
     *     ['question_id'=>'q1', 'choice'=>'A'],
     *     ['question_id'=>'q2', 'choice'=>'B'],
     *   ]
     */
    public static function score(array $questions, array $answers): array
    {
        // counts[axis][side] = int
        $counts = [
            'EI' => ['E'=>0,'I'=>0],
            'SN' => ['S'=>0,'N'=>0],
            'TF' => ['T'=>0,'F'=>0],
            'JP' => ['J'=>0,'P'=>0],
            'AT' => ['A'=>0,'T'=>0],
        ];

        foreach ($answers as $a) {
            $qid = $a['question_id'] ?? null;
            $choice = strtoupper((string)($a['choice'] ?? ''));

            if (!$qid || ($choice !== 'A' && $choice !== 'B')) continue;
            if (!isset($questions[$qid])) continue;

            $q = $questions[$qid];

            $axis = $q['axis'] ?? $q['dim'] ?? null; // 兼容字段名
            if (!isset($counts[$axis])) continue;

            $sideA = $q['side_a'] ?? $q['a_side'] ?? null;
            $sideB = $q['side_b'] ?? $q['b_side'] ?? null;
            if (!$sideA || !$sideB) continue;

            $pick = ($choice === 'A') ? $sideA : $sideB;

            if (isset($counts[$axis][$pick])) {
                $counts[$axis][$pick] += 1;
            }
        }

        // 用你刚做好的 AxisScore 统一输出
        $scores = [];
        $scores['EI'] = AxisScore::fromCounts($counts['EI']['E'], $counts['EI']['I'], 'E', 'I')->toArray();
        $scores['SN'] = AxisScore::fromCounts($counts['SN']['S'], $counts['SN']['N'], 'S', 'N')->toArray();
        $scores['TF'] = AxisScore::fromCounts($counts['TF']['T'], $counts['TF']['F'], 'T', 'F')->toArray();
        $scores['JP'] = AxisScore::fromCounts($counts['JP']['J'], $counts['JP']['P'], 'J', 'P')->toArray();
        $scores['AT'] = AxisScore::fromCounts($counts['AT']['A'], $counts['AT']['T'], 'A', 'T')->toArray();

        return [$scores, $counts];
    }

    public static function typeCodeFromScores(array $scores): string
    {
        $core = ($scores['EI']['side'] ?? 'E')
            . ($scores['SN']['side'] ?? 'S')
            . ($scores['TF']['side'] ?? 'T')
            . ($scores['JP']['side'] ?? 'J');

        $at = $scores['AT']['side'] ?? 'A';

        return $core . '-' . $at;
    }
}