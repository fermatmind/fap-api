<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

use App\Services\Enneagram\EnneagramTopologyAnalyzer;

final class EnneagramForcedChoice144Scorer
{
    public function __construct(
        private readonly EnneagramTopologyAnalyzer $topologyAnalyzer,
    ) {}

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
        $exposures = $this->zeroTypeMap();
        $headToHead = $this->emptyHeadToHeadMatrix();
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

            $aType = $this->normalizeTypeCode($meta['a_type'] ?? '');
            $bType = $this->normalizeTypeCode($meta['b_type'] ?? '');
            $typeCode = $this->normalizeTypeCode($meta[strtolower($choice).'_type'] ?? '');
            if ($typeCode === '' || $aType === '' || $bType === '' || ! in_array($typeCode, [$aType, $bType], true)) {
                throw new \InvalidArgumentException("ENNEAGRAM 144 key missing for question_id={$questionId}");
            }

            $rawCounts[$typeCode]++;
            $exposures[$aType]++;
            $exposures[$bType]++;
            $loserType = $typeCode === $aType ? $bType : $aType;
            $headToHead[$typeCode][$loserType]['wins']++;
            $headToHead[$typeCode][$loserType]['encounters']++;
            $headToHead[$loserType][$typeCode]['losses']++;
            $headToHead[$loserType][$typeCode]['encounters']++;
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
        $quality = [
            'level' => 'P0',
            'flags' => [],
        ];
        $analysisResult = $this->topologyAnalyzer->analyzeForcedChoice144($rawCounts, $exposures, $ranked, $headToHead, $quality);
        $ranked = $analysisResult['ranking'];
        $analysis = $analysisResult['analysis'];
        $top = $ranked[0] ?? ['type_code' => '', 'raw_count' => 0];
        $second = $ranked[1] ?? ['type_code' => '', 'raw_count' => 0];
        $gap = (int) ($analysis['score_separation'] ?? ((int) ($top['raw_count'] ?? 0) - (int) ($second['raw_count'] ?? 0)));
        $display = [
            'preference100' => $this->preference100($rawCounts, $exposures),
            'chart_vector' => $this->chartVector($ranked, $this->preference100($rawCounts, $exposures), 'preference100'),
            'score_kind' => 'win_rate_preference100',
            'score_note' => 'Preference display scores are wins divided by exposures; they are not T-scores, percentiles, or standardized scores.',
        ];

        return [
            'scale_code' => 'ENNEAGRAM',
            'form_code' => 'enneagram_forced_choice_144',
            'score_method' => 'enneagram_forced_choice_144_pair_v1',
            'engine_version' => (string) ($policy['engine_version'] ?? 'enneagram_forced_choice_144_v1.0.0'),
            'scoring_spec_version' => (string) ($policy['scoring_spec_version'] ?? 'enneagram_forced_choice_144_spec_v1'),
            'answer_count' => count($rawAnswers),
            'raw_scores' => [
                'type_counts' => $rawCounts,
                'exposures' => $exposures,
                'pair_answer_counts' => $pairCounts,
                'round_type_counts' => $roundCounts,
            ],
            'scores_0_100' => $scoresPct,
            'scoring' => [
                'wins' => $rawCounts,
                'exposures' => $exposures,
                'head_to_head' => $headToHead,
            ],
            'analysis' => $analysis,
            'display' => $display,
            'ranking' => $ranked,
            'primary_type' => (string) ($analysisResult['primary_type'] ?? ($top['type_code'] ?? '')),
            'top_types' => array_slice($ranked, 0, 3),
            'confidence' => [
                'level' => (string) ($analysis['confidence_band'] ?? $this->confidenceLevel($gap, $policy)),
                'top1_top2_gap' => $gap,
                'score_separation' => $analysis['score_separation'] ?? $gap,
                'interpretation_state' => $analysis['interpretation_state'] ?? 'standard_primary',
            ],
            'quality' => $quality,
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
     * @return array<string,array<string,array{wins:int,losses:int,encounters:int}>>
     */
    private function emptyHeadToHeadMatrix(): array
    {
        $matrix = [];
        foreach (array_keys($this->zeroTypeMap()) as $typeCode) {
            foreach (array_keys($this->zeroTypeMap()) as $opponent) {
                if ($typeCode === $opponent) {
                    continue;
                }
                $matrix[$typeCode][$opponent] = [
                    'wins' => 0,
                    'losses' => 0,
                    'encounters' => 0,
                ];
            }
        }

        return $matrix;
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

    /**
     * @param  array<string,int>  $wins
     * @param  array<string,int>  $exposures
     * @return array<string,int|null>
     */
    private function preference100(array $wins, array $exposures): array
    {
        $out = [];
        foreach ($wins as $typeCode => $count) {
            $exposure = (int) ($exposures[$typeCode] ?? 0);
            $out[$typeCode] = $exposure > 0 ? (int) round(100.0 * ((int) $count) / $exposure) : null;
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $ranked
     * @param  array<string,int|float|null>  $scores
     * @return list<array<string,mixed>>
     */
    private function chartVector(array $ranked, array $scores, string $scoreKey): array
    {
        $out = [];
        foreach ($ranked as $row) {
            $typeCode = (string) ($row['type_code'] ?? '');
            if ($typeCode === '') {
                continue;
            }
            $out[] = [
                'type_code' => $typeCode,
                $scoreKey => $scores[$typeCode] ?? null,
                'rank' => (int) ($row['rank'] ?? 0),
            ];
        }

        return $out;
    }
}
