<?php

namespace App\Services\Score;

class MbtiAttemptScorer
{
    /**
     * @param array $answers  [ ['question_id'=>'Q1','code'=>'A'], ... ]
     * @param array $questionsIndex question_id => ['dimension','key_pole','direction','score_map']
     */
    public function score(array $answers, array $questionsIndex): array
    {
        $expectedQuestionCount = 144;
        $dims = ['EI','SN','TF','JP','AT'];

        if (count($answers) !== $expectedQuestionCount) {
            throw new \RuntimeException("Invalid answers count. expected={$expectedQuestionCount}, got=".count($answers));
        }

        // 校验
        foreach ($answers as $a) {
            $qid = $a['question_id'] ?? null;
            if (!$qid || !isset($questionsIndex[$qid])) {
                throw new \RuntimeException("Unknown question_id: " . (string)$qid);
            }
        }

        $dimN        = array_fill_keys($dims, 0);
        $sumTowardP1 = array_fill_keys($dims, 0);
        $countP1     = array_fill_keys($dims, 0);
        $countP2     = array_fill_keys($dims, 0);
        $countNeutral= array_fill_keys($dims, 0);

        $defaultScoreMap = ['A'=>2,'B'=>1,'C'=>0,'D'=>-1,'E'=>-2];

        foreach ($answers as $a) {
            $qid  = (string)($a['question_id'] ?? '');
            $code = strtoupper((string)($a['code'] ?? ''));

            $meta = $questionsIndex[$qid];
            $dim  = $meta['dimension'] ?? null;
            if (!$dim || !isset($dimN[$dim])) continue;

            $scoreMap = $meta['score_map'] ?? null;
            if (!is_array($scoreMap) || empty($scoreMap)) $scoreMap = $defaultScoreMap;

            // normalize
            $norm = [];
            foreach ($scoreMap as $k=>$v) $norm[strtoupper((string)$k)] = (int)$v;
            $scoreMap = $norm;

            if (!array_key_exists($code, $scoreMap)) $scoreMap = $defaultScoreMap;

            // 全0兜底
            $allZero = true;
            foreach ($scoreMap as $v) { if ((int)$v !== 0) { $allZero=false; break; } }
            if ($allZero) $scoreMap = $defaultScoreMap;

            $rawScore  = (int)($scoreMap[$code] ?? 0);
            $direction = (int)($meta['direction'] ?? 1);
            $keyPole   = (string)($meta['key_pole'] ?? '');

            $signed = $rawScore * (($direction === 0) ? 1 : $direction);

            [$p1, $p2] = $this->getDimensionPoles($dim);
            $towardP1 = ($keyPole === $p2) ? -$signed : $signed;

            $dimN[$dim] += 1;
            $sumTowardP1[$dim] += $towardP1;

            if ($towardP1 > 0) $countP1[$dim] += 1;
            elseif ($towardP1 < 0) $countP2[$dim] += 1;
            else $countNeutral[$dim] += 1;
        }

        foreach ($dims as $dim) {
            if ((int)$dimN[$dim] <= 0) {
                throw new \RuntimeException("No scored answers for dim={$dim}");
            }
        }

        // scores_pct：0..100（toward P1）
        $scoresPct = [];
        foreach ($dims as $dim) {
            $n = (int)$dimN[$dim];
            $pct = (int)round((($sumTowardP1[$dim] + 2*$n) / (4*$n)) * 100);
            $pct = max(0, min(100, $pct));
            $scoresPct[$dim] = $pct;
        }

        // type_code
        $letters = [
            'EI' => $scoresPct['EI'] >= 50 ? 'E' : 'I',
            'SN' => $scoresPct['SN'] >= 50 ? 'S' : 'N',
            'TF' => $scoresPct['TF'] >= 50 ? 'T' : 'F',
            'JP' => $scoresPct['JP'] >= 50 ? 'J' : 'P',
        ];
        $atSuffix = $scoresPct['AT'] >= 50 ? 'A' : 'T';
        $typeCode = implode('', $letters).'-'.$atSuffix;

        // axis_states（你的离散化规则）
        $axisStates = [];
        foreach ($scoresPct as $dim => $pctTowardP1) {
            $displayPct = $pctTowardP1 >= 50 ? $pctTowardP1 : (100 - $pctTowardP1);
            $axisStates[$dim] = match (true) {
                $displayPct >= 80 => 'very_strong',
                $displayPct >= 70 => 'strong',
                $displayPct >= 60 => 'clear',
                $displayPct >= 55 => 'weak',
                default           => 'very_weak',
            };
        }

        // scores_json（审计）
        $scoresJson = [];
        foreach ($dims as $dim) {
            $scoresJson[$dim] = [
                'a'       => $countP1[$dim],
                'b'       => $countP2[$dim],
                'neutral' => $countNeutral[$dim],
                'sum'     => $sumTowardP1[$dim],
                'total'   => $dimN[$dim],
            ];
        }

        return compact('typeCode','scoresPct','axisStates','scoresJson');
    }

    private function getDimensionPoles(string $dim): array
    {
        return match ($dim) {
            'EI' => ['E','I'],
            'SN' => ['S','N'],
            'TF' => ['T','F'],
            'JP' => ['J','P'],
            'AT' => ['A','T'],
            default => ['',''],
        };
    }
}