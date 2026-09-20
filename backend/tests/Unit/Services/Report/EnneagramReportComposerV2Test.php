<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\EnneagramReportComposer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EnneagramReportComposerV2Test extends TestCase
{
    private const SECTION_IDS = [
        '2.1', '2.2', '2.3', '2.4', '2.5', '2.6',
        '3.1', '3.2', '3.3',
        '4.1', '4.2', '4.3',
        '5.1', '5.2', '5.3',
        '6.1', '6.2', '6.3', '6.4', '6.5',
    ];

    #[DataProvider('formProvider')]
    public function test_composer_emits_the_canonical_seven_chapter_contract(
        string $formCode,
        string $expectedFormVariant,
        string $expectedMethodologyVariant,
        string $attemptLocale,
        string $expectedLocale
    ): void {
        $payload = $this->compose($this->syntheticProjectionInput($formCode, $this->scoreShape(6, 'clear')), $attemptLocale);
        $report = (array) data_get($payload, 'report._meta.enneagram_report_v2');

        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame('enneagram.report.v2', $report['schema_version']);
        $this->assertSame($expectedLocale, $report['locale']);
        $this->assertSame($formCode, data_get($report, 'form.form_code'));
        $this->assertSame($expectedMethodologyVariant, data_get($report, 'form.methodology_variant'));
        $this->assertSame($expectedFormVariant, data_get($this->module($payload, 'result_overview'), 'form_variant'));
        $this->assertSame([
            'chapter_1_result', 'chapter_2_core_pattern', 'chapter_3_strength_cost', 'chapter_4_relationships',
            'chapter_5_work', 'chapter_6_stress_recovery', 'chapter_7_observation',
        ], collect($report['pages'])->pluck('page_key')->all());
        $this->assertSame(['01', '02', '03', '04', '05', '06', '07'], collect($report['pages'])->pluck('number')->all());
        $this->assertSame(self::SECTION_IDS, collect($report['pages'])->flatMap(fn (array $page): array => $page['section_ids'])->all());
        $this->assertCount(9, $report['modules']);
        $this->assertCount(9, $report['distribution']);
        $this->assertCount(3, $report['candidates']);
        $this->assertSame(['primary', 'secondary', 'tertiary'], collect($report['candidates'])->pluck('candidate_role')->all());

        foreach ($report['candidates'] as $candidate) {
            $this->assertSame(self::SECTION_IDS, array_column($candidate['sections'], 'section_id'));
            $this->assertCount(5, $candidate['growth_actions']);
            $this->assertNotSame('', $candidate['type_name']);
            $this->assertNotSame('', $candidate['short_title']);
        }
        foreach ($report['modules'] as $module) {
            $this->assertSame($expectedLocale, data_get($module, 'content.locale'));
            $this->assertNotSame('placeholder', $module['visibility']);
            $this->assertNotSame('placeholder_card', $module['kind']);
        }
    }

    public function test_all_types_states_forms_and_locales_keep_full_candidate_content(): void
    {
        foreach (range(1, 9) as $primaryType) {
            foreach (['clear', 'close_call', 'diffuse', 'low_quality'] as $state) {
                foreach (['enneagram_likert_105', 'enneagram_forced_choice_144'] as $formCode) {
                    foreach (['zh-CN', 'en'] as $locale) {
                        [$analysis, $quality] = $this->stateOverrides($primaryType, $state);
                        $payload = $this->compose(
                            $this->syntheticProjectionInput($formCode, $this->scoreShape($primaryType, $state), $analysis, $quality),
                            $locale
                        );
                        $report = (array) data_get($payload, 'report._meta.enneagram_report_v2');
                        $context = "type={$primaryType} state={$state} form={$formCode} locale={$locale}";

                        $this->assertSame($state, data_get($report, 'classification.interpretation_scope'), $context);
                        $this->assertSame($state, data_get($this->module($payload, 'result_overview'), 'content.interpretation_scope'), $context);
                        $this->assertSame((string) $primaryType, data_get($report, 'candidates.0.type_id'), $context);
                        $this->assertCount(9, $report['distribution'], $context);
                        $this->assertCount(3, $report['candidates'], $context);
                        $this->assertCount(20, data_get($report, 'candidates.0.sections'), $context);
                        $this->assertCount(5, data_get($report, 'candidates.0.growth_actions'), $context);
                        $this->assertFalse(data_get($this->section($report, 0, '6.4'), 'assignment_allowed'), $context);
                        $this->assertCount(9, data_get($this->section($report, 0, '6.4'), 'levels'), $context);
                    }
                }
            }
        }
    }

    public function test_every_type_can_appear_in_each_top_three_role(): void
    {
        foreach (range(1, 9) as $typeId) {
            foreach ([1, 2, 3] as $rank) {
                $others = array_values(array_diff(range(1, 9), [$typeId]));
                $scores = array_fill_keys(array_map(static fn (int $id): string => 'T'.$id, range(1, 9)), 10.0);
                $scores['T'.$others[0]] = 90.0;
                $scores['T'.$others[1]] = 80.0;
                $scores['T'.$typeId] = match ($rank) {
                    1 => 95.0,
                    2 => 85.0,
                    default => 75.0,
                };

                $payload = $this->compose($this->syntheticProjectionInput('enneagram_likert_105', $scores), 'zh-CN');
                $this->assertSame((string) $typeId, data_get($payload, 'report._meta.enneagram_report_v2.candidates.'.($rank - 1).'.type_id'));
            }
        }
    }

    public function test_e105_and_fc144_share_the_same_canonical_bodies_while_retaining_distinct_score_sources(): void
    {
        $scores = $this->scoreShape(6, 'clear');
        $e105 = (array) data_get($this->compose($this->syntheticProjectionInput('enneagram_likert_105', $scores), 'zh-CN'), 'report._meta.enneagram_report_v2');
        $fc144 = (array) data_get($this->compose($this->syntheticProjectionInput('enneagram_forced_choice_144', $scores), 'zh-CN'), 'report._meta.enneagram_report_v2');

        $this->assertSame(data_get($e105, 'candidates.0.sections'), data_get($fc144, 'candidates.0.sections'));
        $this->assertSame(data_get($e105, 'candidates.0.growth_actions'), data_get($fc144, 'candidates.0.growth_actions'));
        $this->assertNotSame(data_get($e105, 'form.methodology_variant'), data_get($fc144, 'form.methodology_variant'));
        $this->assertSame('likert_intensity', data_get($e105, 'distribution.0.raw_source_summary.mode'));
        $this->assertIsNumeric(data_get($e105, 'distribution.0.raw_source_summary.raw_intensity'));
        $this->assertIsNumeric(data_get($e105, 'distribution.0.raw_source_summary.dominance'));
        $this->assertSame('forced_choice_wins_exposures', data_get($fc144, 'distribution.0.raw_source_summary.mode'));
        $this->assertIsInt(data_get($fc144, 'distribution.0.raw_source_summary.wins'));
        $this->assertIsInt(data_get($fc144, 'distribution.0.raw_source_summary.exposures'));
    }

    public function test_ties_are_ordered_deterministically_by_type_id(): void
    {
        $scores = array_fill_keys(['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'], 50.0);
        $first = $this->compose($this->syntheticProjectionInput('enneagram_likert_105', $scores), 'zh-CN');
        $second = $this->compose($this->syntheticProjectionInput('enneagram_likert_105', array_reverse($scores, true)), 'zh-CN');

        $this->assertSame(['1', '2', '3'], collect(data_get($first, 'report._meta.enneagram_report_v2.candidates'))->pluck('type_id')->all());
        $this->assertSame(data_get($first, 'report._meta.enneagram_report_v2.candidates'), data_get($second, 'report._meta.enneagram_report_v2.candidates'));
    }

    public function test_all_thirty_six_close_call_pairs_resolve_deterministically_in_both_candidate_orders(): void
    {
        foreach (range(1, 8) as $typeA) {
            foreach (range($typeA + 1, 9) as $typeB) {
                foreach ([[$typeA, $typeB], [$typeB, $typeA]] as [$first, $second]) {
                    $scores = array_fill_keys(array_map(static fn (int $id): string => 'T'.$id, range(1, 9)), 10.0);
                    $scores['T'.$first] = 81.0;
                    $scores['T'.$second] = 79.0;
                    $payload = $this->compose($this->syntheticProjectionInput(
                        'enneagram_likert_105',
                        $scores,
                        ['interpretation_state' => 'mixed_close_call', 'close_call_candidates' => ['T'.$first, 'T'.$second]]
                    ), 'en');
                    $module = $this->module($payload, 'candidate_pair_comparison');
                    $context = "first={$first} second={$second}";

                    $this->assertSame('visible', $module['visibility'], $context);
                    $this->assertTrue((bool) data_get($module, 'content.available'), $context);
                    $this->assertSame($typeA.'_'.$typeB, data_get($module, 'content.pair_key'), $context);
                    $this->assertSame([(string) $first, (string) $second], collect(data_get($module, 'content.candidate_order'))->pluck('type_id')->all(), $context);
                    $this->assertCount(5, data_get($module, 'content.dimensions'), $context);
                    foreach ((array) data_get($module, 'content.dimensions') as $dimension) {
                        $this->assertCount(2, $dimension['sides'], $context);
                        $this->assertNotSame($dimension['sides'][0]['copy'], $dimension['sides'][1]['copy'], $context);
                    }
                }
            }
        }
    }

    public function test_pair_comparison_is_unavailable_outside_close_call(): void
    {
        foreach (['clear', 'diffuse', 'low_quality'] as $state) {
            [$analysis, $quality] = $this->stateOverrides(8, $state);
            $payload = $this->compose(
                $this->syntheticProjectionInput('enneagram_forced_choice_144', $this->scoreShape(8, $state), $analysis, $quality),
                'zh-CN'
            );
            $module = $this->module($payload, 'candidate_pair_comparison');

            $this->assertSame('unavailable', $module['visibility'], $state);
            $this->assertFalse((bool) data_get($module, 'content.available'), $state);
            $this->assertNull(data_get($module, 'content.pair_key'), $state);
        }
    }

    public function test_theory_boundaries_and_actions_are_structured_and_non_assigning(): void
    {
        $payload = $this->compose($this->syntheticProjectionInput('enneagram_forced_choice_144', $this->scoreShape(8, 'clear')), 'en');
        $report = (array) data_get($payload, 'report._meta.enneagram_report_v2');
        $wing = $this->section($report, 0, '2.6');
        $levels = $this->section($report, 0, '6.4');
        $actions = (array) data_get($report, 'candidates.0.growth_actions');

        $this->assertContains('no_score_based_wing_assignment', $wing['theory_refs']);
        $this->assertFalse($levels['assignment_allowed']);
        $this->assertContains('no_score_based_level_assignment', $levels['theory_refs']);
        foreach ($levels['levels'] as $level) {
            $this->assertFalse($level['assignment_allowed']);
        }
        $this->assertCount(count($actions), array_unique(array_column($actions, 'action_id')));
        foreach ($actions as $action) {
            $this->assertSame([1, 3, 7], $action['observation_days']);
            $this->assertNotSame('', $action['instruction']);
            $this->assertNotSame('', $action['observable_outcome']);
        }
        $this->assertSame('observation_evidence_only', data_get($this->module($payload, 'growth_actions'), 'content.assignment_mode'));
        $this->assertFalse(data_get($this->module($payload, 'growth_actions'), 'content.system_result_mutation_allowed'));
    }

    /** @return iterable<string,array{string,string,string,string,string}> */
    public static function formProvider(): iterable
    {
        yield 'e105 zh' => ['enneagram_likert_105', 'e105', 'e105_standard', 'zh-CN', 'zh'];
        yield 'e105 en' => ['enneagram_likert_105', 'e105', 'e105_standard', 'en', 'en'];
        yield 'fc144 zh' => ['enneagram_forced_choice_144', 'fc144', 'fc144_forced_choice', 'zh-CN', 'zh'];
        yield 'fc144 en' => ['enneagram_forced_choice_144', 'fc144', 'fc144_forced_choice', 'en', 'en'];
    }

    /** @return array<string,mixed> */
    private function compose(array $scoreResult, string $locale): array
    {
        $attempt = new Attempt(['locale' => $locale]);
        $result = new Result(['result_json' => ['normed_json' => $scoreResult]]);

        return app(EnneagramReportComposer::class)->composeVariant($attempt, $result, 'full');
    }

    /** @return array<string,mixed> */
    private function module(array $payload, string $moduleKey): array
    {
        return collect((array) data_get($payload, 'report._meta.enneagram_report_v2.modules'))->firstWhere('module_key', $moduleKey) ?? [];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function section(array $report, int $candidateIndex, string $sectionId): array
    {
        return collect((array) data_get($report, 'candidates.'.$candidateIndex.'.sections'))->firstWhere('section_id', $sectionId) ?? [];
    }

    /** @return array<string,float> */
    private function scoreShape(int $primaryType, string $state): array
    {
        $orderedTypes = array_merge([$primaryType], array_values(array_diff(range(1, 9), [$primaryType])));
        $shapes = [
            'clear' => [90.0, 62.0, 52.0, 37.0, 28.0, 24.0, 21.0, 16.0, 13.0],
            'close_call' => [81.0, 79.0, 47.0, 33.0, 27.0, 23.0, 21.0, 18.0, 17.0],
            'diffuse' => [52.0, 51.0, 50.0, 49.0, 48.0, 47.0, 46.0, 45.0, 44.0],
            'low_quality' => [84.0, 68.0, 52.0, 35.0, 28.0, 21.0, 18.0, 16.0, 12.0],
        ];
        $scores = [];
        foreach ($orderedTypes as $index => $type) {
            $scores['T'.$type] = $shapes[$state][$index];
        }

        return $scores;
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function stateOverrides(int $primaryType, string $state): array
    {
        $orderedTypes = array_merge([$primaryType], array_values(array_diff(range(1, 9), [$primaryType])));

        return [
            $state === 'close_call' ? ['interpretation_state' => 'mixed_close_call', 'close_call_candidates' => ['T'.$orderedTypes[0], 'T'.$orderedTypes[1]]] : [],
            $state === 'low_quality' ? ['level' => 'P2', 'flags' => ['speed_too_fast']] : [],
        ];
    }

    /**
     * @param  array<string,float>  $scoresPct
     * @param  array<string,mixed>  $analysisOverrides
     * @param  array<string,mixed>  $qualityOverrides
     * @return array<string,mixed>
     */
    private function syntheticProjectionInput(string $formCode, array $scoresPct, array $analysisOverrides = [], array $qualityOverrides = []): array
    {
        $normalizedScores = [];
        foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'] as $typeCode) {
            $normalizedScores[$typeCode] = round((float) ($scoresPct[$typeCode] ?? 0.0), 2);
        }
        $ranking = collect($normalizedScores)
            ->map(fn (float $scorePct, string $typeCode): array => ['type_code' => $typeCode, 'score_pct' => $scorePct])
            ->sort(fn (array $a, array $b): int => ($b['score_pct'] <=> $a['score_pct']) ?: strcmp($a['type_code'], $b['type_code']))
            ->values()
            ->map(function (array $row, int $index) use ($formCode, $normalizedScores): array {
                $row[$formCode === 'enneagram_forced_choice_144' ? 'raw_count' : 'raw_intensity'] = $formCode === 'enneagram_forced_choice_144'
                    ? (int) round(($row['score_pct'] / 100.0) * 32.0)
                    : round(($row['score_pct'] / 25.0) - 2.0, 6);
                $row['rank'] = $index + 1;
                if ($formCode !== 'enneagram_forced_choice_144') {
                    $row['dominance'] = round($row['score_pct'] - (array_sum($normalizedScores) / count($normalizedScores)), 6);
                }

                return $row;
            })->all();
        $analysis = array_merge([
            'core_type' => $ranking[0]['type_code'],
            'top3' => array_column(array_slice($ranking, 0, 3), 'type_code'),
            'score_separation' => round((float) $ranking[0]['score_pct'] - (float) $ranking[1]['score_pct'], 4),
            'interpretation_state' => 'standard_primary',
            'confidence_band' => 'medium',
            'response_quality_summary' => ['level' => 'clean', 'soft_flags' => [], 'hard_flags' => [], 'flags' => []],
        ], $analysisOverrides);
        $quality = array_merge(['level' => 'P0', 'flags' => []], $qualityOverrides);
        $base = [
            'scale_code' => 'ENNEAGRAM',
            'form_code' => $formCode,
            'score_method' => $formCode === 'enneagram_forced_choice_144' ? 'enneagram_forced_choice_144_pair_v1' : 'enneagram_likert_105_weighted_v1',
            'scoring_spec_version' => $formCode === 'enneagram_forced_choice_144' ? 'enneagram_forced_choice_144_spec_v1' : 'enneagram_likert_105_spec_v1',
            'scores_0_100' => $normalizedScores,
            'ranking' => $ranking,
            'analysis' => $analysis,
            'quality' => $quality,
            'version_snapshot' => ['content_manifest_hash' => 'sha256:fixture-content-hash'],
        ];
        if ($formCode === 'enneagram_forced_choice_144') {
            $wins = [];
            foreach ($normalizedScores as $typeCode => $scorePct) {
                $wins[$typeCode] = (int) round(($scorePct / 100.0) * 32.0);
            }
            $base['raw_scores'] = ['type_counts' => $wins, 'exposures' => array_fill_keys(array_keys($wins), 32)];
        } else {
            $rawIntensity = [];
            $dominance = [];
            $mean = array_sum($normalizedScores) / count($normalizedScores);
            foreach ($normalizedScores as $typeCode => $scorePct) {
                $rawIntensity[$typeCode] = round(($scorePct / 25.0) - 2.0, 6);
                $dominance[$typeCode] = round($scorePct - $mean, 6);
            }
            $base['raw_scores'] = ['raw_intensity' => $rawIntensity, 'dominance' => $dominance];
        }

        return $base;
    }
}
