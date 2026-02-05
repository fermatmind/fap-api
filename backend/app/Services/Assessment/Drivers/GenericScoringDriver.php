<?php

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;
use RuntimeException;

class GenericScoringDriver implements DriverInterface
{
    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $questionsDoc = $ctx['questions'] ?? null;
        if (!is_array($questionsDoc)) {
            throw new RuntimeException('questions missing');
        }

        $items = $questionsDoc['items'] ?? ($questionsDoc['questions']['items'] ?? null);
        if (!is_array($items) || empty($items)) {
            throw new RuntimeException('questions.items missing');
        }

        $dimensionsSpec = $spec['dimensions'] ?? null;
        if (!is_array($dimensionsSpec) || empty($dimensionsSpec)) {
            throw new RuntimeException('scoring_spec.dimensions missing');
        }

        $dimensions = $this->normalizeDimensions($dimensionsSpec);
        if (empty($dimensions)) {
            throw new RuntimeException('scoring_spec.dimensions invalid');
        }

        $fallbackScoreMap = $this->normalizeScoreMap($spec['score_map'] ?? ($spec['scoreMap'] ?? null));
        $questionIndex = $this->buildQuestionIndex($items, $dimensions, $fallbackScoreMap);
        if (empty($questionIndex)) {
            throw new RuntimeException('questions index empty');
        }

        $dichotomyDims = [];
        $traitDims = [];
        foreach ($dimensions as $code => $conf) {
            $p1 = (string) ($conf['p1'] ?? '');
            $p2 = (string) ($conf['p2'] ?? '');
            if ($p1 !== '' && $p2 !== '') {
                $dichotomyDims[$code] = $conf;
            } else {
                $traitDims[$code] = $conf;
            }
        }

        $sumTowardP1 = [];
        $minSum = [];
        $maxSum = [];
        $countTotal = [];
        $countP1 = [];
        $countP2 = [];
        $countNeutral = [];

        $traitSum = [];
        $traitCount = [];
        $skipped = [];
        $answerCount = 0;

        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                continue;
            }

            $qid = trim((string) ($answer['question_id'] ?? ''));
            $code = strtoupper((string) ($answer['code'] ?? ($answer['option_code'] ?? '')));
            if ($qid === '' || $code === '') {
                continue;
            }

            $meta = $questionIndex[$qid] ?? null;
            if (!is_array($meta)) {
                $skipped[] = ['question_id' => $qid, 'reason' => 'unknown_question'];
                continue;
            }

            $dim = (string) ($meta['dimension'] ?? '');
            if ($dim === '' || !isset($dimensions[$dim])) {
                $skipped[] = ['question_id' => $qid, 'reason' => 'unknown_dimension', 'dimension' => $dim];
                continue;
            }

            $scoreMap = $meta['scores'] ?? [];
            if (!is_array($scoreMap)) {
                $scoreMap = [];
            }

            $score = $scoreMap[$code] ?? null;
            if (!is_numeric($score)) {
                $skipped[] = ['question_id' => $qid, 'reason' => 'unknown_option', 'dimension' => $dim];
                continue;
            }

            $score = (float) $score;
            $direction = (int) ($meta['direction'] ?? 1);
            $direction = $direction === 0 ? 1 : $direction;

            if (isset($dichotomyDims[$dim])) {
                $p2 = (string) ($dichotomyDims[$dim]['p2'] ?? '');
                $keyPole = (string) ($meta['key_pole'] ?? '');
                $signed = $score * $direction;
                $towardP1 = ($keyPole !== '' && $p2 !== '' && $keyPole === $p2) ? -$signed : $signed;

                $range = $meta['toward_range'] ?? null;
                if (!is_array($range)) {
                    $range = $this->computeTowardRange($scoreMap, $direction, $keyPole, $p2);
                }

                $sumTowardP1[$dim] = ($sumTowardP1[$dim] ?? 0.0) + $towardP1;
                $minSum[$dim] = ($minSum[$dim] ?? 0.0) + (float) ($range['min'] ?? 0.0);
                $maxSum[$dim] = ($maxSum[$dim] ?? 0.0) + (float) ($range['max'] ?? 0.0);
                $countTotal[$dim] = ($countTotal[$dim] ?? 0) + 1;

                if ($towardP1 > 0) {
                    $countP1[$dim] = ($countP1[$dim] ?? 0) + 1;
                } elseif ($towardP1 < 0) {
                    $countP2[$dim] = ($countP2[$dim] ?? 0) + 1;
                } else {
                    $countNeutral[$dim] = ($countNeutral[$dim] ?? 0) + 1;
                }
            } else {
                $minScore = $meta['min_score'] ?? null;
                $maxScore = $meta['max_score'] ?? null;
                if (!is_numeric($minScore) || !is_numeric($maxScore) || (float) $maxScore === (float) $minScore) {
                    $skipped[] = ['question_id' => $qid, 'reason' => 'invalid_score_range', 'dimension' => $dim];
                    continue;
                }

                $minScore = (float) $minScore;
                $maxScore = (float) $maxScore;
                $adjusted = $direction < 0 ? ($maxScore + $minScore - $score) : $score;
                $normalized = ($adjusted - $minScore) / ($maxScore - $minScore) * 100;
                $normalized = max(0.0, min(100.0, $normalized));

                $traitSum[$dim] = ($traitSum[$dim] ?? 0.0) + $normalized;
                $traitCount[$dim] = ($traitCount[$dim] ?? 0) + 1;
                $countTotal[$dim] = ($countTotal[$dim] ?? 0) + 1;
            }

            $answerCount += 1;
        }

        $pciLevels = $this->resolvePciLevels($spec);
        $tieDefaults = $this->resolveTieDefaults($spec);

        $scoresPct = [];
        $axisStates = [];
        $scoresJson = [];
        $winningPoles = [];
        $pciAxes = [];

        foreach ($dichotomyDims as $dim => $conf) {
            if (!isset($countTotal[$dim]) || $countTotal[$dim] <= 0) {
                continue;
            }

            $range = ($maxSum[$dim] ?? 0.0) - ($minSum[$dim] ?? 0.0);
            if ($range <= 0) {
                $pct = 50.0;
            } else {
                $pct = (($sumTowardP1[$dim] ?? 0.0) - ($minSum[$dim] ?? 0.0)) / $range * 100;
            }
            $pct = max(0.0, min(100.0, $pct));
            $pct = (float) round($pct);

            $scoresPct[$dim] = (int) $pct;

            $p1 = (string) ($conf['p1'] ?? '');
            $p2 = (string) ($conf['p2'] ?? '');
            $tie = $tieDefaults[$dim] ?? $p1;
            $winning = $pct > 50 ? $p1 : ($pct < 50 ? $p2 : $tie);
            $winningPoles[$dim] = $winning;

            $displayPct = $pct >= 50 ? $pct : (100 - $pct);
            $axisStates[$dim] = match (true) {
                $displayPct >= 80 => 'very_strong',
                $displayPct >= 70 => 'strong',
                $displayPct >= 60 => 'clear',
                $displayPct >= 55 => 'weak',
                default => 'very_weak',
            };

            $clarity = abs($pct - 50) * 2;
            $pciAxes[$dim] = [
                'percent_first_pole' => (int) $pct,
                'winning_pole' => $winning,
                'clarity' => (int) round($clarity),
                'level' => $this->pciLevel($clarity, $pciLevels),
            ];

            $scoresJson[$dim] = [
                'a' => $countP1[$dim] ?? 0,
                'b' => $countP2[$dim] ?? 0,
                'neutral' => $countNeutral[$dim] ?? 0,
                'sum' => (float) ($sumTowardP1[$dim] ?? 0.0),
                'total' => (float) ($countTotal[$dim] ?? 0),
                'count' => (int) ($countTotal[$dim] ?? 0),
            ];
        }

        $traitScores = [];
        foreach ($traitDims as $dim => $conf) {
            if (!isset($traitCount[$dim]) || $traitCount[$dim] <= 0) {
                continue;
            }

            $pct = ($traitSum[$dim] ?? 0.0) / $traitCount[$dim];
            $pct = max(0.0, min(100.0, $pct));
            $pct = (float) round($pct);

            $scoresPct[$dim] = (int) $pct;
            $traitScores[$dim] = [
                'percent' => (int) $pct,
                'count' => (int) $traitCount[$dim],
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

        $typeCode = $this->resolveTypeCode($spec, $winningPoles);

        $breakdown = [
            'score_method' => 'generic_scoring',
            'answer_count' => $answerCount,
            'dimensions' => $scoresPct,
            'skipped' => $skipped,
            'time_bonus' => 0,
        ];

        $axisScores = [
            'scores_pct' => $scoresPct,
            'axis_states' => $axisStates,
            'scores_json' => $scoresJson,
            'winning_poles' => $winningPoles,
            'pci' => [
                'overall' => $pciOverall,
                'axes' => $pciAxes,
            ],
            'trait_scores' => $traitScores,
        ];

        return new ScoreResult(
            (float) $answerCount,
            (float) $answerCount,
            $breakdown,
            $typeCode,
            $axisScores,
            null
        );
    }

    private function normalizeDimensions(array $dimensionsSpec): array
    {
        $out = [];
        foreach ($dimensionsSpec as $key => $value) {
            $conf = is_array($value) ? $value : [];
            $code = is_int($key) ? (string) ($conf['code'] ?? '') : (string) $key;
            $code = strtoupper(trim($code));
            if ($code === '') {
                continue;
            }

            $p1 = strtoupper((string) ($conf['p1'] ?? ($conf['pole1'] ?? '')));
            $p2 = strtoupper((string) ($conf['p2'] ?? ($conf['pole2'] ?? '')));
            if ($p1 === '' || $p2 === '') {
                $poles = $conf['poles'] ?? null;
                if (is_array($poles)) {
                    if ($p1 === '') {
                        $p1 = strtoupper((string) ($poles[0] ?? ''));
                    }
                    if ($p2 === '') {
                        $p2 = strtoupper((string) ($poles[1] ?? ''));
                    }
                }
            }

            $out[$code] = [
                'p1' => $p1,
                'p2' => $p2,
            ] + $conf;
        }

        return $out;
    }

    private function normalizeScoreMap(mixed $scoreMap): array
    {
        if (!is_array($scoreMap)) {
            return [];
        }

        $out = [];
        foreach ($scoreMap as $code => $score) {
            $key = strtoupper((string) $code);
            if ($key === '' || !is_numeric($score)) {
                continue;
            }
            $out[$key] = (float) $score;
        }

        return $out;
    }

    private function buildQuestionIndex(array $items, array $dimensions, array $fallbackScoreMap): array
    {
        $index = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qid = trim((string) ($item['question_id'] ?? ''));
            $dim = strtoupper(trim((string) ($item['dimension'] ?? '')));
            if ($qid === '' || $dim === '') {
                continue;
            }

            $scores = $this->extractOptionScores($item['options'] ?? null, $fallbackScoreMap);
            if (empty($scores)) {
                continue;
            }

            $direction = (int) ($item['direction'] ?? 1);
            $direction = $direction === 0 ? 1 : $direction;
            $keyPole = strtoupper(trim((string) ($item['key_pole'] ?? '')));

            $minScore = null;
            $maxScore = null;
            foreach ($scores as $val) {
                if (!is_numeric($val)) {
                    continue;
                }
                $minScore = $minScore === null ? (float) $val : min($minScore, (float) $val);
                $maxScore = $maxScore === null ? (float) $val : max($maxScore, (float) $val);
            }

            $towardRange = null;
            $conf = $dimensions[$dim] ?? null;
            if (is_array($conf)) {
                $p2 = (string) ($conf['p2'] ?? '');
                $p1 = (string) ($conf['p1'] ?? '');
                if ($p1 !== '' && $p2 !== '') {
                    $towardRange = $this->computeTowardRange($scores, $direction, $keyPole, $p2);
                }
            }

            $index[$qid] = [
                'dimension' => $dim,
                'scores' => $scores,
                'direction' => $direction,
                'key_pole' => $keyPole,
                'min_score' => $minScore,
                'max_score' => $maxScore,
                'toward_range' => $towardRange,
            ];
        }

        return $index;
    }

    private function extractOptionScores(mixed $options, array $fallbackScoreMap): array
    {
        $scores = [];
        if (is_array($options)) {
            foreach ($options as $opt) {
                if (!is_array($opt)) {
                    continue;
                }
                $code = strtoupper((string) ($opt['code'] ?? ($opt['option_code'] ?? '')));
                if ($code === '' || !is_numeric($opt['score'] ?? null)) {
                    continue;
                }
                $scores[$code] = (float) ($opt['score'] ?? 0);
            }
        }

        if (empty($scores)) {
            $scores = $fallbackScoreMap;
        }

        return $scores;
    }

    private function computeTowardRange(array $scores, int $direction, string $keyPole, string $p2): array
    {
        $direction = $direction === 0 ? 1 : $direction;
        $min = null;
        $max = null;
        foreach ($scores as $val) {
            if (!is_numeric($val)) {
                continue;
            }
            $signed = (float) $val * $direction;
            $toward = ($keyPole !== '' && $p2 !== '' && $keyPole === $p2) ? -$signed : $signed;
            $min = $min === null ? $toward : min($min, $toward);
            $max = $max === null ? $toward : max($max, $toward);
        }

        if ($min === null || $max === null) {
            return ['min' => 0.0, 'max' => 0.0];
        }

        return ['min' => $min, 'max' => $max];
    }

    private function resolveTypeCode(array $spec, array $winningPoles): ?string
    {
        $rules = $spec['type_rules'] ?? null;
        if (!is_array($rules)) {
            return null;
        }

        $baseAxes = $rules['base_axes'] ?? null;
        if (!is_array($baseAxes) || empty($baseAxes)) {
            return null;
        }

        $letters = [];
        foreach ($baseAxes as $axis) {
            $axisCode = strtoupper((string) $axis);
            $pole = $winningPoles[$axisCode] ?? null;
            if (!is_string($pole) || $pole === '') {
                return null;
            }
            $letters[] = $pole;
        }

        $typeCode = implode('', $letters);
        $suffixAxis = strtoupper((string) ($rules['suffix_axis'] ?? ''));
        if ($suffixAxis !== '') {
            $suffixPole = $winningPoles[$suffixAxis] ?? null;
            if (is_string($suffixPole) && $suffixPole !== '') {
                $delimiter = (string) ($rules['delimiter'] ?? '');
                $typeCode .= $delimiter . $suffixPole;
            }
        }

        return $typeCode !== '' ? $typeCode : null;
    }

    private function resolvePciLevels(array $spec): array
    {
        $levels = $spec['pci_levels'] ?? ($spec['pci']['levels'] ?? null);
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

    private function resolveTieDefaults(array $spec): array
    {
        $defaults = $spec['tie_break']['defaults'] ?? null;
        if (!is_array($defaults)) {
            return [];
        }

        $out = [];
        foreach ($defaults as $dim => $pole) {
            $key = strtoupper((string) $dim);
            $val = strtoupper((string) $pole);
            if ($key !== '' && $val !== '') {
                $out[$key] = $val;
            }
        }

        return $out;
    }
}
