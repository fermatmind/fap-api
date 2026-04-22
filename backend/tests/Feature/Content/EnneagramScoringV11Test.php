<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Assessment\Scorers\EnneagramForcedChoice144Scorer;
use App\Services\Assessment\Scorers\EnneagramLikert105Scorer;
use App\Services\Enneagram\EnneagramPublicProjectionService;
use App\Services\Enneagram\EnneagramTopologyAnalyzer;
use Tests\TestCase;

final class EnneagramScoringV11Test extends TestCase
{
    public function test_likert_105_uses_adjacent_wing_and_wing_heavy_interpretation(): void
    {
        $scorer = new EnneagramLikert105Scorer(new EnneagramTopologyAnalyzer);
        [$answers, $questionIndex] = $this->likertFixture([
            'T3' => array_fill(0, 12, 2),
            'T4' => array_merge(array_fill(0, 11, 2), [1]),
            'T8' => array_fill(0, 11, 0),
        ]);

        $result = $scorer->score($answers, $questionIndex, []);

        $this->assertSame('T3', $result['primary_type']);
        $this->assertSame('T4', data_get($result, 'analysis.wing_candidate'));
        $this->assertSame('T4', data_get($result, 'analysis.runner_up'));
        $this->assertSame('adjacent', data_get($result, 'analysis.topology_relation_of_runner_up'));
        $this->assertSame('wing_heavy', data_get($result, 'analysis.interpretation_state'));
        $this->assertLessThan(0.15, (float) data_get($result, 'analysis.score_separation'));
        $this->assertSame('medium', data_get($result, 'analysis.confidence_band'));
        $this->assertSame('medium', data_get($result, 'confidence.level'));
        $this->assertNotSame(data_get($result, 'confidence.top1_top2_gap'), data_get($result, 'confidence.level'));
    }

    public function test_likert_105_marks_non_topological_runner_up_as_mixed_close_call(): void
    {
        $scorer = new EnneagramLikert105Scorer(new EnneagramTopologyAnalyzer);
        [$answers, $questionIndex] = $this->likertFixture([
            'T3' => array_fill(0, 12, 2),
            'T8' => array_merge(array_fill(0, 10, 2), [1]),
            'T4' => array_fill(0, 12, 0),
        ]);

        $result = $scorer->score($answers, $questionIndex, []);

        $this->assertSame('T3', $result['primary_type']);
        $this->assertSame('T4', data_get($result, 'analysis.wing_candidate'));
        $this->assertSame('T8', data_get($result, 'analysis.runner_up'));
        $this->assertSame('other', data_get($result, 'analysis.topology_relation_of_runner_up'));
        $this->assertSame('mixed_close_call', data_get($result, 'analysis.interpretation_state'));
        $this->assertSame('low', data_get($result, 'analysis.confidence_band'));
    }

    public function test_forced_choice_144_resolves_tied_leaders_by_head_to_head(): void
    {
        $scorer = new EnneagramForcedChoice144Scorer(new EnneagramTopologyAnalyzer);
        [$answers, $questionIndex] = $this->forcedChoiceFixture(
            winnerSequence: array_merge(
                array_fill(0, 19, 'T1'),
                array_fill(0, 18, 'T2'),
                $this->nonLeaderWins(104)
            ),
            headToHeadWinners: ['T2', 'T2', 'T1']
        );

        $result = $scorer->score($answers, $questionIndex, []);

        $this->assertSame('resolved_by_head_to_head', data_get($result, 'analysis.tie_break_status'));
        $this->assertFalse((bool) data_get($result, 'analysis.unresolved_tie'));
        $this->assertSame('T2', $result['primary_type']);
        $this->assertSame('T2', data_get($result, 'analysis.core_type'));
    }

    public function test_forced_choice_144_outputs_unresolved_tie_when_head_to_head_stays_tied(): void
    {
        $scorer = new EnneagramForcedChoice144Scorer(new EnneagramTopologyAnalyzer);
        [$answers, $questionIndex] = $this->forcedChoiceFixture(
            winnerSequence: array_merge(
                array_fill(0, 19, 'T1'),
                array_fill(0, 19, 'T2'),
                $this->nonLeaderWins(104)
            ),
            headToHeadWinners: ['T1', 'T2']
        );

        $result = $scorer->score($answers, $questionIndex, []);

        $this->assertSame('unresolved_after_head_to_head', data_get($result, 'analysis.tie_break_status'));
        $this->assertTrue((bool) data_get($result, 'analysis.unresolved_tie'));
        $this->assertSame(['T1', 'T2'], data_get($result, 'analysis.close_call_candidates'));
        $this->assertSame('low', data_get($result, 'analysis.confidence_band'));
        $this->assertSame('forced_choice_close_call', data_get($result, 'analysis.interpretation_state'));
        $this->assertSame('T1', $result['primary_type']);
    }

    public function test_projection_separates_scoring_analysis_and_display_scores(): void
    {
        $likertScorer = new EnneagramLikert105Scorer(new EnneagramTopologyAnalyzer);
        [$likertAnswers, $likertQuestionIndex] = $this->likertFixture([
            'T3' => array_fill(0, 12, 2),
            'T4' => array_merge(array_fill(0, 11, 2), [1]),
        ]);
        $likert = $likertScorer->score($likertAnswers, $likertQuestionIndex, []);
        $projection = (new EnneagramPublicProjectionService)->build($likert, 'en');

        $this->assertArrayHasKey('scoring', $projection);
        $this->assertArrayHasKey('analysis', $projection);
        $this->assertArrayHasKey('display', $projection);
        $this->assertSame(100, data_get($projection, 'display.profile100.T3'));
        $this->assertSame(98, data_get($projection, 'display.profile100.T4'));
        $this->assertSame(2.0, data_get($projection, 'scoring.raw.T3'));
        $this->assertSame('wing_heavy', data_get($projection, 'analysis.interpretation_state'));
        $this->assertTrue((bool) data_get($projection, 'display.not_percentile'));
        $this->assertTrue((bool) data_get($projection, 'display.not_t_score'));
        $this->assertSame('T3', $projection['primary_type']);
        $this->assertArrayHasKey('score_pct', $projection['type_vector'][0]);

        $forcedScorer = new EnneagramForcedChoice144Scorer(new EnneagramTopologyAnalyzer);
        [$forcedAnswers, $forcedQuestionIndex] = $this->forcedChoiceFixture(
            winnerSequence: array_merge(
                array_fill(0, 19, 'T1'),
                array_fill(0, 18, 'T2'),
                $this->nonLeaderWins(104)
            ),
            headToHeadWinners: ['T2', 'T2', 'T1']
        );
        $forced = $forcedScorer->score($forcedAnswers, $forcedQuestionIndex, []);
        $forcedProjection = (new EnneagramPublicProjectionService)->build($forced, 'en');

        $this->assertSame(
            (int) round(100 * data_get($forcedProjection, 'scoring.wins.T2') / data_get($forcedProjection, 'scoring.exposures.T2')),
            data_get($forcedProjection, 'display.preference100.T2')
        );
        $this->assertSame(
            (int) round(100 * data_get($forcedProjection, 'scoring.wins.T1') / data_get($forcedProjection, 'scoring.exposures.T1')),
            data_get($forcedProjection, 'display.preference100.T1')
        );
        $this->assertSame('resolved_by_head_to_head', data_get($forcedProjection, 'analysis.tie_break_status'));
        $this->assertSame('preference100', data_get($forcedProjection, '_meta.display_score_semantics.display_score'));
    }

    /**
     * @param  array<string,list<int>>  $overrides
     * @return array{0:array<int,int>,1:array<int,array<string,mixed>>}
     */
    private function likertFixture(array $overrides): array
    {
        $typeCounts = [
            'T1' => 12,
            'T2' => 12,
            'T3' => 12,
            'T4' => 12,
            'T5' => 12,
            'T6' => 12,
            'T7' => 11,
            'T8' => 11,
            'T9' => 11,
        ];
        $answers = [];
        $questionIndex = [];
        $qid = 1;
        foreach ($typeCounts as $typeCode => $count) {
            $values = $overrides[$typeCode] ?? array_fill(0, $count, -1);
            for ($i = 0; $i < $count; $i++) {
                $questionIndex[$qid] = [
                    'question_id' => $qid,
                    'type_weights' => [$typeCode => 1],
                ];
                $answers[$qid] = (int) ($values[$i] ?? -1);
                $qid++;
            }
        }

        return [$answers, $questionIndex];
    }

    /**
     * @param  list<string>  $winnerSequence
     * @param  list<string>  $headToHeadWinners
     * @return array{0:array<int,string>,1:array<int,array<string,mixed>>}
     */
    private function forcedChoiceFixture(array $winnerSequence, array $headToHeadWinners): array
    {
        $answers = [];
        $questionIndex = [];
        $winnerIndex = 0;
        for ($qid = 1; $qid <= 144; $qid++) {
            if ($qid <= count($headToHeadWinners)) {
                $aType = 'T1';
                $bType = 'T2';
                $winner = $headToHeadWinners[$qid - 1];
            } else {
                $winner = $winnerSequence[$winnerIndex] ?? 'T3';
                $winnerIndex++;
                $aType = $winner;
                $bType = $winner === 'T9' ? 'T8' : 'T9';
            }

            $questionIndex[$qid] = [
                'question_id' => $qid,
                'a_type' => $aType,
                'b_type' => $bType,
                'pair' => $this->pairKey($aType, $bType),
                'round' => 1,
            ];
            $answers[$qid] = $winner === $aType ? 'A' : 'B';
        }

        return [$answers, $questionIndex];
    }

    private function pairKey(string $aType, string $bType): string
    {
        $types = [$aType, $bType];
        sort($types, SORT_STRING);

        return implode('-', $types);
    }

    /**
     * @return list<string>
     */
    private function nonLeaderWins(int $count): array
    {
        $types = ['T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'];
        $wins = [];
        for ($i = 0; $i < $count; $i++) {
            $wins[] = $types[$i % count($types)];
        }

        return $wins;
    }
}
