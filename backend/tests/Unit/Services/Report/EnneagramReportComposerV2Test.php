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
    #[DataProvider('formProvider')]
    public function test_composer_emits_v2_report_payload_with_five_pages_for_both_forms(
        string $formCode,
        string $expectedFormVariant,
        string $expectedMethodologyVariant
    ): void {
        $composer = app(EnneagramReportComposer::class);
        $attempt = new Attempt(['locale' => 'zh-CN']);
        $result = new Result([
            'result_json' => [
                'normed_json' => $this->syntheticProjectionInput($formCode, [
                    'T6' => 87.0,
                    'T1' => 64.0,
                    'T9' => 49.0,
                    'T2' => 38.0,
                    'T5' => 31.0,
                    'T3' => 27.0,
                    'T4' => 22.0,
                    'T7' => 18.0,
                    'T8' => 15.0,
                ]),
            ],
        ]);

        $payload = $composer->composeVariant($attempt, $result, 'full');

        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame('enneagram.report.v2', data_get($payload, 'report._meta.enneagram_report_v2.schema_version'));
        $this->assertCount(5, (array) data_get($payload, 'report._meta.enneagram_report_v2.pages'));
        $this->assertSame(
            [
                'page_1_result_overview',
                'page_2_work_reality',
                'page_3_growth_spectrum',
                'page_4_relationship_conflict',
                'page_5_method_observation_next',
            ],
            collect((array) data_get($payload, 'report._meta.enneagram_report_v2.pages'))->pluck('page_key')->all()
        );
        $this->assertSame($formCode, data_get($payload, 'report._meta.enneagram_report_v2.form.form_code'));
        $this->assertSame($expectedMethodologyVariant, data_get($payload, 'report._meta.enneagram_report_v2.form.methodology_variant'));
        $this->assertSame($expectedFormVariant, data_get($this->module($payload, 'methodology_boundary_card'), 'form_variant'));
        $this->assertStringStartsWith('sha256:', (string) data_get($payload, 'report._meta.enneagram_report_v2.registry.registry_release_hash'));
        $this->assertNotSame('', (string) data_get($payload, 'report._meta.enneagram_report_v2.provenance.interpretation_context_id'));
        $this->assertSame('enneagram_report_engine.v2', data_get($payload, 'report._meta.enneagram_report_v2.provenance.report_engine_version'));
    }

    public function test_page_1_contains_required_modules(): void
    {
        $payload = $this->composeReportV2($this->syntheticProjectionInput('enneagram_likert_105', [
            'T3' => 89.0,
            'T8' => 62.0,
            'T1' => 55.0,
            'T6' => 37.0,
            'T2' => 28.0,
            'T7' => 24.0,
            'T4' => 21.0,
            'T5' => 16.0,
            'T9' => 13.0,
        ]));

        $pageModules = collect((array) data_get($payload, 'report._meta.enneagram_report_v2.pages.0.modules'))
            ->pluck('module_key')
            ->all();

        $this->assertSame(
            [
                'instant_summary',
                'top3_cards',
                'type_deep_dive_summary',
                'all9_profile',
                'confidence_band_card',
                'dominance_gap_card',
                'close_call_card',
                'blind_spot_card',
                'center_summary',
                'stance_summary',
                'harmonic_summary',
                'wing_hint_visual',
                'methodology_boundary_card',
                'diffuse_boundary',
                'low_quality_boundary',
            ],
            $pageModules
        );
    }

    public function test_unavailable_v2_report_uses_public_error_code_without_registry_exception_details(): void
    {
        $composer = app(EnneagramReportComposer::class);
        $method = (new \ReflectionClass($composer))->getMethod('buildUnavailableReportV2');
        $method->setAccessible(true);

        $payload = $method->invoke($composer, [
            'form' => [
                'form_code' => 'enneagram_likert_105',
                'form_kind' => 'likert',
                'methodology_variant' => 'e105_standard',
            ],
            'algorithmic_meta' => [
                'projection_version' => 'projection.test',
            ],
        ], 'en');

        $provenance = data_get($payload, 'provenance');

        $this->assertSame('registry_unavailable', data_get($provenance, 'build_status'));
        $this->assertSame('ENNEAGRAM_REGISTRY_UNAVAILABLE', data_get($provenance, 'build_error_code'));
        $this->assertSame('registry unavailable.', data_get($provenance, 'build_error'));
        $this->assertStringNotContainsString('/Users/', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('RuntimeException', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_close_call_scope_includes_close_call_card_with_pair_refs(): void
    {
        $payload = $this->composeReportV2(
            $this->syntheticProjectionInput('enneagram_likert_105', [
                'T1' => 81.0,
                'T6' => 79.0,
                'T9' => 47.0,
                'T2' => 33.0,
                'T3' => 27.0,
                'T4' => 23.0,
                'T5' => 21.0,
                'T7' => 18.0,
                'T8' => 17.0,
            ], [
                'interpretation_state' => 'mixed_close_call',
                'close_call_candidates' => ['T1', 'T6'],
            ])
        );

        $module = $this->module($payload, 'close_call_card');

        $this->assertSame('close_call', data_get($module, 'state'));
        $this->assertSame('visible', data_get($module, 'visibility'));
        $this->assertSame('1_6', data_get($module, 'content.pair.pair_key'));
        $this->assertContains('enneagram_pair_registry:1_6', (array) data_get($module, 'registry_refs'));
    }

    public function test_diffuse_scope_includes_diffuse_boundary_module(): void
    {
        $payload = $this->composeReportV2(
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
            ])
        );

        $module = $this->module($payload, 'diffuse_boundary');

        $this->assertSame('diffuse', data_get($module, 'state'));
        $this->assertSame('visible', data_get($module, 'visibility'));
        $this->assertSame('结果分散说明', data_get($module, 'content.title'));
    }

    public function test_low_quality_scope_includes_low_quality_boundary_module(): void
    {
        $payload = $this->composeReportV2(
            $this->syntheticProjectionInput('enneagram_forced_choice_144', [
                'T5' => 84.0,
                'T6' => 68.0,
                'T4' => 52.0,
                'T1' => 35.0,
                'T2' => 28.0,
                'T3' => 21.0,
                'T7' => 18.0,
                'T8' => 16.0,
                'T9' => 12.0,
            ], [], [
                'level' => 'P2',
                'flags' => ['speed_too_fast'],
            ])
        );

        $module = $this->module($payload, 'low_quality_boundary');

        $this->assertSame('low_quality', data_get($module, 'state'));
        $this->assertSame('visible', data_get($module, 'visibility'));
        $this->assertSame('triggered_operational_signal', data_get($module, 'content.low_quality_status'));
        $this->assertSame(['speed_too_fast'], data_get($module, 'content.qc_flags'));
    }

    public function test_v2_modules_expose_p0_ready_registry_provenance(): void
    {
        $payload = $this->composeReportV2(
            $this->syntheticProjectionInput('enneagram_likert_105', [
                'T2' => 83.0,
                'T9' => 64.0,
                'T3' => 58.0,
                'T1' => 34.0,
                'T4' => 29.0,
                'T5' => 22.0,
                'T6' => 18.0,
                'T7' => 17.0,
                'T8' => 14.0,
            ])
        );

        $module = $this->module($payload, 'technical_note_link');

        $this->assertSame('p0_ready', data_get($module, 'provenance.content_maturity'));
        $this->assertSame('descriptive', data_get($module, 'provenance.evidence_level'));
        $this->assertSame('required', data_get($module, 'fallback_policy'));
    }

    public function test_type_deep_dive_fields_are_available_across_pages(): void
    {
        $payload = $this->composeReportV2(
            $this->syntheticProjectionInput('enneagram_likert_105', [
                'T8' => 91.0,
                'T3' => 69.0,
                'T1' => 61.0,
                'T6' => 42.0,
                'T2' => 28.0,
                'T5' => 23.0,
                'T4' => 19.0,
                'T7' => 17.0,
                'T9' => 13.0,
            ])
        );

        $summary = $this->module($payload, 'type_deep_dive_summary');
        $this->assertSame('8', data_get($summary, 'content.primary_candidate'));
        $this->assertNotSame('', (string) data_get($summary, 'content.core_desire'));
        $this->assertNotSame('', (string) data_get($summary, 'content.core_fear'));
        $this->assertNotSame('', (string) data_get($summary, 'content.defense_pattern'));

        $work = $this->module($payload, 'work_style_summary');
        $this->assertNotSame('', (string) data_get($work, 'content.type_summary'));
        $this->assertGreaterThanOrEqual(1, count((array) data_get($work, 'content.list_groups', [])));

        $stress = $this->module($payload, 'stress_trigger');
        $this->assertNotSame('', (string) data_get($stress, 'content.value'));
        $this->assertGreaterThanOrEqual(1, count((array) data_get($stress, 'content.list_groups', [])));

        $recovery = $this->module($payload, 'recovery_action');
        $this->assertNotSame('', (string) data_get($recovery, 'content.type_recovery_action'));
        $this->assertNotSame('', (string) data_get($recovery, 'content.growth_principle'));
        $this->assertNotSame('', (string) data_get($recovery, 'content.thirty_day_experiment'));
        $this->assertGreaterThanOrEqual(2, count((array) data_get($recovery, 'content.list_groups', [])));

        $relationship = $this->module($payload, 'relationship_need');
        $this->assertNotSame('', (string) data_get($relationship, 'content.type_summary'));
        $this->assertGreaterThanOrEqual(1, count((array) data_get($relationship, 'content.list_groups', [])));

        $conflict = $this->module($payload, 'conflict_script');
        $this->assertNotSame('', (string) data_get($conflict, 'content.type_summary'));
        $this->assertGreaterThanOrEqual(2, count((array) data_get($conflict, 'content.list_groups', [])));
    }

    public function test_work_growth_and_relationship_modules_expose_pack_lists(): void
    {
        $payload = $this->composeReportV2(
            $this->syntheticProjectionInput('enneagram_likert_105', [
                'T1' => 88.0,
                'T6' => 63.0,
                'T3' => 51.0,
                'T9' => 42.0,
                'T2' => 33.0,
                'T5' => 29.0,
                'T4' => 24.0,
                'T7' => 20.0,
                'T8' => 18.0,
            ])
        );

        $workStrengths = $this->module($payload, 'collaboration_strengths');
        $this->assertSame('work_strengths', data_get($workStrengths, 'content.list_groups.0.label_key'));
        $this->assertGreaterThanOrEqual(4, count((array) data_get($workStrengths, 'content.list_groups.0.items', [])));

        $workTriggers = $this->module($payload, 'workplace_trigger_points');
        $this->assertSame('workplace_trigger_points', data_get($workTriggers, 'content.list_groups.0.label_key'));
        $this->assertGreaterThanOrEqual(2, count((array) data_get($workTriggers, 'content.list_groups.0.items', [])));

        $growthCosts = $this->module($payload, 'cost_expression');
        $this->assertSame('growth_costs', data_get($growthCosts, 'content.list_groups.0.label_key'));
        $this->assertGreaterThanOrEqual(4, count((array) data_get($growthCosts, 'content.list_groups.0.items', [])));

        $state = $this->module($payload, 'state_spectrum');
        $this->assertNotSame('', (string) data_get($state, 'content.stable_expression'));
        $this->assertSame('early_warning_signs', data_get($state, 'content.list_groups.0.label_key'));

        $relationshipStrengths = $this->module($payload, 'relationship_strengths');
        $this->assertSame('relationship_strengths', data_get($relationshipStrengths, 'content.list_groups.0.label_key'));
        $this->assertGreaterThanOrEqual(4, count((array) data_get($relationshipStrengths, 'content.list_groups.0.items', [])));

        $communication = $this->module($payload, 'communication_manual');
        $this->assertSame('communication_manual', data_get($communication, 'content.list_groups.0.label_key'));
        $this->assertGreaterThanOrEqual(3, count((array) data_get($communication, 'content.list_groups.0.items', [])));
    }

    public function test_sample_report_module_exposes_preview_fields_from_registry(): void
    {
        $payload = $this->composeReportV2(
            $this->syntheticProjectionInput('enneagram_likert_105', [
                'T8' => 86.0,
                'T3' => 71.0,
                'T1' => 64.0,
                'T6' => 39.0,
                'T2' => 28.0,
                'T5' => 25.0,
                'T4' => 21.0,
                'T7' => 19.0,
                'T9' => 17.0,
            ])
        );

        $module = $this->module($payload, 'sample_report_link');

        $this->assertSame('clear_sample', data_get($module, 'content.sample_key'));
        $this->assertSame('clear', data_get($module, 'content.sample_type'));
        $this->assertSame('enneagram_likert_105', data_get($module, 'content.form_code'));
        $this->assertSame('clear', data_get($module, 'content.interpretation_scope'));
        $this->assertSame(['8', '3', '1'], data_get($module, 'content.top_types'));
        $this->assertNotSame('', (string) data_get($module, 'content.short_summary'));
        $this->assertNotSame('', (string) data_get($module, 'content.page_1_preview'));
        $this->assertNotSame('', (string) data_get($module, 'content.method_boundary'));
        $this->assertNotSame('', (string) data_get($module, 'content.public_url_slug'));
    }

    /**
     * @return iterable<string,array{string,string,string}>
     */
    public static function formProvider(): iterable
    {
        yield 'e105' => ['enneagram_likert_105', 'e105', 'e105_standard'];
        yield 'fc144' => ['enneagram_forced_choice_144', 'fc144', 'fc144_forced_choice'];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    private function composeReportV2(array $scoreResult): array
    {
        $composer = app(EnneagramReportComposer::class);
        $attempt = new Attempt(['locale' => 'zh-CN']);
        $result = new Result(['result_json' => ['normed_json' => $scoreResult]]);

        return $composer->composeVariant($attempt, $result, 'full');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function module(array $payload, string $moduleKey): array
    {
        return collect((array) data_get($payload, 'report._meta.enneagram_report_v2.modules'))
            ->firstWhere('module_key', $moduleKey) ?? [];
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
                'version_snapshot' => ['content_manifest_hash' => 'sha256:fixture-content-hash'],
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
            'version_snapshot' => ['content_manifest_hash' => 'sha256:fixture-content-hash'],
            'raw_scores' => [
                'raw_intensity' => $rawIntensity,
                'dominance' => $dominance,
            ],
        ];
    }
}
