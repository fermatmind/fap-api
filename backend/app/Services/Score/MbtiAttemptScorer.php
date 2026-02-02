<?php

namespace App\Services\Score;

class MbtiAttemptScorer
{
    /**
     * @param array $answers  [ ['question_id'=>'Q1','code'=>'A'], ... ]
     * @param array $questionsIndex question_id => ['dimension','key_pole','direction','score_map','weight','code']
     * @param array|null $scoringSpec scoring_spec.json (optional)
     */
    public function score(array $answers, array $questionsIndex, ?array $scoringSpec = null): array
    {
        $engineVersion = $this->resolveEngineVersion($scoringSpec);
        if ($this->isV2Spec($scoringSpec)) {
            return $this->scoreV2($answers, $questionsIndex, $scoringSpec, $engineVersion);
        }

        return $this->scoreV1($answers, $questionsIndex, $scoringSpec, $engineVersion);
    }

    private function scoreV1(
        array $answers,
        array $questionsIndex,
        ?array $scoringSpec,
        string $engineVersion
    ): array {
        $expectedQuestionCount = $this->expectedCount($questionsIndex);
        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

        // 校验
        foreach ($answers as $a) {
            $qid = $a['question_id'] ?? null;
            if (!$qid || !isset($questionsIndex[$qid])) {
                throw new \RuntimeException("Unknown question_id: " . (string)$qid);
            }
        }

        if (count($answers) !== $expectedQuestionCount) {
            throw new \RuntimeException("Invalid answers count. expected={$expectedQuestionCount}, got=" . count($answers));
        }

        $dimN        = array_fill_keys($dims, 0);
        $sumTowardP1 = array_fill_keys($dims, 0);
        $countP1     = array_fill_keys($dims, 0);
        $countP2     = array_fill_keys($dims, 0);
        $countNeutral= array_fill_keys($dims, 0);
        $skipped     = [];

        $defaultScoreMap = ['A'=>2,'B'=>1,'C'=>0,'D'=>-1,'E'=>-2];
        $fallbackScoreMap = $this->normalizeScoreMap($scoringSpec['likert']['score_map'] ?? null, $defaultScoreMap);

        foreach ($answers as $a) {
            $qid  = (string)($a['question_id'] ?? '');
            $code = strtoupper((string)($a['code'] ?? ''));

            $meta = $questionsIndex[$qid];
            $dim  = $meta['dimension'] ?? null;
            if (!$dim || !isset($dimN[$dim])) {
                $skipped[] = ['question_id' => $qid, 'reason' => 'invalid_dimension', 'dimension' => $dim];
                continue;
            }

            $scoreMap = $this->normalizeScoreMap($meta['score_map'] ?? null, $fallbackScoreMap);

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
                'count'   => $dimN[$dim],
            ];
        }

        $winningPoles = [
            'EI' => $scoresPct['EI'] >= 50 ? 'E' : 'I',
            'SN' => $scoresPct['SN'] >= 50 ? 'S' : 'N',
            'TF' => $scoresPct['TF'] >= 50 ? 'T' : 'F',
            'JP' => $scoresPct['JP'] >= 50 ? 'J' : 'P',
            'AT' => $scoresPct['AT'] >= 50 ? 'A' : 'T',
        ];

        return [
            'typeCode' => $typeCode,
            'scoresPct' => $scoresPct,
            'axisStates' => $axisStates,
            'scoresJson' => $scoresJson,
            'counts' => [
                'p1' => $countP1,
                'p2' => $countP2,
                'neutral' => $countNeutral,
            ],
            'dimTotals' => $dimN,
            'sumTowardP1' => $sumTowardP1,
            'skipped' => $skipped,
            'engineVersion' => $engineVersion,
            'winningPoles' => $winningPoles,
            'pci' => null,
            'facetScores' => null,
        ];
    }

    private function scoreV2(
        array $answers,
        array $questionsIndex,
        ?array $scoringSpec,
        string $engineVersion
    ): array {
        $expectedQuestionCount = $this->expectedCount($questionsIndex);
        $dimensions = $this->resolveDimensions($scoringSpec);
        $dims = array_keys($dimensions);

        if (count($answers) !== $expectedQuestionCount) {
            throw new \RuntimeException("Invalid answers count. expected={$expectedQuestionCount}, got=" . count($answers));
        }

        foreach ($answers as $a) {
            $qid = $a['question_id'] ?? null;
            if (!$qid || !isset($questionsIndex[$qid])) {
                throw new \RuntimeException("Unknown question_id: " . (string)$qid);
            }
        }

        $weighting = is_array($scoringSpec['weighting'] ?? null) ? $scoringSpec['weighting'] : [];
        $minW = isset($weighting['min_weight']) ? (float) $weighting['min_weight'] : 0.5;
        $maxW = isset($weighting['max_weight']) ? (float) $weighting['max_weight'] : 2.0;
        $defaultW = isset($weighting['default_weight']) ? (float) $weighting['default_weight'] : 1.0;

        $scoreMapDefault = $this->normalizeScoreMap(
            $scoringSpec['likert']['score_map'] ?? null,
            ['A' => 2, 'B' => 1, 'C' => 0, 'D' => -1, 'E' => -2]
        );

        $dimN = array_fill_keys($dims, 0);
        $sumWeighted = array_fill_keys($dims, 0.0);
        $weightSum = array_fill_keys($dims, 0.0);
        $countP1 = array_fill_keys($dims, 0);
        $countP2 = array_fill_keys($dims, 0);
        $countNeutral = array_fill_keys($dims, 0);
        $skipped = [];

        $facetMap = $this->buildFacetMap($scoringSpec);
        $facetSum = [];
        $facetWeightSum = [];

        foreach ($answers as $a) {
            $qid  = (string)($a['question_id'] ?? '');
            $code = strtoupper((string)($a['code'] ?? ''));
            $meta = $questionsIndex[$qid];
            $dim  = $meta['dimension'] ?? null;

            if (!$dim || !isset($dimensions[$dim])) {
                $skipped[] = ['question_id' => $qid, 'reason' => 'invalid_dimension', 'dimension' => $dim];
                continue;
            }

            $scoreMap = $this->normalizeScoreMap($meta['score_map'] ?? null, $scoreMapDefault);
            if (!array_key_exists($code, $scoreMap)) {
                $scoreMap = $scoreMapDefault;
            }

            $rawScore  = (int) ($scoreMap[$code] ?? 0);
            $direction = (int) ($meta['direction'] ?? 1);
            $keyPole   = (string) ($meta['key_pole'] ?? '');

            $signed = $rawScore * (($direction === 0) ? 1 : $direction);
            $p1 = $dimensions[$dim]['p1'];
            $p2 = $dimensions[$dim]['p2'];
            $towardP1 = ($keyPole === $p2) ? -$signed : $signed;

            $weight = $this->clampWeight($meta['weight'] ?? null, $minW, $maxW, $defaultW);
            $weighted = $towardP1 * $weight;

            $dimN[$dim] += 1;
            $sumWeighted[$dim] += $weighted;
            $weightSum[$dim] += $weight;

            if ($towardP1 > 0) $countP1[$dim] += 1;
            elseif ($towardP1 < 0) $countP2[$dim] += 1;
            else $countNeutral[$dim] += 1;

            $qCode = (string) ($meta['code'] ?? '');
            if ($qCode !== '' && isset($facetMap[$qCode])) {
                $facetKey = $facetMap[$qCode]['facet'];
                if (!isset($facetSum[$facetKey])) {
                    $facetSum[$facetKey] = 0.0;
                    $facetWeightSum[$facetKey] = 0.0;
                }
                $facetSum[$facetKey] += $weighted;
                $facetWeightSum[$facetKey] += $weight;
            }
        }

        foreach ($dims as $dim) {
            if ($dimN[$dim] <= 0 || $weightSum[$dim] <= 0) {
                throw new \RuntimeException("No scored answers for dim={$dim}");
            }
        }

        $normalization = is_array($scoringSpec['normalization'] ?? null) ? $scoringSpec['normalization'] : [];
        $pctRound = isset($normalization['percent_round']) ? (int) $normalization['percent_round'] : 0;
        $tieDefaults = $this->resolveTieBreakDefaults($scoringSpec);
        $pciLevels = $this->resolvePciLevels($scoringSpec);

        $scoresPct = [];
        $winningPoles = [];
        $axisStates = [];
        $pciAxes = [];

        foreach ($dims as $dim) {
            $w = $weightSum[$dim];
            $normalized = $sumWeighted[$dim] / (2 * $w);
            $pct = 50 + ($normalized * 50);
            $pct = max(0.0, min(100.0, $pct));
            $pct = $pctRound >= 0 ? round($pct, $pctRound) : $pct;

            $scoresPct[$dim] = (int) $pct;

            $p1 = $dimensions[$dim]['p1'];
            $p2 = $dimensions[$dim]['p2'];
            $tie = $tieDefaults[$dim] ?? $p1;
            $winning = $pct > 50 ? $p1 : ($pct < 50 ? $p2 : $tie);
            $winningPoles[$dim] = $winning;

            $displayPct = $pct >= 50 ? $pct : (100 - $pct);
            $axisStates[$dim] = match (true) {
                $displayPct >= 80 => 'very_strong',
                $displayPct >= 70 => 'strong',
                $displayPct >= 60 => 'clear',
                $displayPct >= 55 => 'weak',
                default           => 'very_weak',
            };

            $clarity = abs($pct - 50) * 2;
            $pciAxes[$dim] = [
                'percent_first_pole' => (int) $pct,
                'winning_pole' => $winning,
                'clarity' => (int) round($clarity),
                'level' => $this->pciLevel($clarity, $pciLevels),
            ];
        }

        $letters = [
            'EI' => $winningPoles['EI'] ?? 'E',
            'SN' => $winningPoles['SN'] ?? 'S',
            'TF' => $winningPoles['TF'] ?? 'T',
            'JP' => $winningPoles['JP'] ?? 'J',
        ];
        $atSuffix = $winningPoles['AT'] ?? 'A';
        $typeCode = implode('', $letters) . '-' . $atSuffix;

        $scoresJson = [];
        foreach ($dims as $dim) {
            $scoresJson[$dim] = [
                'a' => $countP1[$dim],
                'b' => $countP2[$dim],
                'neutral' => $countNeutral[$dim],
                'sum' => round($sumWeighted[$dim], 4),
                'total' => round($weightSum[$dim], 4),
                'count' => $dimN[$dim],
            ];
        }

        $facetScores = [];
        foreach ($facetSum as $facetKey => $sum) {
            $w = $facetWeightSum[$facetKey] ?? 0.0;
            if ($w <= 0) {
                continue;
            }
            $dim = substr((string) $facetKey, 0, 2);
            $p1 = $dimensions[$dim]['p1'] ?? null;
            $p2 = $dimensions[$dim]['p2'] ?? null;
            if ($p1 === null || $p2 === null) {
                continue;
            }
            $normalized = $sum / (2 * $w);
            $pct = 50 + ($normalized * 50);
            $pct = max(0.0, min(100.0, $pct));
            $pct = $pctRound >= 0 ? round($pct, $pctRound) : $pct;
            $winning = $pct > 50 ? $p1 : ($pct < 50 ? $p2 : ($tieDefaults[$dim] ?? $p1));
            $clarity = abs($pct - 50) * 2;
            $facetScores[$facetKey] = [
                'dimension' => $dim,
                'percent_first_pole' => (int) $pct,
                'winning_pole' => $winning,
                'clarity' => (int) round($clarity),
                'level' => $this->pciLevel($clarity, $pciLevels),
            ];
        }

        $pciOverall = null;
        if (!empty($pciAxes)) {
            $sum = 0.0;
            $n = 0;
            foreach ($pciAxes as $row) {
                if (isset($row['clarity']) && is_numeric($row['clarity'])) {
                    $sum += (float) $row['clarity'];
                    $n += 1;
                }
            }
            if ($n > 0) {
                $avg = $sum / $n;
                $pciOverall = [
                    'clarity' => (int) round($avg),
                    'level' => $this->pciLevel($avg, $pciLevels),
                ];
            }
        }

        $pci = [
            'overall' => $pciOverall,
            'axes' => $pciAxes,
        ];

        return [
            'typeCode' => $typeCode,
            'scoresPct' => $scoresPct,
            'axisStates' => $axisStates,
            'scoresJson' => $scoresJson,
            'counts' => [
                'p1' => $countP1,
                'p2' => $countP2,
                'neutral' => $countNeutral,
            ],
            'dimTotals' => $dimN,
            'sumTowardP1' => $sumWeighted,
            'skipped' => $skipped,
            'engineVersion' => $engineVersion,
            'winningPoles' => $winningPoles,
            'pci' => $pci,
            'facetScores' => $facetScores,
        ];
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

    private function expectedCount(array $questionsIndex): int
    {
        $count = count($questionsIndex);
        return $count > 0 ? $count : 144;
    }

    private function normalizeScoreMap(?array $scoreMap, array $fallback): array
    {
        if (!is_array($scoreMap) || empty($scoreMap)) {
            return $fallback;
        }

        $norm = [];
        foreach ($scoreMap as $k => $v) {
            $key = strtoupper((string) $k);
            if ($key === '') {
                continue;
            }
            $norm[$key] = (int) $v;
        }

        if (empty($norm)) {
            return $fallback;
        }

        $allZero = true;
        foreach ($norm as $v) {
            if ((int) $v !== 0) {
                $allZero = false;
                break;
            }
        }

        return $allZero ? $fallback : $norm;
    }

    private function clampWeight(mixed $raw, float $min, float $max, float $fallback): float
    {
        $val = is_numeric($raw) ? (float) $raw : $fallback;
        if ($val < $min) $val = $min;
        if ($val > $max) $val = $max;
        return $val;
    }

    private function resolveDimensions(?array $scoringSpec): array
    {
        $default = [
            'EI' => ['p1' => 'E', 'p2' => 'I', 'axis_positive' => 'E'],
            'SN' => ['p1' => 'S', 'p2' => 'N', 'axis_positive' => 'S'],
            'TF' => ['p1' => 'T', 'p2' => 'F', 'axis_positive' => 'T'],
            'JP' => ['p1' => 'J', 'p2' => 'P', 'axis_positive' => 'J'],
            'AT' => ['p1' => 'A', 'p2' => 'T', 'axis_positive' => 'A'],
        ];

        $dims = is_array($scoringSpec['dimensions'] ?? null) ? $scoringSpec['dimensions'] : [];
        if (empty($dims)) {
            return $default;
        }

        $out = [];
        foreach ($dims as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $poles = $row['poles'] ?? null;
            $p1 = $poles[0] ?? ($default[$code]['p1'] ?? '');
            $p2 = $poles[1] ?? ($default[$code]['p2'] ?? '');
            $axisPositive = strtoupper((string) ($row['axis_positive'] ?? $p1));
            if ($axisPositive !== $p1 && $axisPositive !== $p2) {
                $axisPositive = $p1;
            }
            $out[$code] = [
                'p1' => $axisPositive,
                'p2' => ($axisPositive === $p1 ? $p2 : $p1),
                'axis_positive' => $axisPositive,
            ];
        }

        return empty($out) ? $default : $out;
    }

    private function resolveTieBreakDefaults(?array $scoringSpec): array
    {
        $fallback = [
            'EI' => 'I',
            'SN' => 'N',
            'TF' => 'F',
            'JP' => 'P',
            'AT' => 'T',
        ];

        $defaults = $scoringSpec['tie_break']['defaults'] ?? null;
        if (!is_array($defaults)) {
            return $fallback;
        }

        $out = $fallback;
        foreach ($defaults as $dim => $pole) {
            $key = strtoupper((string) $dim);
            $val = strtoupper((string) $pole);
            if ($key !== '' && $val !== '') {
                $out[$key] = $val;
            }
        }

        return $out;
    }

    private function resolvePciLevels(?array $scoringSpec): array
    {
        $levels = $scoringSpec['pci']['levels'] ?? null;
        if (!is_array($levels) || empty($levels)) {
            return [
                ['code' => 'slight', 'min' => 0, 'max' => 14],
                ['code' => 'moderate', 'min' => 15, 'max' => 30],
                ['code' => 'clear', 'min' => 31, 'max' => 50],
                ['code' => 'very_clear', 'min' => 51, 'max' => 100],
            ];
        }
        return $levels;
    }

    private function pciLevel(float $clarity, array $levels): string
    {
        foreach ($levels as $level) {
            if (!is_array($level)) {
                continue;
            }
            $min = isset($level['min']) ? (float) $level['min'] : 0.0;
            $max = isset($level['max']) ? (float) $level['max'] : 100.0;
            if ($clarity >= $min && $clarity <= $max) {
                $code = (string) ($level['code'] ?? '');
                return $code !== '' ? $code : 'unknown';
            }
        }
        return 'unknown';
    }

    private function buildFacetMap(?array $scoringSpec): array
    {
        $out = [];
        $groups = $scoringSpec['facets']['mapping']['groups'] ?? null;
        if (!is_array($groups)) {
            return $out;
        }

        foreach ($groups as $dim => $facets) {
            if (!is_array($facets)) {
                continue;
            }
            foreach ($facets as $facetKey => $codes) {
                if (!is_array($codes)) {
                    continue;
                }
                foreach ($codes as $code) {
                    $qCode = strtoupper((string) $code);
                    if ($qCode === '') {
                        continue;
                    }
                    $out[$qCode] = [
                        'facet' => (string) $facetKey,
                        'dimension' => strtoupper((string) $dim),
                    ];
                }
            }
        }

        return $out;
    }

    private function isV2Spec(?array $scoringSpec): bool
    {
        if (!is_array($scoringSpec)) {
            return false;
        }

        $schema = (string) ($scoringSpec['schema'] ?? '');
        $engine = (string) ($scoringSpec['engine_version'] ?? '');

        return $schema === 'fap.scoring_spec.v2' || $engine === 'mbti-industrial-v2';
    }

    private function resolveEngineVersion(?array $scoringSpec): string
    {
        $engine = is_array($scoringSpec) ? (string) ($scoringSpec['engine_version'] ?? '') : '';
        return $engine !== '' ? $engine : 'mbti-legacy-v1';
    }
}
