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
            round(100 * data_get($forcedProjection, 'scoring.wins.T2') / 32, 2),
            data_get($forced, 'scores_0_100.T2')
        );
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

    public function test_projection_v2_falls_back_to_scores_when_legacy_ranking_is_missing(): void
    {
        $scorer = new EnneagramLikert105Scorer(new EnneagramTopologyAnalyzer);
        [$answers, $questionIndex] = $this->likertFixture([
            'T6' => array_fill(0, 12, 2),
            'T9' => array_merge(array_fill(0, 11, 2), [1]),
            'T2' => array_fill(0, 12, 1),
        ]);
        $result = $scorer->score($answers, $questionIndex, []);

        unset($result['ranking']);

        $projection = (new EnneagramPublicProjectionService)->buildV2($result, 'zh-CN');

        $this->assertSame('6', data_get($projection, 'scores.primary_candidate'));
        $this->assertCount(3, (array) data_get($projection, 'scores.top_types'));
        $this->assertCount(9, (array) data_get($projection, 'scores.all9_profile'));
        $this->assertSame(
            ['1', '2', '3', '4', '5', '6', '7', '8', '9'],
            collect((array) data_get($projection, 'scores.all9_profile'))->pluck('type')->sort()->values()->all()
        );
        $this->assertSame('enneagram_likert_105', data_get($projection, 'form.form_code'));
        $this->assertSame('e105_likert_space.v1', data_get($projection, 'form.score_space_version'));
    }

    public function test_projection_v2_policy_marks_e105_clear_when_gap_is_strong(): void
    {
        $projection = (new EnneagramPublicProjectionService)->buildV2(
            $this->syntheticProjectionInput('enneagram_likert_105', [
                'T4' => 92.0,
                'T2' => 68.0,
                'T9' => 51.0,
                'T1' => 34.0,
                'T3' => 29.0,
                'T5' => 24.0,
                'T6' => 20.0,
                'T7' => 18.0,
                'T8' => 15.0,
            ]),
            'zh-CN'
        );

        $this->assertSame('high_confidence', data_get($projection, 'classification.confidence_level'));
        $this->assertSame('clear', data_get($projection, 'classification.interpretation_scope'));
        $this->assertSame('gap_above_high_confidence_threshold', data_get($projection, 'classification.interpretation_reason'));
        $this->assertGreaterThan(15.0, (float) data_get($projection, 'classification.dominance_gap_pct'));
        $this->assertNotNull(data_get($projection, 'classification.dominance.normalized_gap'));
        $this->assertNotNull(data_get($projection, 'classification.dominance.profile_entropy'));
    }

    public function test_projection_v2_policy_marks_e105_close_call_from_analyzer_signal(): void
    {
        $scorer = new EnneagramLikert105Scorer(new EnneagramTopologyAnalyzer);
        [$answers, $questionIndex] = $this->likertFixture([
            'T3' => array_fill(0, 12, 2),
            'T8' => array_merge(array_fill(0, 10, 2), [1]),
            'T4' => array_fill(0, 12, 0),
        ]);
        $result = $scorer->score($answers, $questionIndex, []);

        $projection = (new EnneagramPublicProjectionService)->buildV2($result, 'zh-CN');

        $this->assertSame('close_call', data_get($projection, 'classification.confidence_level'));
        $this->assertSame('close_call', data_get($projection, 'classification.interpretation_scope'));
        $this->assertSame('analyzer_close_call', data_get($projection, 'classification.interpretation_reason'));
        $this->assertSame('analyzer_close_call', data_get($projection, 'dynamics.close_call_pair.trigger_reason'));
    }

    public function test_projection_v2_policy_marks_fc144_clear_when_gap_is_strong(): void
    {
        $projection = (new EnneagramPublicProjectionService)->buildV2(
            $this->syntheticProjectionInput('enneagram_forced_choice_144', [
                'T8' => 88.0,
                'T3' => 63.0,
                'T7' => 50.0,
                'T2' => 38.0,
                'T1' => 30.0,
                'T4' => 26.0,
                'T5' => 20.0,
                'T6' => 18.0,
                'T9' => 16.0,
            ]),
            'en'
        );

        $this->assertSame('high_confidence', data_get($projection, 'classification.confidence_level'));
        $this->assertSame('clear', data_get($projection, 'classification.interpretation_scope'));
        $this->assertSame('fc144_forced_choice_space.v1', data_get($projection, 'form.score_space_version'));
        $this->assertGreaterThan(15.0, (float) data_get($projection, 'classification.dominance_gap_pct'));
    }

    public function test_projection_v2_policy_marks_fc144_unresolved_tie_as_close_call(): void
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
        $projection = (new EnneagramPublicProjectionService)->buildV2($result, 'en');

        $this->assertSame('close_call', data_get($projection, 'classification.confidence_level'));
        $this->assertSame('close_call', data_get($projection, 'classification.interpretation_scope'));
        $this->assertSame('unresolved_tie', data_get($projection, 'classification.interpretation_reason'));
        $this->assertSame('unresolved_tie', data_get($projection, 'dynamics.close_call_pair.trigger_reason'));
    }

    public function test_projection_v2_policy_marks_diffuse_when_profile_shape_is_flat(): void
    {
        $projection = (new EnneagramPublicProjectionService)->buildV2(
            $this->syntheticProjectionInput('enneagram_likert_105', [
                'T1' => 52.0,
                'T2' => 51.0,
                'T3' => 50.0,
                'T4' => 49.0,
                'T5' => 48.0,
                'T6' => 47.0,
                'T7' => 46.0,
                'T8' => 45.0,
                'T9' => 44.0,
            ]),
            'zh-CN'
        );

        $this->assertSame('diffuse', data_get($projection, 'classification.confidence_level'));
        $this->assertSame('diffuse', data_get($projection, 'classification.interpretation_scope'));
        $this->assertContains(
            (string) data_get($projection, 'classification.interpretation_reason'),
            ['high_profile_entropy', 'top3_spread_low']
        );
        $this->assertTrue((bool) data_get($projection, 'render_hints.show_diffuse_warning'));
    }

    public function test_projection_v2_policy_triggers_low_quality_only_with_operational_flags(): void
    {
        $projection = (new EnneagramPublicProjectionService)->buildV2(
            $this->syntheticProjectionInput(
                'enneagram_likert_105',
                [
                    'T5' => 84.0,
                    'T6' => 68.0,
                    'T4' => 52.0,
                    'T1' => 35.0,
                    'T2' => 28.0,
                    'T3' => 21.0,
                    'T7' => 18.0,
                    'T8' => 16.0,
                    'T9' => 12.0,
                ],
                [],
                ['level' => 'P2', 'flags' => ['speed_too_fast']]
            ),
            'zh-CN'
        );

        $this->assertSame('low_quality', data_get($projection, 'classification.confidence_level'));
        $this->assertSame('low_quality', data_get($projection, 'classification.interpretation_scope'));
        $this->assertSame('retest', data_get($projection, 'classification.quality_level'));
        $this->assertSame('triggered_operational_signal', data_get($projection, 'classification.low_quality_status'));
        $this->assertTrue((bool) data_get($projection, 'render_hints.show_low_quality_boundary'));
    }

    public function test_projection_v2_policy_keeps_low_quality_unavailable_without_operational_flags(): void
    {
        $projection = (new EnneagramPublicProjectionService)->buildV2(
            $this->syntheticProjectionInput('enneagram_forced_choice_144', [
                'T1' => 71.0,
                'T2' => 62.0,
                'T3' => 44.0,
                'T4' => 31.0,
                'T5' => 27.0,
                'T6' => 22.0,
                'T7' => 18.0,
                'T8' => 16.0,
                'T9' => 12.0,
            ]),
            'en'
        );

        $this->assertNotSame('low_quality', data_get($projection, 'classification.confidence_level'));
        $this->assertSame('unavailable', data_get($projection, 'classification.quality_level'));
        $this->assertSame('not_triggered_no_operational_signal', data_get($projection, 'classification.low_quality_status'));
        $this->assertSame('no_signal', data_get($projection, '_meta.policy.signal_limitations.low_quality'));
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
     * @param  array<string,float>  $scoresPct
     * @param  array<string,mixed>  $analysisOverrides
     * @param  array<string,mixed>  $qualityOverrides
     * @return array<string,mixed>
     */
    private function syntheticProjectionInput(
        string $formCode,
        array $scoresPct,
        array $analysisOverrides = [],
        array $qualityOverrides = []
    ): array {
        $normalizedScores = [];
        foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'] as $typeCode) {
            $normalizedScores[$typeCode] = round((float) ($scoresPct[$typeCode] ?? 0.0), 2);
        }

        $ranking = collect($normalizedScores)
            ->map(fn (float $scorePct, string $typeCode): array => [
                'type_code' => $typeCode,
                'score_pct' => $scorePct,
            ])
            ->sort(fn (array $a, array $b): int => ($b['score_pct'] <=> $a['score_pct']) ?: strcmp($a['type_code'], $b['type_code']))
            ->values()
            ->map(function (array $row, int $index) use ($formCode, $normalizedScores): array {
                if ($formCode === 'enneagram_forced_choice_144') {
                    $row['raw_count'] = (int) round(($row['score_pct'] / 100.0) * 32.0);
                } else {
                    $mean = array_sum($normalizedScores) / count($normalizedScores);
                    $rawIntensity = round(($row['score_pct'] / 25.0) - 2.0, 6);
                    $row['raw_intensity'] = $rawIntensity;
                    $row['dominance'] = round($row['score_pct'] - $mean, 6);
                }
                $row['rank'] = $index + 1;

                return $row;
            })
            ->all();

        $analysis = array_merge([
            'core_type' => $ranking[0]['type_code'],
            'top3' => array_values(array_map(static fn (array $row): string => (string) ($row['type_code'] ?? ''), array_slice($ranking, 0, 3))),
            'score_separation' => round((float) $ranking[0]['score_pct'] - (float) $ranking[1]['score_pct'], 4),
            'interpretation_state' => 'standard_primary',
            'confidence_band' => 'medium',
            'response_quality_summary' => ['level' => 'clean', 'soft_flags' => [], 'hard_flags' => [], 'flags' => []],
        ], $analysisOverrides);

        $quality = array_merge([
            'level' => 'P0',
            'flags' => [],
        ], $qualityOverrides);

        if ($formCode === 'enneagram_forced_choice_144') {
            $wins = [];
            $exposures = [];
            foreach ($normalizedScores as $typeCode => $scorePct) {
                $wins[$typeCode] = (int) round(($scorePct / 100.0) * 32.0);
                $exposures[$typeCode] = 32;
            }

            return [
                'scale_code' => 'ENNEAGRAM',
                'form_code' => $formCode,
                'score_method' => 'enneagram_forced_choice_144_pair_v1',
                'scoring_spec_version' => 'enneagram_forced_choice_144_spec_v1',
                'scores_0_100' => $normalizedScores,
                'ranking' => $ranking,
                'analysis' => $analysis,
                'quality' => $quality,
                'raw_scores' => [
                    'type_counts' => $wins,
                    'exposures' => $exposures,
                ],
            ];
        }

        $rawIntensity = [];
        $dominance = [];
        $mean = array_sum($normalizedScores) / count($normalizedScores);
        foreach ($normalizedScores as $typeCode => $scorePct) {
            $rawIntensity[$typeCode] = round(($scorePct / 25.0) - 2.0, 6);
            $dominance[$typeCode] = round($scorePct - $mean, 6);
        }

        return [
            'scale_code' => 'ENNEAGRAM',
            'form_code' => $formCode,
            'score_method' => 'enneagram_likert_105_weighted_v1',
            'scoring_spec_version' => 'enneagram_likert_105_spec_v1',
            'scores_0_100' => $normalizedScores,
            'ranking' => $ranking,
            'analysis' => $analysis,
            'quality' => $quality,
            'raw_scores' => [
                'raw_intensity' => $rawIntensity,
                'dominance' => $dominance,
            ],
        ];
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
