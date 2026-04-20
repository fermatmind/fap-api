<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

final class EnneagramForcedChoice144Scorer
{
    /**
     * @param  array<int,string>  $answersByQid
     * @param  array<int,array<string,mixed>>  $questionIndex
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function score(array $answersByQid, array $questionIndex, array $policy): array
    {
        if (count($questionIndex) !== 144) {
            throw new \InvalidArgumentException('ENNEAGRAM 144 question index invalid.');
        }

        $rawCounts = $this->zeroTypeMap();
        $pairCounts = [];
        $roundCounts = [];
        $rawAnswers = [];

        foreach ($questionIndex as $qid => $meta) {
            $questionId = (int) $qid;
            if (! array_key_exists($questionId, $answersByQid)) {
                throw new \InvalidArgumentException("ENNEAGRAM 144 missing answer for question_id={$questionId}");
            }

            $choice = strtoupper(trim((string) $answersByQid[$questionId]));
            if (! in_array($choice, ['A', 'B'], true)) {
                throw new \InvalidArgumentException("ENNEAGRAM 144 invalid answer for question_id={$questionId}");
            }

            $typeCode = $this->normalizeTypeCode($meta[strtolower($choice).'_type'] ?? '');
            if ($typeCode === '') {
                throw new \InvalidArgumentException("ENNEAGRAM 144 key missing for question_id={$questionId}");
            }

            $rawCounts[$typeCode]++;
            $pair = trim((string) ($meta['pair'] ?? ''));
            if ($pair !== '') {
                $pairCounts[$pair] = ($pairCounts[$pair] ?? 0) + 1;
            }
            $round = (string) ((int) ($meta['round'] ?? 0));
            if ($round !== '0') {
                $roundCounts[$round][$typeCode] = (int) ($roundCounts[$round][$typeCode] ?? 0) + 1;
            }
            $rawAnswers[$questionId] = $choice;
        }

        $scoresPct = [];
        foreach ($rawCounts as $typeCode => $count) {
            $scoresPct[$typeCode] = round(($count / 32.0) * 100.0, 2);
        }

        $ranked = $this->rankTypes($rawCounts, $scoresPct);
        $top = $ranked[0] ?? ['type_code' => '', 'raw_count' => 0];
        $second = $ranked[1] ?? ['type_code' => '', 'raw_count' => 0];
        $gap = (int) ($top['raw_count'] ?? 0) - (int) ($second['raw_count'] ?? 0);

        return [
            'scale_code' => 'ENNEAGRAM',
            'form_code' => 'enneagram_forced_choice_144',
            'score_method' => 'enneagram_forced_choice_144_pair_v1',
            'engine_version' => (string) ($policy['engine_version'] ?? 'enneagram_forced_choice_144_v1.0.0'),
            'scoring_spec_version' => (string) ($policy['scoring_spec_version'] ?? 'enneagram_forced_choice_144_spec_v1'),
            'answer_count' => count($rawAnswers),
            'raw_scores' => [
                'type_counts' => $rawCounts,
                'pair_answer_counts' => $pairCounts,
                'round_type_counts' => $roundCounts,
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
     * @return array<string,int>
     */
    private function zeroTypeMap(): array
    {
        return [
            'T1' => 0,
            'T2' => 0,
            'T3' => 0,
            'T4' => 0,
            'T5' => 0,
            'T6' => 0,
            'T7' => 0,
            'T8' => 0,
            'T9' => 0,
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
     * @param  array<string,int>  $rawCounts
     * @param  array<string,float>  $scoresPct
     * @return list<array{type_code:string,raw_count:int,score_pct:float,rank:int}>
     */
    private function rankTypes(array $rawCounts, array $scoresPct): array
    {
        $rows = [];
        foreach ($rawCounts as $typeCode => $count) {
            $rows[] = [
                'type_code' => $typeCode,
                'raw_count' => (int) $count,
                'score_pct' => (float) ($scoresPct[$typeCode] ?? 0.0),
                'rank' => 0,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $countCompare = ((int) $b['raw_count']) <=> ((int) $a['raw_count']);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp((string) $a['type_code'], (string) $b['type_code']);
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function confidenceLevel(int $gap, array $policy): string
    {
        $thresholds = is_array($policy['confidence_thresholds'] ?? null) ? $policy['confidence_thresholds'] : [];
        $high = (int) ($thresholds['high'] ?? 4);
        $medium = (int) ($thresholds['medium'] ?? 2);

        if ($gap >= $high) {
            return 'high';
        }
        if ($gap >= $medium) {
            return 'medium';
        }

        return 'low';
    }
}
