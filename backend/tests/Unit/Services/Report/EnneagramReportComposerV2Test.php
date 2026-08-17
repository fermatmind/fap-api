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
        string $expectedMethodologyVariant,
        string $attemptLocale,
        string $expectedLocale
    ): void {
        $composer = app(EnneagramReportComposer::class);
        $attempt = new Attempt(['locale' => $attemptLocale]);
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
        $this->assertSame($expectedLocale, data_get($payload, 'report.locale'));
        $this->assertSame($expectedLocale, data_get($payload, 'report._meta.enneagram_public_projection_v1.locale'));
        $this->assertSame($expectedLocale, data_get($payload, 'report._meta.enneagram_public_projection_v2.locale'));
        $this->assertSame($expectedLocale, data_get($payload, 'report._meta.enneagram_report_v2.locale'));
        $this->assertSame('enneagram.report.v2', data_get($payload, 'report._meta.enneagram_report_v2.schema_version'));
        $this->assertCount(5, (array) data_get($payload, 'report._meta.enneagram_report_v2.pages'));
        $this->assertCount(29, (array) data_get($payload, 'report._meta.enneagram_report_v2.modules'));
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
        $this->assertSame($expectedFormVariant, data_get($this->module($payload, 'method_boundary'), 'form_variant'));
        $this->assertSame([], $this->module($payload, 'methodology_boundary_card'));
        $this->assertSame('clear', data_get($this->module($payload, 'instant_summary'), 'content.interpretation_scope'));
        $summaryBody = (string) data_get($this->module($payload, 'instant_summary'), 'content.body');
        $this->assertStringContainsString($expectedLocale === 'en' ? 'explanatory hypothesis' : '解释假设', $summaryBody);
        $this->assertStringContainsString($expectedLocale === 'en' ? 'rather than an absolute label or diagnostic conclusion' : '不是对人格的绝对标签或诊断结论', $summaryBody);
        $this->assertStringStartsWith('sha256:', (string) data_get($payload, 'report._meta.enneagram_report_v2.registry.registry_release_hash'));
        $this->assertNotSame('', (string) data_get($payload, 'report._meta.enneagram_report_v2.provenance.interpretation_context_id'));
        $this->assertSame('enneagram_report_engine.v2', data_get($payload, 'report._meta.enneagram_report_v2.provenance.report_engine_version'));

        foreach ((array) data_get($payload, 'report._meta.enneagram_report_v2.modules') as $module) {
            $this->assertSame(
                $expectedLocale,
                (string) data_get($module, 'content.locale'),
                'Module locale mismatch: '.(string) data_get($module, 'module_key', 'unknown')
            );
        }
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
                'close_call_card',
                'blind_spot_card',
                'wing_hint_visual',
            ],
            $pageModules
        );
    }

    public function test_report_density_contract_removes_placeholders_and_repeated_disclaimers(): void
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

        $pages = (array) data_get($payload, 'report._meta.enneagram_report_v2.pages');
        $modules = (array) data_get($payload, 'report._meta.enneagram_report_v2.modules');
        $this->assertSame([7, 6, 6, 5, 5], array_map(
            static fn (array $page): int => count((array) ($page['modules'] ?? [])),
            $pages
        ));

        $removed = [
            'confidence_band_card', 'dominance_gap_card', 'center_summary', 'stance_summary', 'harmonic_summary',
            'diffuse_boundary', 'low_quality_boundary', 'context_mode_placeholder',
            'arrow_growth_reference_placeholder', 'resonance_feedback_placeholder',
            'history_share_retake_placeholder', 'blind_spot_in_relationship',
        ];
        $moduleKeys = collect($modules)->pluck('module_key')->all();
        $this->assertNotContains('placeholder', collect($modules)->pluck('visibility')->all());
        $this->assertNotContains('placeholder_card', collect($modules)->pluck('kind')->all());
        foreach ($removed as $moduleKey) {
            $this->assertNotContains($moduleKey, $moduleKeys);
        }

        foreach ($modules as $module) {
            $moduleKey = (string) ($module['module_key'] ?? '');
            if (! in_array($moduleKey, ['method_boundary', 'instant_summary'], true)) {
                $this->assertArrayNotHasKey('disclaimer', (array) ($module['content'] ?? []), $moduleKey);
            }
            foreach ((array) data_get($module, 'content.list_groups', []) as $group) {
                $this->assertLessThanOrEqual(2, count((array) ($group['items'] ?? [])), $moduleKey);
            }
        }

        $seenLongCopy = [];
        $collectStrings = function (mixed $value) use (&$collectStrings): array {
            if (is_string($value)) {
                return mb_strlen(trim($value)) >= 36 ? [trim($value)] : [];
            }
            if (! is_array($value)) {
                return [];
            }

            return array_reduce(
                array_values($value),
                static fn (array $carry, mixed $item): array => array_merge($carry, $collectStrings($item)),
                []
            );
        };
        foreach ($modules as $module) {
            $moduleKey = (string) ($module['module_key'] ?? '');
            foreach (array_unique($collectStrings((array) ($module['content'] ?? []))) as $copy) {
                $this->assertArrayNotHasKey($copy, $seenLongCopy, 'Repeated long copy in '.$moduleKey.' and '.($seenLongCopy[$copy] ?? 'unknown'));
                $seenLongCopy[$copy] = $moduleKey;
            }
        }

        $this->assertSame(1, collect($modules)->where('module_key', 'method_boundary')->count());
    }

    public function test_all9_profile_module_labels_scores_as_relative_profile_signals(): void
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

        $module = $this->module($payload, 'all9_profile');
        $items = (array) data_get($module, 'content.items');
        $first = (array) ($items[0] ?? []);

        $this->assertSame('答题轮廓内的相对线索', data_get($module, 'content.score_axis_label'));
        $this->assertStringContainsString('不是常模排名、诊断标签、能力评价或固定人格定论', (string) data_get($module, 'content.boundary_note'));
        $this->assertSame(['norm_comparison', 'diagnosis', 'ability_rating', 'personality_verdict'], data_get($module, 'content.not_for'));
        $this->assertCount(9, $items);
        $this->assertArrayNotHasKey('score_interpretation_label', $first);
        $this->assertArrayNotHasKey('score_boundary_note', $first);
    }

    public function test_unavailable_center_is_omitted_and_blind_spot_has_observation_value(): void
    {
        $payload = $this->composeReportV2($this->syntheticProjectionInput('enneagram_likert_105', [
            'T8' => 88.0,
            'T1' => 61.0,
            'T9' => 53.0,
            'T6' => 39.0,
            'T3' => 34.0,
            'T2' => 29.0,
            'T5' => 23.0,
            'T7' => 18.0,
            'T4' => 14.0,
        ]));

        $this->assertSame([], $this->module($payload, 'center_summary'));
        $blindSpot = $this->module($payload, 'blind_spot_card');
        $this->assertSame('visible', data_get($blindSpot, 'visibility'));
        $this->assertSame('available', data_get($blindSpot, 'content.status'));
        $this->assertStringContainsString('观察', (string) data_get($blindSpot, 'content.body'));
    }

    public function test_redundant_confidence_and_gap_cards_are_not_emitted(): void
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

        $this->assertSame([], $this->module($payload, 'confidence_band_card'));
        $this->assertSame([], $this->module($payload, 'dominance_gap_card'));
    }

    public function test_all_type_state_form_and_locale_combinations_keep_content_and_method_contracts(): void
    {
        $forms = [
            'enneagram_likert_105' => ['zh-CN' => 'E105 五点量表版', 'en' => 'E105 Five-point Likert Form'],
            'enneagram_forced_choice_144' => ['zh-CN' => 'FC144 二选一迫选版', 'en' => 'FC144 Two-option Forced-choice Form'],
        ];
        $states = ['clear', 'close_call', 'diffuse', 'low_quality'];

        foreach (range(1, 9) as $primaryType) {
            foreach ($states as $expectedState) {
                foreach ($forms as $formCode => $localeLabels) {
                    foreach ($localeLabels as $attemptLocale => $expectedFormLabel) {
                        [$scores, $analysisOverrides, $qualityOverrides] = $this->matrixFixture(
                            $primaryType,
                            $expectedState
                        );
                        $payload = $this->composeReportV2ForLocale(
                            $this->syntheticProjectionInput(
                                $formCode,
                                $scores,
                                $analysisOverrides,
                                $qualityOverrides
                            ),
                            $attemptLocale
                        );
                        $context = sprintf('type=%d state=%s form=%s locale=%s', $primaryType, $expectedState, $formCode, $attemptLocale);
                        $modules = (array) data_get($payload, 'report._meta.enneagram_report_v2.modules');

                        $this->assertCount(29, $modules, $context);
                        $this->assertSame($expectedState, data_get($this->module($payload, 'instant_summary'), 'content.interpretation_scope'), $context);
                        $this->assertSame($expectedState === 'clear', data_get($this->module($payload, 'instant_summary'), 'content.hard_primary_language'), $context);
                        $this->assertSame($expectedFormLabel, data_get($this->module($payload, 'instant_summary'), 'content.form_badge.label'), $context);
                        $this->assertSame($attemptLocale === 'en' ? 'View technical note' : '查看技术说明', data_get($this->module($payload, 'technical_note_link'), 'content.label'), $context);
                        $this->assertSame(1, collect($modules)->where('module_key', 'method_boundary')->count(), $context);
                        $this->assertSame(0, collect($modules)->where('module_key', 'methodology_boundary_card')->count(), $context);

                        foreach (['type_deep_dive_summary', 'work_style_summary', 'growth_axis', 'relationship_need'] as $moduleKey) {
                            $this->assertSame((string) $primaryType, data_get($this->module($payload, $moduleKey), 'content.primary_candidate'), $context.' module='.$moduleKey);
                        }

                        $visibleContent = json_encode(
                            collect($modules)->pluck('content')->values()->all(),
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                        );
                        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9_])T[1-9](?![A-Za-z0-9_])/', $visibleContent, $context);
                        $this->assertStringNotContainsString('[object Object]', $visibleContent, $context);
                        $this->assertDoesNotMatchRegularExpression('/深度版|深度辨析|提高辨析度|更准确|更权威|高级版/u', $visibleContent, $context);
                        $this->assertDoesNotMatchRegularExpression('/Deep (?:Edition|Form|discrimination)|increases discrimination|more accurate|higher authority|advanced version/i', $visibleContent, $context);
                        if ($attemptLocale === 'zh-CN') {
                            $this->assertDoesNotMatchRegularExpression('/Top[123]|All9|Confidence Band|Technical Note|(?i:score space|forced-choice|\bform\b)/', $visibleContent, $context);
                        }
                    }
                }
            }
        }
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
        $summary = $this->module($payload, 'instant_summary');

        $this->assertSame('close_call', data_get($module, 'state'));
        $this->assertSame('visible', data_get($module, 'visibility'));
        $this->assertSame('1_6', data_get($module, 'content.pair.pair_key'));
        $this->assertContains('enneagram_pair_registry:1_6', (array) data_get($module, 'registry_refs'));
        $this->assertSame('close_call', data_get($summary, 'content.interpretation_scope'));
        $this->assertStringContainsString('并排比较', (string) data_get($summary, 'content.body'));
        $this->assertStringContainsString('不急着固定自我标签', (string) data_get($summary, 'content.body'));
    }

    public function test_diffuse_scope_uses_the_single_overview_boundary(): void
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

        $summary = $this->module($payload, 'instant_summary');

        $this->assertSame([], $this->module($payload, 'diffuse_boundary'));
        $this->assertSame('diffuse', data_get($summary, 'content.interpretation_scope'));
        $this->assertStringContainsString('第一候选还不足以单独承载整页解释', (string) data_get($summary, 'content.body'));
        $this->assertStringContainsString('真实情境', (string) data_get($summary, 'content.body'));
    }

    public function test_low_quality_scope_uses_the_single_overview_boundary(): void
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

        $summary = $this->module($payload, 'instant_summary');

        $this->assertSame([], $this->module($payload, 'low_quality_boundary'));
        $this->assertSame('low_quality', data_get($summary, 'content.interpretation_scope'));
        $this->assertStringContainsString('作答信号显示解释边界需要放宽', (string) data_get($summary, 'content.body'));
        $this->assertStringContainsString('不适合用来确认主型', (string) data_get($summary, 'content.body'));
        $this->assertStringContainsString('不是在责备作答者', (string) data_get($summary, 'content.body'));
    }

    public function test_form_recommendation_uses_safe_registry_boundary_copy(): void
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

        $module = $this->module($payload, 'form_recommendation');

        $this->assertSame('retake_same_form_after_quality_check', data_get($module, 'content.recommendation_key'));
        $this->assertSame('form_specific_observation_not_cross_form_verdict', data_get($module, 'content.boundary_kind'));
        $this->assertStringContainsString('重测同一题型', (string) data_get($module, 'content.recommendation_copy'));
        $this->assertStringContainsString('不能用于规避质量边界', (string) data_get($module, 'content.recommendation_copy'));
        $this->assertContains('accuracy_ranking', (array) data_get($module, 'content.not_for'));
        $this->assertContains('enneagram_method_registry:low_quality_boundary', (array) data_get($module, 'registry_refs'));
    }

    public function test_default_form_recommendation_does_not_repeat_global_method_boundary(): void
    {
        $payload = $this->composeReportV2($this->syntheticProjectionInput('enneagram_forced_choice_144', [
            'T5' => 84.0,
            'T6' => 60.0,
            'T4' => 48.0,
            'T1' => 35.0,
            'T2' => 28.0,
            'T3' => 21.0,
            'T7' => 18.0,
            'T8' => 16.0,
            'T9' => 12.0,
        ]));

        $module = $this->module($payload, 'form_recommendation');

        $this->assertSame('stay_with_current_form', data_get($module, 'content.recommendation_key'));
        $this->assertNull(data_get($module, 'content.recommendation_copy'));
        $this->assertSame([], data_get($module, 'registry_refs'));
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
        $this->assertNotSame('', (string) data_get($summary, 'content.internal_tension'));
        $this->assertNotSame('', (string) data_get($summary, 'content.validation_hook'));
        $this->assertNull(data_get($summary, 'content.growth_experiment'));
        $this->assertNull(data_get($summary, 'content.core_desire'));
        $this->assertNull(data_get($summary, 'content.core_fear'));
        $this->assertNull(data_get($summary, 'content.defense_pattern'));

        $work = $this->module($payload, 'work_style_summary');
        $this->assertNotSame('', (string) data_get($work, 'content.type_summary'));
        $this->assertGreaterThanOrEqual(1, count((array) data_get($work, 'content.list_groups', [])));

        $stress = $this->module($payload, 'stress_trigger');
        $this->assertNull(data_get($stress, 'content.value'));
        $this->assertGreaterThanOrEqual(1, count((array) data_get($stress, 'content.list_groups', [])));

        $recovery = $this->module($payload, 'recovery_action');
        $this->assertNull(data_get($recovery, 'content.type_recovery_action'));
        $this->assertNull(data_get($recovery, 'content.growth_principle'));
        $this->assertNull(data_get($recovery, 'content.thirty_day_experiment'));
        $this->assertCount(1, (array) data_get($recovery, 'content.list_groups', []));

        $relationship = $this->module($payload, 'relationship_need');
        $this->assertNotSame('', (string) data_get($relationship, 'content.type_summary'));
        $this->assertGreaterThanOrEqual(1, count((array) data_get($relationship, 'content.list_groups', [])));

        $conflict = $this->module($payload, 'conflict_script');
        $this->assertNull(data_get($conflict, 'content.type_summary'));
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
        $this->assertCount(2, (array) data_get($workStrengths, 'content.list_groups.0.items', []));

        $workTriggers = $this->module($payload, 'workplace_trigger_points');
        $this->assertSame('workplace_trigger_points', data_get($workTriggers, 'content.list_groups.0.label_key'));
        $this->assertCount(2, (array) data_get($workTriggers, 'content.list_groups.0.items', []));

        $growthCosts = $this->module($payload, 'cost_expression');
        $this->assertSame('growth_costs', data_get($growthCosts, 'content.list_groups.0.label_key'));
        $this->assertCount(2, (array) data_get($growthCosts, 'content.list_groups.0.items', []));

        $state = $this->module($payload, 'state_spectrum');
        $this->assertNotSame('', (string) data_get($state, 'content.stable_expression'));
        $this->assertNull(data_get($state, 'content.list_groups'));

        $relationshipStrengths = $this->module($payload, 'relationship_strengths');
        $this->assertSame('relationship_strengths', data_get($relationshipStrengths, 'content.list_groups.0.label_key'));
        $this->assertCount(2, (array) data_get($relationshipStrengths, 'content.list_groups.0.items', []));

        $communication = $this->module($payload, 'communication_manual');
        $this->assertSame('communication_manual', data_get($communication, 'content.list_groups.0.label_key'));
        $this->assertCount(2, (array) data_get($communication, 'content.list_groups.0.items', []));
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
     * @return iterable<string,array{string,string,string,string,string}>
     */
    public static function formProvider(): iterable
    {
        yield 'e105 zh' => ['enneagram_likert_105', 'e105', 'e105_standard', 'zh-CN', 'zh'];
        yield 'e105 en' => ['enneagram_likert_105', 'e105', 'e105_standard', 'en', 'en'];
        yield 'fc144 zh' => ['enneagram_forced_choice_144', 'fc144', 'fc144_forced_choice', 'zh-CN', 'zh'];
        yield 'fc144 en' => ['enneagram_forced_choice_144', 'fc144', 'fc144_forced_choice', 'en', 'en'];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    private function composeReportV2(array $scoreResult): array
    {
        return $this->composeReportV2ForLocale($scoreResult, 'zh-CN');
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    private function composeReportV2ForLocale(array $scoreResult, string $locale): array
    {
        $composer = app(EnneagramReportComposer::class);
        $attempt = new Attempt(['locale' => $locale]);
        $result = new Result(['result_json' => ['normed_json' => $scoreResult]]);

        return $composer->composeVariant($attempt, $result, 'full');
    }

    /**
     * @return array{array<string,float>,array<string,mixed>,array<string,mixed>}
     */
    private function matrixFixture(int $primaryType, string $state): array
    {
        $orderedTypes = array_merge([$primaryType], array_values(array_diff(range(1, 9), [$primaryType])));
        $scoreShapes = [
            'clear' => [90.0, 62.0, 52.0, 37.0, 28.0, 24.0, 21.0, 16.0, 13.0],
            'close_call' => [81.0, 79.0, 47.0, 33.0, 27.0, 23.0, 21.0, 18.0, 17.0],
            'diffuse' => [52.0, 51.0, 50.0, 49.0, 48.0, 47.0, 46.0, 45.0, 44.0],
            'low_quality' => [84.0, 68.0, 52.0, 35.0, 28.0, 21.0, 18.0, 16.0, 12.0],
        ];
        $scores = [];
        foreach ($orderedTypes as $index => $type) {
            $scores['T'.$type] = $scoreShapes[$state][$index];
        }

        $analysisOverrides = $state === 'close_call'
            ? [
                'interpretation_state' => 'mixed_close_call',
                'close_call_candidates' => ['T'.$orderedTypes[0], 'T'.$orderedTypes[1]],
            ]
            : [];
        $qualityOverrides = $state === 'low_quality'
            ? ['level' => 'P2', 'flags' => ['speed_too_fast']]
            : [];

        return [$scores, $analysisOverrides, $qualityOverrides];
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
