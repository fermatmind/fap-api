<?php

declare(strict_types=1);

namespace App\Services\Enneagram;

final class EnneagramTopologyAnalyzer
{
    private const TYPE_ORDER = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'];

    /**
     * @var array<string,list<string>>
     */
    private const RING_NEIGHBORS = [
        'T1' => ['T9', 'T2'],
        'T2' => ['T1', 'T3'],
        'T3' => ['T2', 'T4'],
        'T4' => ['T3', 'T5'],
        'T5' => ['T4', 'T6'],
        'T6' => ['T5', 'T7'],
        'T7' => ['T6', 'T8'],
        'T8' => ['T7', 'T9'],
        'T9' => ['T8', 'T1'],
    ];

    /**
     * @var array<string,list<string>>
     */
    private const LINE_CONNECTIONS = [
        'T1' => ['T4', 'T7'],
        'T2' => ['T4', 'T8'],
        'T3' => ['T6', 'T9'],
        'T4' => ['T1', 'T2'],
        'T5' => ['T7', 'T8'],
        'T6' => ['T3', 'T9'],
        'T7' => ['T1', 'T5'],
        'T8' => ['T2', 'T5'],
        'T9' => ['T3', 'T6'],
    ];

    /**
     * @param  array<string,float>  $rawIntensity
     * @param  array<string,float>  $dominance
     * @param  list<array<string,mixed>>  $ranking
     * @param  array<string,mixed>  $quality
     * @return array<string,mixed>
     */
    public function analyzeLikert105(array $rawIntensity, array $dominance, array $ranking, array $quality): array
    {
        $coreType = $this->normalizeTypeCode($ranking[0]['type_code'] ?? '');
        $runnerUp = $this->normalizeTypeCode($ranking[1]['type_code'] ?? '');
        $topDominance = (float) ($ranking[0]['dominance'] ?? 0.0);
        $runnerUpDominance = (float) ($ranking[1]['dominance'] ?? 0.0);
        $scoreSeparation = round($topDominance - $runnerUpDominance, 6);
        $neighbors = self::RING_NEIGHBORS[$coreType] ?? [];
        $wingCandidate = $this->resolveWingCandidate($neighbors, $dominance);
        $relation = $this->runnerUpRelation($coreType, $runnerUp);
        $responseQuality = $this->responseQualitySummary($quality);
        $hasHardQualityIssue = ((string) ($responseQuality['level'] ?? 'clean')) === 'hard';
        $interpretationState = $this->likertInterpretationState($scoreSeparation, $relation, $hasHardQualityIssue);
        $confidenceBand = $this->confidenceBand($interpretationState, $hasHardQualityIssue);

        return [
            'core_type' => $coreType,
            'top3' => array_values(array_map(
                static fn (array $row): string => (string) ($row['type_code'] ?? ''),
                array_slice($ranking, 0, 3)
            )),
            'wing_candidate' => $wingCandidate,
            'wing_neighbor_scores' => $this->neighborScores($neighbors, $rawIntensity, $dominance),
            'runner_up' => $runnerUp,
            'topology_relation_of_runner_up' => $relation,
            'score_separation' => $scoreSeparation,
            'interpretation_state' => $interpretationState,
            'confidence_band' => $confidenceBand,
            'response_quality_summary' => $responseQuality,
        ];
    }

    /**
     * @param  array<string,int>  $wins
     * @param  array<string,int>  $exposures
     * @param  list<array<string,mixed>>  $ranking
     * @param  array<string,array<string,array{wins:int,losses:int,encounters:int}>>  $headToHead
     * @param  array<string,mixed>  $quality
     * @return array{analysis:array<string,mixed>,primary_type:string,ranking:list<array<string,mixed>>}
     */
    public function analyzeForcedChoice144(array $wins, array $exposures, array $ranking, array $headToHead, array $quality): array
    {
        $topWins = (int) ($ranking[0]['raw_count'] ?? 0);
        $tiedLeaders = array_values(array_filter(
            self::TYPE_ORDER,
            static fn (string $typeCode): bool => (int) ($wins[$typeCode] ?? 0) === $topWins
        ));
        $primaryType = (string) ($ranking[0]['type_code'] ?? '');
        $tieBreakStatus = count($tiedLeaders) > 1 ? 'unresolved_after_head_to_head' : 'not_needed';
        $unresolvedTie = false;
        $closeCallCandidates = count($tiedLeaders) > 1 ? $tiedLeaders : [];
        $tieBreakScores = [];

        if (count($tiedLeaders) > 1) {
            $tieBreakScores = $this->tieBreakScores($tiedLeaders, $headToHead);
            $maxTieBreakWins = max($tieBreakScores);
            $resolved = array_values(array_filter(
                array_keys($tieBreakScores),
                static fn (string $typeCode): bool => $tieBreakScores[$typeCode] === $maxTieBreakWins
            ));

            if (count($resolved) === 1) {
                $primaryType = $resolved[0];
                $tieBreakStatus = 'resolved_by_head_to_head';
                $closeCallCandidates = $tiedLeaders;
                $ranking = $this->rerankWithPrimary($ranking, $primaryType);
            } else {
                $unresolvedTie = true;
                $primaryType = (string) ($ranking[0]['type_code'] ?? '');
            }
        }

        $secondWins = (int) ($ranking[1]['raw_count'] ?? 0);
        $scoreSeparation = max(0, $topWins - $secondWins);
        $responseQuality = $this->responseQualitySummary($quality);
        $hasHardQualityIssue = ((string) ($responseQuality['level'] ?? 'clean')) === 'hard';
        $interpretationState = $unresolvedTie ? 'forced_choice_close_call' : 'standard_primary';
        $confidenceBand = $unresolvedTie || $hasHardQualityIssue
            ? 'low'
            : ($scoreSeparation >= 4 ? 'high' : ($scoreSeparation >= 2 ? 'medium' : 'medium'));

        return [
            'analysis' => [
                'core_type' => $primaryType,
                'top3' => array_values(array_map(
                    static fn (array $row): string => (string) ($row['type_code'] ?? ''),
                    array_slice($ranking, 0, 3)
                )),
                'score_separation' => $scoreSeparation,
                'tie_break_status' => $tieBreakStatus,
                'unresolved_tie' => $unresolvedTie,
                'close_call_candidates' => $closeCallCandidates,
                'tie_break_scores' => $tieBreakScores,
                'interpretation_state' => $interpretationState,
                'confidence_band' => $confidenceBand,
                'response_quality_summary' => $responseQuality,
                'exposures' => $exposures,
            ],
            'primary_type' => $primaryType,
            'ranking' => $ranking,
        ];
    }

    /**
     * @param  list<string>  $neighbors
     * @param  array<string,float>  $dominance
     */
    private function resolveWingCandidate(array $neighbors, array $dominance): string
    {
        $candidate = '';
        $candidateScore = null;
        foreach ($neighbors as $typeCode) {
            $score = (float) ($dominance[$typeCode] ?? 0.0);
            if ($candidateScore === null || $score > $candidateScore) {
                $candidate = $typeCode;
                $candidateScore = $score;
            }
        }

        return $candidate;
    }

    private function runnerUpRelation(string $coreType, string $runnerUp): string
    {
        if ($coreType === '' || $runnerUp === '') {
            return 'other';
        }
        if (in_array($runnerUp, self::RING_NEIGHBORS[$coreType] ?? [], true)) {
            return 'adjacent';
        }
        if (in_array($runnerUp, self::LINE_CONNECTIONS[$coreType] ?? [], true)) {
            return 'line_connected';
        }

        return 'other';
    }

    /**
     * @param  list<string>  $neighbors
     * @param  array<string,float>  $rawIntensity
     * @param  array<string,float>  $dominance
     * @return array<string,array{raw_intensity:float,dominance:float}>
     */
    private function neighborScores(array $neighbors, array $rawIntensity, array $dominance): array
    {
        $out = [];
        foreach ($neighbors as $typeCode) {
            $out[$typeCode] = [
                'raw_intensity' => round((float) ($rawIntensity[$typeCode] ?? 0.0), 6),
                'dominance' => round((float) ($dominance[$typeCode] ?? 0.0), 6),
            ];
        }

        return $out;
    }

    private function likertInterpretationState(float $scoreSeparation, string $relation, bool $hasHardQualityIssue): string
    {
        if ($scoreSeparation < 0.15) {
            if ($relation === 'adjacent') {
                return 'wing_heavy';
            }
            if ($relation === 'line_connected') {
                return 'line_tension';
            }

            return 'mixed_close_call';
        }

        if ($scoreSeparation >= 0.30 && ! $hasHardQualityIssue) {
            return 'clear_primary';
        }

        return 'standard_primary';
    }

    private function confidenceBand(string $interpretationState, bool $hasHardQualityIssue): string
    {
        if ($hasHardQualityIssue || $interpretationState === 'mixed_close_call') {
            return 'low';
        }
        if ($interpretationState === 'clear_primary') {
            return 'high';
        }

        return 'medium';
    }

    /**
     * @param  array<string,mixed>  $quality
     * @return array{level:string,soft_flags:list<string>,hard_flags:list<string>,flags:list<string>}
     */
    private function responseQualitySummary(array $quality): array
    {
        $flags = array_values(array_filter(array_map('strval', is_array($quality['flags'] ?? null) ? $quality['flags'] : [])));
        $level = strtoupper(trim((string) ($quality['level'] ?? 'P0')));
        $hardFlags = $level !== 'P0' && $level !== 'CLEAN' ? $flags : [];
        $softFlags = $level === 'P0' || $level === 'CLEAN' ? $flags : [];
        $summaryLevel = $hardFlags !== [] ? 'hard' : ($softFlags !== [] ? 'soft' : 'clean');

        return [
            'level' => $summaryLevel,
            'soft_flags' => $softFlags,
            'hard_flags' => $hardFlags,
            'flags' => $flags,
        ];
    }

    /**
     * @param  list<string>  $tiedLeaders
     * @param  array<string,array<string,array{wins:int,losses:int,encounters:int}>>  $headToHead
     * @return array<string,int>
     */
    private function tieBreakScores(array $tiedLeaders, array $headToHead): array
    {
        $scores = array_fill_keys($tiedLeaders, 0);
        foreach ($tiedLeaders as $typeCode) {
            foreach ($tiedLeaders as $opponent) {
                if ($typeCode === $opponent) {
                    continue;
                }
                $scores[$typeCode] += (int) ($headToHead[$typeCode][$opponent]['wins'] ?? 0);
            }
        }

        return $scores;
    }

    /**
     * @param  list<array<string,mixed>>  $ranking
     * @return list<array<string,mixed>>
     */
    private function rerankWithPrimary(array $ranking, string $primaryType): array
    {
        usort($ranking, static function (array $a, array $b) use ($primaryType): int {
            $aType = (string) ($a['type_code'] ?? '');
            $bType = (string) ($b['type_code'] ?? '');
            if ($aType === $primaryType && $bType !== $primaryType) {
                return -1;
            }
            if ($bType === $primaryType && $aType !== $primaryType) {
                return 1;
            }

            $countCompare = ((int) ($b['raw_count'] ?? 0)) <=> ((int) ($a['raw_count'] ?? 0));
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp($aType, $bType);
        });

        foreach ($ranking as $index => $row) {
            $ranking[$index]['rank'] = $index + 1;
        }

        return $ranking;
    }

    private function normalizeTypeCode(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (preg_match('/^T([1-9])$/', $value, $matches) !== 1) {
            return '';
        }

        return 'T'.$matches[1];
    }
}
