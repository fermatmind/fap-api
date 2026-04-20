<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

final class EnneagramLikert105Scorer
{
    /**
     * @param  array<int,int>  $answersByQid
     * @param  array<int,array<string,mixed>>  $questionIndex
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function score(array $answersByQid, array $questionIndex, array $policy): array
    {
        if (count($questionIndex) !== 105) {
            throw new \InvalidArgumentException('ENNEAGRAM 105 question index invalid.');
        }

        $weightedSums = $this->zeroTypeMap();
        $weightSums = $this->zeroTypeMap();
        $rawAnswers = [];

        foreach ($questionIndex as $qid => $meta) {
            $questionId = (int) $qid;
            if (! array_key_exists($questionId, $answersByQid)) {
                throw new \InvalidArgumentException("ENNEAGRAM 105 missing answer for question_id={$questionId}");
            }

            $value = (int) $answersByQid[$questionId];
            if ($value < -2 || $value > 2) {
                throw new \InvalidArgumentException("ENNEAGRAM 105 invalid answer for question_id={$questionId}");
            }

            $weights = is_array($meta['type_weights'] ?? null) ? $meta['type_weights'] : [];
            if ($weights === []) {
                throw new \InvalidArgumentException("ENNEAGRAM 105 weights missing for question_id={$questionId}");
            }

            foreach ($weights as $typeCodeRaw => $weightRaw) {
                $typeCode = $this->normalizeTypeCode($typeCodeRaw);
                if ($typeCode === '') {
                    continue;
                }

                $weight = (float) $weightRaw;
                if ($weight === 0.0) {
                    continue;
                }

                $weightedSums[$typeCode] += $value * $weight;
                $weightSums[$typeCode] += abs($weight);
            }
            $rawAnswers[$questionId] = $value;
        }

        $rawIntensity = [];
        foreach ($weightedSums as $typeCode => $sum) {
            $denominator = (float) ($weightSums[$typeCode] ?? 0.0);
            $rawIntensity[$typeCode] = $denominator > 0.0 ? round($sum / $denominator, 6) : 0.0;
        }

        $mean = array_sum($rawIntensity) / 9.0;
        $dominance = [];
        $scoresPct = [];
        foreach ($rawIntensity as $typeCode => $score) {
            $dominance[$typeCode] = round($score - $mean, 6);
            $scoresPct[$typeCode] = round((($score + 2.0) / 4.0) * 100.0, 2);
        }

        $ranked = $this->rankTypes($dominance, $rawIntensity);
        $top = $ranked[0] ?? ['type_code' => '', 'dominance' => 0.0];
        $second = $ranked[1] ?? ['type_code' => '', 'dominance' => 0.0];
        $gap = round((float) ($top['dominance'] ?? 0.0) - (float) ($second['dominance'] ?? 0.0), 6);

        return [
            'scale_code' => 'ENNEAGRAM',
            'form_code' => 'enneagram_likert_105',
            'score_method' => 'enneagram_likert_105_weighted_v1',
            'engine_version' => (string) ($policy['engine_version'] ?? 'enneagram_likert_105_v1.0.0'),
            'scoring_spec_version' => (string) ($policy['scoring_spec_version'] ?? 'enneagram_likert_105_spec_v1'),
            'answer_count' => count($rawAnswers),
            'raw_scores' => [
                'weighted_sum' => $this->roundMap($weightedSums),
                'weight_sum' => $this->roundMap($weightSums),
                'raw_intensity' => $rawIntensity,
                'dominance' => $dominance,
            ],
            'scores_0_100' => $scoresPct,
            'ranking' => $ranked,
            'primary_type' => (string) ($top['type_code'] ?? ''),
            'top_types' => array_slice($ranked, 0, 3),
            'confidence' => [
                'level' => $this->confidenceLevel($gap, $policy),
                'top1_top2_gap' => $gap,
            ],
            'quality' => [
                'level' => 'P0',
                'flags' => [],
            ],
        ];
    }

    /**
     * @return array<string,float>
     */
    private function zeroTypeMap(): array
    {
        return [
            'T1' => 0.0,
            'T2' => 0.0,
            'T3' => 0.0,
            'T4' => 0.0,
            'T5' => 0.0,
            'T6' => 0.0,
            'T7' => 0.0,
            'T8' => 0.0,
            'T9' => 0.0,
        ];
    }

    private function normalizeTypeCode(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (preg_match('/^T([1-9])$/', $value, $matches) !== 1) {
            return '';
        }

        return 'T'.$matches[1];
    }

    /**
     * @param  array<string,float>  $dominance
     * @param  array<string,float>  $rawIntensity
     * @return list<array{type_code:string,raw_intensity:float,dominance:float,rank:int}>
     */
    private function rankTypes(array $dominance, array $rawIntensity): array
    {
        $rows = [];
        foreach ($dominance as $typeCode => $score) {
            $rows[] = [
                'type_code' => $typeCode,
                'raw_intensity' => (float) ($rawIntensity[$typeCode] ?? 0.0),
                'dominance' => (float) $score,
                'rank' => 0,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $dominanceCompare = ((float) $b['dominance']) <=> ((float) $a['dominance']);
            if ($dominanceCompare !== 0) {
                return $dominanceCompare;
            }

            return strcmp((string) $a['type_code'], (string) $b['type_code']);
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
        }

        return $rows;
    }

    /**
     * @param  array<string,float>  $map
     * @return array<string,float>
     */
    private function roundMap(array $map): array
    {
        return array_map(static fn (float $value): float => round($value, 6), $map);
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function confidenceLevel(float $gap, array $policy): string
    {
        $thresholds = is_array($policy['confidence_thresholds'] ?? null) ? $policy['confidence_thresholds'] : [];
        $high = (float) ($thresholds['high'] ?? 0.30);
        $medium = (float) ($thresholds['medium'] ?? 0.15);

        if ($gap >= $high) {
            return 'high';
        }
        if ($gap >= $medium) {
            return 'medium';
        }

        return 'low';
    }
}
