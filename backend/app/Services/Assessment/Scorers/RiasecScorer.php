<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

final class RiasecScorer
{
    private const DIMENSIONS = ['R', 'I', 'A', 'S', 'E', 'C'];

    /**
     * @param  array<int,int>  $answers
     * @param  array<int,array<string,mixed>>  $questionIndex
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function score(array $answers, array $questionIndex, array $policy): array
    {
        $formKind = strtolower(trim((string) ($policy['form_kind'] ?? 'standard')));
        $isEnhanced = $formKind === 'enhanced' || count($questionIndex) >= 140;
        $requiredCount = $isEnhanced ? 140 : 60;

        $normalizedAnswers = [];
        foreach ($answers as $qid => $value) {
            $questionId = (int) $qid;
            $score = (int) $value;
            if ($questionId <= 0 || $score < 1 || $score > 5) {
                continue;
            }
            $normalizedAnswers[$questionId] = $score;
        }
        ksort($normalizedAnswers, SORT_NUMERIC);

        $missing = [];
        for ($qid = 1; $qid <= $requiredCount; $qid++) {
            if (! array_key_exists($qid, $normalizedAnswers)) {
                $missing[] = $qid;
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException('RIASEC answers missing required questions: '.implode(',', array_slice($missing, 0, 12)));
        }

        $rawScores = array_fill_keys(self::DIMENSIONS, 0.0);
        $activityValues = array_fill_keys(self::DIMENSIONS, []);
        $environmentValues = array_fill_keys(self::DIMENSIONS, []);
        $roleValues = array_fill_keys(self::DIMENSIONS, []);

        foreach ($questionIndex as $qid => $meta) {
            $questionId = (int) $qid;
            if ($questionId <= 0 || $questionId > $requiredCount) {
                continue;
            }
            $dimension = strtoupper(trim((string) ($meta['dimension'] ?? '')));
            if (! in_array($dimension, self::DIMENSIONS, true)) {
                continue;
            }
            $value = (float) ($normalizedAnswers[$questionId] ?? 0);
            if ($value <= 0) {
                continue;
            }

            $subscale = strtolower(trim((string) ($meta['subscale'] ?? 'activity')));
            if ($questionId <= 60 || $subscale === 'activity') {
                $activityValues[$dimension][] = $value;
            } elseif ($subscale === 'environment') {
                $environmentValues[$dimension][] = $value;
            } elseif ($subscale === 'role') {
                $roleValues[$dimension][] = $value;
            }

            if (! $isEnhanced && $questionId <= 60) {
                $rawScores[$dimension] += $value;
            }
        }

        $scores = [];
        $activityScores = [];
        $environmentScores = [];
        $roleScores = [];
        foreach (self::DIMENSIONS as $dimension) {
            if ($isEnhanced) {
                $activity = $this->standardizeMean($this->mean($activityValues[$dimension]));
                $environment = $this->standardizeMean($this->mean($environmentValues[$dimension]));
                $role = $this->standardizeMean($this->mean($roleValues[$dimension]));
                $scores[$dimension] = $this->roundScore((0.70 * $activity) + (0.15 * $environment) + (0.15 * $role));
                $activityScores[$dimension] = $this->roundScore($activity);
                $environmentScores[$dimension] = $this->roundScore($environment);
                $roleScores[$dimension] = $this->roundScore($role);
                $rawScores[$dimension] = $this->roundScore($this->mean($activityValues[$dimension]));
            } else {
                $scores[$dimension] = $this->roundScore((($rawScores[$dimension] - 10.0) / 40.0) * 100.0);
            }
        }

        $ranked = $this->rankScores($scores);
        $topTypes = array_column($ranked, 'type');
        $topThree = array_slice($topTypes, 0, 3);
        $primary = (string) ($topThree[0] ?? '');
        $secondary = (string) ($topThree[1] ?? '');
        $tertiary = (string) ($topThree[2] ?? '');
        $topCode = implode('', $topThree);
        $clarity = $this->roundScore(((float) ($ranked[0]['score'] ?? 0.0)) - ((float) ($ranked[1]['score'] ?? 0.0)));
        $breadth = $this->roundScore(array_sum($scores) / max(1, count($scores)));
        $differentiation = $this->roundScore($this->standardDeviation(array_values($scores)));
        $quality = $isEnhanced
            ? $this->scoreQuality($normalizedAnswers)
            : ['grade' => 'A', 'flags' => [], 'attention' => ['grade' => 'A'], 'consistency' => ['grade' => 'A']];

        $payload = [
            'score_method' => $isEnhanced ? 'riasec_enhanced_140_v1' : 'riasec_standard_60_v1',
            'answer_count' => $requiredCount,
            'form_kind' => $isEnhanced ? 'enhanced' : 'standard',
            'top_code' => $topCode,
            'primary_type' => $primary,
            'secondary_type' => $secondary,
            'tertiary_type' => $tertiary,
            'scores_0_100' => $scores,
            'raw_scores' => $rawScores,
            'top_types' => $ranked,
            'clarity_index' => $clarity,
            'breadth_index' => $breadth,
            'differentiation_index' => $differentiation,
            'score_R' => $scores['R'],
            'score_I' => $scores['I'],
            'score_A' => $scores['A'],
            'score_S' => $scores['S'],
            'score_E' => $scores['E'],
            'score_C' => $scores['C'],
            'quality_grade' => (string) ($quality['grade'] ?? 'A'),
            'quality_flags' => is_array($quality['flags'] ?? null) ? $quality['flags'] : [],
            'quality' => $quality,
            'scoring_spec_version' => (string) ($policy['scoring_spec_version'] ?? ($isEnhanced ? 'riasec_enhanced_140_v1' : 'riasec_standard_60_v1')),
            'engine_version' => (string) ($policy['engine_version'] ?? 'riasec_v1.0.0'),
        ];

        if ($isEnhanced) {
            foreach (self::DIMENSIONS as $dimension) {
                $payload["activity_{$dimension}"] = $activityScores[$dimension];
                $payload["env_{$dimension}"] = $environmentScores[$dimension];
                $payload["role_{$dimension}"] = $roleScores[$dimension];
            }
            $payload['activity_scores'] = $activityScores;
            $payload['env_scores'] = $environmentScores;
            $payload['role_scores'] = $roleScores;
        }

        return $payload;
    }

    /**
     * @param  array<string,float>  $scores
     * @return list<array{type:string,score:float}>
     */
    private function rankScores(array $scores): array
    {
        $order = array_flip(self::DIMENSIONS);
        uksort($scores, static function (string $leftType, string $rightType) use ($scores, $order): int {
            $leftScore = (float) ($scores[$leftType] ?? 0.0);
            $rightScore = (float) ($scores[$rightType] ?? 0.0);
            if (abs($rightScore - $leftScore) > 0.00001) {
                return $rightScore <=> $leftScore;
            }

            return ($order[$leftType] ?? 0) <=> ($order[$rightType] ?? 0);
        });

        $ranked = [];
        foreach ($scores as $type => $score) {
            $ranked[] = ['type' => (string) $type, 'score' => $this->roundScore($score)];
        }

        return $ranked;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function standardizeMean(float $mean): float
    {
        if ($mean <= 0.0) {
            return 0.0;
        }

        return (($mean - 1.0) / 4.0) * 100.0;
    }

    /**
     * @param  list<float>  $values
     */
    private function standardDeviation(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += (($value - $mean) ** 2);
        }

        return sqrt($variance / count($values));
    }

    /**
     * @param  array<int,int>  $answers
     * @return array<string,mixed>
     */
    private function scoreQuality(array $answers): array
    {
        $flags = [];
        $attentionPasses = 0;
        if (($answers[133] ?? null) === 3) {
            $attentionPasses++;
        } else {
            $flags[] = 'attention_133_failed';
        }
        if (($answers[137] ?? null) === 2) {
            $attentionPasses++;
        } else {
            $flags[] = 'attention_137_failed';
        }

        $attentionGrade = $attentionPasses === 2 ? 'A' : ($attentionPasses === 1 ? 'B' : 'C');
        $consistencyDiffs = [
            abs(($answers[13] ?? 0) - ($answers[138] ?? 0)),
            abs(($answers[31] ?? 0) - ($answers[139] ?? 0)),
            abs(($answers[51] ?? 0) - ($answers[140] ?? 0)),
        ];
        $avgDiff = array_sum($consistencyDiffs) / count($consistencyDiffs);
        $consistencyGrade = $avgDiff <= 1.0 ? 'A' : ($avgDiff <= 1.5 ? 'B' : 'C');
        if ($consistencyGrade === 'C') {
            $flags[] = 'low_consistency';
        }
        if (($answers[134] ?? 0) >= 4) {
            $flags[] = 'broad_agreement';
        }
        $idealizationCount = 0;
        foreach ([135, 136] as $qid) {
            if (($answers[$qid] ?? 0) >= 4) {
                $idealizationCount++;
            }
        }
        if ($idealizationCount > 0) {
            $flags[] = $idealizationCount >= 2 ? 'strong_idealization' : 'idealization';
        }

        $grade = 'A';
        if ($attentionGrade === 'C' || $consistencyGrade === 'C' || $idealizationCount >= 2) {
            $grade = 'C';
        } elseif ($attentionGrade === 'B' || $consistencyGrade === 'B' || $flags !== []) {
            $grade = 'B';
        }

        return [
            'grade' => $grade,
            'flags' => array_values(array_unique($flags)),
            'attention' => [
                'grade' => $attentionGrade,
                'passed' => $attentionPasses,
                'expected' => ['133' => 3, '137' => 2],
            ],
            'consistency' => [
                'grade' => $consistencyGrade,
                'average_abs_diff' => $this->roundScore($avgDiff),
                'pairs' => ['13_138', '31_139', '51_140'],
            ],
        ];
    }

    private function roundScore(float $score): float
    {
        return round(max(0.0, min(100.0, $score)), 2);
    }
}
