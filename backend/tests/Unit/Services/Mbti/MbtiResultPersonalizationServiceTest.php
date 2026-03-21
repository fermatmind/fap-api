<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiIntraTypeProfileService;
use App\Services\Mbti\MbtiResultPersonalizationService;
use Tests\TestCase;

final class MbtiResultPersonalizationServiceTest extends TestCase
{
    public function test_it_emits_distinct_overview_variants_for_same_type_when_ei_strength_changes(): void
    {
        $service = app(MbtiResultPersonalizationService::class);

        $clearPayload = [
            'versions' => [
                'engine' => 'v1.2',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'ENFP-T',
            ],
            'recommended_reads' => [
                [
                    'id' => 'read-action',
                    'type' => 'article',
                    'title' => '一周行动实验',
                    'priority' => 30,
                    'tags' => ['growth', 'action'],
                    'url' => 'https://example.com/read-action',
                ],
                [
                    'id' => 'read-career',
                    'type' => 'article',
                    'title' => '职业环境匹配',
                    'priority' => 10,
                    'tags' => ['career', 'work'],
                    'url' => 'https://example.com/read-career',
                ],
                [
                    'id' => 'read-explain',
                    'type' => 'article',
                    'title' => '边界类型解释',
                    'priority' => 25,
                    'tags' => ['explainability', 'mbti'],
                    'url' => 'https://example.com/read-explain',
                ],
            ],
            'scores' => [
                'EI' => ['pct' => 67, 'delta' => 17, 'side' => 'E', 'state' => 'clear'],
                'SN' => ['pct' => 64, 'delta' => 14, 'side' => 'N', 'state' => 'clear'],
                'TF' => ['pct' => 59, 'delta' => 9, 'side' => 'T', 'state' => 'balanced'],
                'JP' => ['pct' => 57, 'delta' => 7, 'side' => 'J', 'state' => 'moderate'],
                'AT' => ['pct' => 68, 'delta' => 18, 'side' => 'T', 'state' => 'clear'],
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'balanced',
                'JP' => 'moderate',
                'AT' => 'clear',
            ],
            'norms' => [
                'version_id' => 'norm_2026_02',
                'metrics' => [
                    'EI' => [
                        'score_int' => 67,
                        'percentile' => 0.73,
                        'over_percent' => 73,
                    ],
                ],
            ],
        ];

        $strongPayload = $clearPayload;
        $strongPayload['scores']['EI'] = ['pct' => 77, 'delta' => 27, 'side' => 'E', 'state' => 'strong'];
        $strongPayload['axis_states']['EI'] = 'strong';

        $clear = $service->buildForReportPayload($clearPayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase4a_contract',
        ]);

        $strong = $service->buildForReportPayload($strongPayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase4a_contract',
        ]);

        $this->assertSame('ENFP-T', $clear['type_code']);
        $this->assertSame('T', $clear['identity']);
        $this->assertSame('mbti.personalization.phase9e.v1', $clear['schema_version']);
        $this->assertSame('clear', data_get($clear, 'axis_bands.EI'));
        $this->assertSame('strong', data_get($strong, 'axis_bands.EI'));
        $this->assertSame(false, data_get($clear, 'boundary_flags.EI'));
        $this->assertSame(false, data_get($strong, 'boundary_flags.EI'));
        $this->assertSame('MBTI.cn-mainland.zh-CN.v0.3', $clear['pack_id']);
        $this->assertSame('report_phase4a_contract', $clear['engine_version']);
        $this->assertSame('phase9c.v1', $clear['dynamic_sections_version']);
        $this->assertSame('narrative_runtime_contract.v1', data_get($clear, 'narrative_runtime_contract_v1.version'));
        $this->assertSame('off', data_get($clear, 'narrative_runtime_contract_v1.runtime_mode'));
        $this->assertSame('null', data_get($clear, 'narrative_runtime_contract_v1.provider_name'));
        $this->assertSame(false, data_get($clear, 'narrative_runtime_contract_v1.output_present.narrative_intro'));
        $this->assertSame('controlled_narrative.v1', data_get($clear, 'controlled_narrative_v1.version'));
        $this->assertSame('controlled_narrative.v1', data_get($clear, 'controlled_narrative_v1.narrative_contract_version'));
        $this->assertSame('off', data_get($clear, 'controlled_narrative_v1.runtime_mode'));
        $this->assertSame('', data_get($clear, 'controlled_narrative_v1.narrative_intro'));
        $this->assertSame('cultural_calibration.v1', data_get($clear, 'cultural_calibration_v1.version'));
        $this->assertSame('zh-CN', data_get($clear, 'cultural_calibration_v1.locale_context'));
        $this->assertSame('CN_MAINLAND.zh-CN', data_get($clear, 'cultural_calibration_v1.cultural_context'));
        $this->assertSame('governance.v1', data_get($clear, 'cultural_calibration_v1.calibration_policy_version'));
        $this->assertSame('content_governance', data_get($clear, 'cultural_calibration_v1.calibration_source'));
        $this->assertContains('growth.next_actions', data_get($clear, 'cultural_calibration_v1.calibrated_section_keys', []));
        $this->assertContains('career.next_step', data_get($clear, 'cultural_calibration_v1.calibrated_section_keys', []));
        $this->assertNotSame('', trim((string) data_get($clear, 'cultural_calibration_v1.calibration_fingerprint')));
        $this->assertContains('working_life_v1', data_get($clear, 'narrative_runtime_contract_v1.truth_guard_fields', []));
        $this->assertSame('mbti.privacy_contract.v1', data_get($clear, 'privacy_contract_v1.version'));
        $this->assertSame(true, data_get($clear, 'privacy_contract_v1.consent_scope.subject_export'));
        $this->assertContains(
            'action_plan_summary',
            data_get($clear, 'privacy_contract_v1.exportable_assets.derived_personalization_fields', [])
        );
        $this->assertSame('action_journey.v1', data_get($clear, 'action_journey_v1.journey_contract_version'));
        $this->assertSame('result_revisit', data_get($clear, 'action_journey_v1.journey_scope'));
        $this->assertSame('first_view_activation', data_get($clear, 'action_journey_v1.journey_state'));
        $this->assertSame('not_started', data_get($clear, 'action_journey_v1.progress_state'));
        $this->assertSame('pulse_check.v1', data_get($clear, 'pulse_check_v1.pulse_contract_version'));
        $this->assertSame('mbti.intra_type_profile.v1', data_get($clear, 'intra_type_profile_v1.version'));
        $this->assertSame('same_type.seed.name_decision_rule.jp', data_get($clear, 'profile_seed_key'));
        $this->assertSame(
            data_get($clear, 'profile_seed_key'),
            data_get($clear, 'intra_type_profile_v1.profile_seed_key')
        );
        $this->assertContains('same_type.boundary_axis.jp', data_get($clear, 'same_type_divergence_keys', []));
        $this->assertContains('same_type.boundary_axis.tf', data_get($clear, 'same_type_divergence_keys', []));
        $this->assertNotSame('', trim((string) data_get($clear, 'selection_fingerprint')));
        $this->assertNotSame(
            trim((string) data_get($clear, 'selection_fingerprint')),
            trim((string) data_get($strong, 'selection_fingerprint'))
        );
        $this->assertSame(
            [
                'growth.next_actions.next_action.EI.E',
                'growth.next_actions.boundary.TF',
                'growth.next_actions.identity.t',
            ],
            $clear['sections']['growth.next_actions']['selected_blocks'] ?? null
        );
        $this->assertSame(
            [
                'career.work_experiments.work_experiment.EI.E',
                'career.work_experiments.boundary.JP',
                'career.work_experiments.identity.t',
            ],
            $clear['sections']['career.work_experiments']['selected_blocks'] ?? null
        );
        $this->assertStringContainsString(
            'mode.action_boundary_buffered',
            (string) ($clear['section_selection_keys']['growth.next_actions'] ?? '')
        );
        $this->assertStringContainsString(
            'growth_next_actions_next_action_ei_e+growth_next_actions_boundary_tf+growth_next_actions_identity_t',
            (string) ($clear['section_selection_keys']['growth.next_actions'] ?? '')
        );
        $this->assertStringContainsString(
            'mode.career_experiment_boundary',
            (string) ($clear['action_selection_keys']['career.work_experiments'] ?? '')
        );
        $this->assertSame(
            ['read-career', 'read-explain', 'read-action'],
            data_get($clear, 'recommendation_selection_keys')
        );
        $this->assertSame(
            ['TF', 'JP'],
            data_get($clear, 'selection_evidence.axis.boundary_axes')
        );
        $this->assertSame('comparative.norming.v1', data_get($clear, 'comparative_v1.version'));
        $this->assertSame(true, data_get($clear, 'comparative_v1.enabled'));
        $this->assertSame(73, data_get($clear, 'comparative_v1.percentile.value'));
        $this->assertSame('norm_2026_02', data_get($clear, 'comparative_v1.norming_version'));
        $this->assertSame('same_type.boundary_axes', data_get($clear, 'comparative_v1.same_type_contrast.key'));
        $this->assertSame(
            [
                'is_first_view' => true,
                'is_revisit' => false,
                'has_unlock' => false,
                'has_feedback' => false,
                'has_share' => false,
                'has_action_engagement' => false,
                'feedback_sentiment' => 'none',
                'feedback_coverage' => 'none',
                'action_completion_tendency' => 'idle',
                'last_deep_read_section' => '',
                'current_intent_cluster' => 'default',
            ],
            data_get($clear, 'user_state')
        );
        $this->assertSame('growth.next_actions', data_get($clear, 'orchestration.primary_focus_key'));
        $this->assertSame(
            ['unlock_full_report', 'career_bridge', 'share_result'],
            data_get($clear, 'orchestration.cta_priority_keys')
        );
        $this->assertSame(
            ['read-action', 'read-career', 'read-explain'],
            data_get($clear, 'ordered_recommendation_keys')
        );
        $this->assertSame('read-action', data_get($clear, 'reading_focus_key'));
        $this->assertSame(
            [
                'weekly_action.theme.name_decision_rule',
                'work_experiment.theme.name_decision_rule',
                'relationship_action.theme.name_decision_rule',
                'watchout.stability.context_sensitive',
            ],
            array_slice((array) data_get($clear, 'ordered_action_keys', []), 0, 4)
        );
        $this->assertSame(
            [
                'read-action',
                'read-career',
                'read-explain',
            ],
            data_get($clear, 'recommendation_priority_keys')
        );
        $this->assertSame('weekly_action.theme.name_decision_rule', data_get($clear, 'action_focus_key'));
        $this->assertSame('growth.next_actions', data_get($clear, 'continuity.carryover_focus_key'));
        $this->assertSame('unlock_to_continue_focus', data_get($clear, 'continuity.carryover_reason'));
        $this->assertSame(
            ['growth.next_actions', 'traits.close_call_axes', 'traits.adjacent_type_contrast'],
            data_get($clear, 'continuity.recommended_resume_keys')
        );
        $this->assertSame(
            ['growth', 'explainability'],
            data_get($clear, 'continuity.carryover_scene_keys')
        );
        $this->assertContains(
            'weekly_action.theme.name_decision_rule',
            data_get($clear, 'continuity.carryover_action_keys', [])
        );
        $this->assertLessThan(
            array_search('growth.summary', data_get($clear, 'orchestration.ordered_section_keys', []), true),
            array_search('growth.next_actions', data_get($clear, 'orchestration.ordered_section_keys', []), true)
        );
        $this->assertNotSame('', trim((string) ($clear['explainability_summary'] ?? '')));
        $this->assertSame(
            ['ENFJ', 'ENTP'],
            data_get($clear, 'neighbor_type_keys')
        );
        $this->assertSame(
            ['JP', 'TF'],
            array_map(static fn (array $axis): string => (string) ($axis['axis'] ?? ''), data_get($clear, 'close_call_axes', []))
        );
        $this->assertContains('stability.bucket.context_sensitive', data_get($clear, 'confidence_or_stability_keys', []));
        $this->assertStringContainsString(
            '先用把能量投向外部互动',
            (string) ($clear['work_style_summary'] ?? '')
        );
        $this->assertSame(
            [
                'role_fit.role.NF',
                'role_fit.primary.EI.E.clear',
                'role_fit.support.JP.J.boundary',
                'role_fit.identity.T',
                'role_fit.boundary.JP',
                'role_fit.boundary.TF',
            ],
            data_get($clear, 'role_fit_keys')
        );
        $this->assertSame(
            [
                'collaboration_fit.primary.EI.E.clear',
                'collaboration_fit.support.TF.T.boundary',
                'collaboration_fit.identity.T',
                'collaboration_fit.boundary.TF',
                'collaboration_fit.boundary.JP',
                'collaboration_fit.decision_boundary.TF',
                'collaboration_fit.decision_boundary.JP',
            ],
            data_get($clear, 'collaboration_fit_keys')
        );
        $this->assertContains('work_env.preference.high_collaboration', data_get($clear, 'work_env_preference_keys'));
        $this->assertContains('work_env.boundary.JP', data_get($clear, 'work_env_preference_keys'));
        $this->assertContains('career_next_step.theme.clarify_decision_criteria', data_get($clear, 'career_next_step_keys'));
        $this->assertSame('mbti.working_life.v1', data_get($clear, 'working_life_v1.version'));
        $this->assertSame('career.next_step', data_get($clear, 'working_life_v1.career_focus_key'));
        $this->assertSame(
            ['career.next_step', 'career.work_experiments', 'career.work_environment', 'career.collaboration_fit'],
            data_get($clear, 'working_life_v1.career_journey_keys')
        );
        $this->assertContains('career_bridge', data_get($clear, 'working_life_v1.career_action_priority_keys', []));
        $this->assertSame('career.next_step', data_get($clear, 'career_focus_key'));
        $this->assertSame(
            ['career.next_step', 'career.work_experiments', 'career.work_environment', 'career.collaboration_fit'],
            data_get($clear, 'career_journey_keys')
        );
        $this->assertStringContainsString('一周内能重复', (string) ($clear['action_plan_summary'] ?? ''));
        $this->assertContains('weekly_action.theme.name_decision_rule', data_get($clear, 'weekly_action_keys'));
        $this->assertContains('relationship_action.theme.name_decision_rule', data_get($clear, 'relationship_action_keys'));
        $this->assertContains('work_experiment.theme.name_decision_rule', data_get($clear, 'work_experiment_keys'));
        $this->assertContains('watchout.stability.context_sensitive', data_get($clear, 'watchout_keys'));
        $this->assertSame('work.primary.EI.E.clear', data_get($clear, 'scene_fingerprint.work.style_key'));
        $this->assertSame('work.primary.EI.E.strong', data_get($strong, 'scene_fingerprint.work.style_key'));
        $this->assertSame(
            [
                'relationships.primary.TF.T.boundary',
                'relationships.support.EI.E.clear',
                'relationships.identity.T',
                'relationships.boundary.TF',
                'relationships.boundary.JP',
            ],
            data_get($clear, 'relationship_style_keys')
        );
        $this->assertSame(
            [
                'communication.primary.EI.E.clear',
                'communication.support.TF.T.boundary',
                'communication.identity.T',
                'communication.boundary.TF',
                'communication.boundary.JP',
            ],
            data_get($clear, 'communication_style_keys')
        );
        $this->assertNotSame(
            data_get($clear, 'variant_keys.overview'),
            data_get($strong, 'variant_keys.overview')
        );
        $this->assertSame(
            'overview:EI.E.clear:identity.T:boundary.none',
            data_get($clear, 'variant_keys.overview')
        );
        $this->assertSame(
            'overview:EI.E.strong:identity.T:boundary.none',
            data_get($strong, 'variant_keys.overview')
        );
        $this->assertSame(
            'traits.why_this_type:EI.E.clear:identity.T:boundary.JP',
            $clear['variant_keys']['traits.why_this_type'] ?? null
        );
        $this->assertSame(
            'traits.close_call_axes:JP.J.boundary:identity.T:boundary.JP',
            $clear['variant_keys']['traits.close_call_axes'] ?? null
        );
        $this->assertSame(
            'traits.adjacent_type_contrast:JP.J.boundary:identity.T:neighbor.ENFJ',
            $clear['variant_keys']['traits.adjacent_type_contrast'] ?? null
        );
        $this->assertSame(
            'growth.stability_confidence:stability.context_sensitive:identity.T:boundary.JP',
            $clear['variant_keys']['growth.stability_confidence'] ?? null
        );
        $this->assertSame(
            'traits.why_this_type:dominant.EI.E.clear',
            $clear['contrast_keys']['traits.why_this_type'] ?? null
        );
        $this->assertSame(
            'traits.adjacent_type_contrast:neighbor.ENFJ-ENTP',
            $clear['contrast_keys']['traits.adjacent_type_contrast'] ?? null
        );
        $this->assertSame(
            'relationships.rel_risks:TF.T.boundary:identity.T:boundary.TF',
            $clear['variant_keys']['relationships.rel_risks'] ?? null
        );
        $this->assertSame(
            'traits.decision_style:TF.T.boundary:identity.T:boundary.TF',
            $clear['variant_keys']['traits.decision_style'] ?? null
        );
        $this->assertSame(
            'growth.stress_recovery:JP.J.boundary:identity.T:boundary.JP',
            $clear['variant_keys']['growth.stress_recovery'] ?? null
        );
        $this->assertSame(
            'relationships.communication_style:EI.E.clear:identity.T:boundary.TF',
            $clear['variant_keys']['relationships.communication_style'] ?? null
        );
        $this->assertSame(
            'career.collaboration_fit:EI.E.clear:identity.T:boundary.TF',
            $clear['variant_keys']['career.collaboration_fit'] ?? null
        );
        $this->assertSame(
            'career.work_environment:EI.E.clear:identity.T:boundary.JP',
            $clear['variant_keys']['career.work_environment'] ?? null
        );
        $this->assertSame(
            'career.work_experiments:EI.E.clear:identity.T:action.work_experiment_theme_name_decision_rule:boundary.JP',
            $clear['variant_keys']['career.work_experiments'] ?? null
        );
        $this->assertSame(
            'career.next_step:TF.T.boundary:identity.T:boundary.TF',
            $clear['variant_keys']['career.next_step'] ?? null
        );
        $this->assertSame(
            'growth.next_actions:EI.E.clear:identity.T:action.weekly_action_theme_name_decision_rule:boundary.TF',
            $clear['variant_keys']['growth.next_actions'] ?? null
        );
        $this->assertSame(
            'growth.weekly_experiments:EI.E.clear:identity.T:action.weekly_action_theme_name_decision_rule:boundary.TF',
            $clear['variant_keys']['growth.weekly_experiments'] ?? null
        );
        $this->assertSame(
            'growth.watchouts:JP.J.boundary:identity.T:action.watchout_stability_context_sensitive:boundary.JP',
            $clear['variant_keys']['growth.watchouts'] ?? null
        );
        $this->assertSame(
            'relationships.try_this_week:EI.E.clear:identity.T:action.relationship_action_theme_name_decision_rule:boundary.TF',
            $clear['variant_keys']['relationships.try_this_week'] ?? null
        );
        $this->assertNotSame(
            data_get($clear, 'sections.overview.selected_blocks.0'),
            data_get($strong, 'sections.overview.selected_blocks.0')
        );
        $this->assertStringContainsString(
            '稳定的外倾倾向',
            (string) data_get($clear, 'sections.overview.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            '外倾偏好已经很鲜明',
            (string) data_get($strong, 'sections.overview.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            '两套判断入口之间来回校准',
            (string) ($clear['sections']['relationships.rel_risks']['blocks'][3]['text'] ?? '')
        );
        $this->assertSame(
            'decision',
            $clear['sections']['traits.decision_style']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'stress_recovery',
            $clear['sections']['growth.stress_recovery']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'communication',
            $clear['sections']['relationships.communication_style']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'work_style',
            $clear['sections']['career.summary']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'collaboration_fit',
            $clear['sections']['career.collaboration_fit']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'work_env',
            $clear['sections']['career.work_environment']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'career_next_step',
            $clear['sections']['career.next_step']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'work_experiment',
            $clear['sections']['career.work_experiments']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'why_this_type',
            $clear['sections']['traits.why_this_type']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'borderline_axis',
            $clear['sections']['traits.close_call_axes']['blocks'][0]['kind'] ?? null
        );
        $this->assertSame(
            'adjacent_type_contrast',
            $clear['sections']['traits.adjacent_type_contrast']['blocks'][0]['kind'] ?? null
        );
        $this->assertSame(
            'stability_explanation',
            $clear['sections']['growth.stability_confidence']['blocks'][0]['kind'] ?? null
        );
        $this->assertSame(
            'next_action',
            $clear['sections']['growth.next_actions']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'weekly_experiment',
            $clear['sections']['growth.weekly_experiments']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'watchout',
            $clear['sections']['growth.watchouts']['blocks'][1]['kind'] ?? null
        );
        $this->assertSame(
            'relationship_practice',
            $clear['sections']['relationships.try_this_week']['blocks'][1]['kind'] ?? null
        );
        $this->assertStringContainsString(
            '主类型',
            (string) ($clear['sections']['traits.why_this_type']['blocks'][1]['text'] ?? '')
        );
        $this->assertStringContainsString(
            '只拉开了7个点差',
            (string) ($clear['sections']['traits.close_call_axes']['blocks'][0]['text'] ?? '')
        );
        $this->assertStringContainsString(
            '最容易把你看成ENFJ',
            (string) ($clear['sections']['traits.adjacent_type_contrast']['blocks'][0]['text'] ?? '')
        );
        $this->assertStringContainsString(
            '情境敏感型稳定',
            (string) ($clear['sections']['growth.stability_confidence']['blocks'][0]['text'] ?? '')
        );
        $this->assertStringContainsString(
            '压力升高时',
            (string) data_get($clear, 'scene_fingerprint.stress_recovery.summary', '')
        );
    }

    public function test_it_can_change_same_type_selected_blocks_when_user_intent_changes_without_changing_type(): void
    {
        $personalizationService = app(MbtiResultPersonalizationService::class);
        $intraTypeProfileService = app(MbtiIntraTypeProfileService::class);

        $payload = [
            'versions' => [
                'engine' => 'v1.2',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'ENFP-T',
            ],
            'scores' => [
                'EI' => ['pct' => 67, 'delta' => 17, 'side' => 'E', 'state' => 'clear'],
                'SN' => ['pct' => 64, 'delta' => 14, 'side' => 'N', 'state' => 'clear'],
                'TF' => ['pct' => 59, 'delta' => 9, 'side' => 'T', 'state' => 'balanced'],
                'JP' => ['pct' => 57, 'delta' => 7, 'side' => 'J', 'state' => 'moderate'],
                'AT' => ['pct' => 68, 'delta' => 18, 'side' => 'T', 'state' => 'clear'],
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'balanced',
                'JP' => 'moderate',
                'AT' => 'clear',
            ],
        ];

        $base = $personalizationService->buildForReportPayload($payload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase4a_contract',
        ]);

        $clarify = $intraTypeProfileService->attach(array_replace_recursive($base, [
            'user_state' => [
                'current_intent_cluster' => 'clarify_type',
            ],
        ]));
        $careerMove = $intraTypeProfileService->attach(array_replace_recursive($base, [
            'user_state' => [
                'current_intent_cluster' => 'career_move',
            ],
        ]));

        $this->assertSame('ENFP-T', $clarify['type_code']);
        $this->assertSame('ENFP-T', $careerMove['type_code']);
        $this->assertSame('T', $clarify['identity']);
        $this->assertSame('T', $careerMove['identity']);
        $this->assertSame(
            [
                'growth.next_actions.next_action.EI.E',
                'growth.next_actions.axis_strength.EI.E.clear',
                'growth.next_actions.identity.t',
            ],
            $clarify['sections']['growth.next_actions']['selected_blocks'] ?? null
        );
        $this->assertSame(
            [
                'growth.next_actions.next_action.EI.E',
                'growth.next_actions.axis_strength.EI.E.clear',
                'growth.next_actions.boundary.TF',
            ],
            $careerMove['sections']['growth.next_actions']['selected_blocks'] ?? null
        );
        $this->assertNotSame(
            $clarify['sections']['growth.next_actions']['selected_blocks'] ?? null,
            $careerMove['sections']['growth.next_actions']['selected_blocks'] ?? null
        );
        $this->assertStringContainsString(
            'mode.action_explainable',
            (string) ($clarify['section_selection_keys']['growth.next_actions'] ?? '')
        );
        $this->assertStringContainsString(
            'mode.action_career_bridge',
            (string) ($careerMove['section_selection_keys']['growth.next_actions'] ?? '')
        );
        $this->assertNotSame(
            data_get($clarify, 'selection_fingerprint'),
            data_get($careerMove, 'selection_fingerprint')
        );
    }

    public function test_it_changes_phase4a_scene_variant_keys_when_jp_band_changes(): void
    {
        $service = app(MbtiResultPersonalizationService::class);

        $boundaryPayload = [
            'versions' => [
                'engine' => 'v1.2',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'ENFP-T',
            ],
            'scores' => [
                'EI' => ['pct' => 67, 'delta' => 17, 'side' => 'E', 'state' => 'clear'],
                'SN' => ['pct' => 64, 'delta' => 14, 'side' => 'N', 'state' => 'clear'],
                'TF' => ['pct' => 59, 'delta' => 9, 'side' => 'T', 'state' => 'balanced'],
                'JP' => ['pct' => 57, 'delta' => 7, 'side' => 'J', 'state' => 'moderate'],
                'AT' => ['pct' => 68, 'delta' => 18, 'side' => 'T', 'state' => 'clear'],
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'balanced',
                'JP' => 'moderate',
                'AT' => 'clear',
            ],
        ];

        $clearPayload = $boundaryPayload;
        $clearPayload['scores']['JP'] = ['pct' => 66, 'delta' => 16, 'side' => 'J', 'state' => 'clear'];
        $clearPayload['axis_states']['JP'] = 'clear';

        $boundary = $service->buildForReportPayload($boundaryPayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase4a_contract',
        ]);

        $clear = $service->buildForReportPayload($clearPayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase4a_contract',
        ]);

        $this->assertSame(
            'growth.stress_recovery:JP.J.boundary:identity.T:boundary.JP',
            $boundary['variant_keys']['growth.stress_recovery'] ?? null
        );
        $this->assertSame(
            'growth.stress_recovery:JP.J.clear:identity.T:boundary.TF',
            $clear['variant_keys']['growth.stress_recovery'] ?? null
        );
        $this->assertSame(
            'traits.close_call_axes:JP.J.boundary:identity.T:boundary.JP',
            $boundary['variant_keys']['traits.close_call_axes'] ?? null
        );
        $this->assertSame(
            'traits.close_call_axes:TF.T.boundary:identity.T:boundary.TF',
            $clear['variant_keys']['traits.close_call_axes'] ?? null
        );
        $this->assertNotSame(
            $boundary['variant_keys']['traits.adjacent_type_contrast'] ?? null,
            $clear['variant_keys']['traits.adjacent_type_contrast'] ?? null
        );
        $this->assertNotSame(
            $boundary['variant_keys']['growth.stress_recovery'] ?? null,
            $clear['variant_keys']['growth.stress_recovery'] ?? null
        );
        $this->assertStringContainsString(
            '过载时和恢复时可能会切到不同挡位',
            (string) ($boundary['sections']['growth.stress_recovery']['blocks'][0]['text'] ?? '')
        );
        $this->assertStringContainsString(
            '更稳定的应对入口',
            (string) ($clear['sections']['growth.stress_recovery']['blocks'][0]['text'] ?? '')
        );
    }

    public function test_it_reuses_authoritative_dominant_axis_for_explainability_sections(): void
    {
        $service = app(MbtiResultPersonalizationService::class);

        $payload = [
            'versions' => [
                'engine' => 'v1.2',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'INTP-A',
            ],
            'scores' => [
                'EI' => ['pct' => 57, 'delta' => 7, 'side' => 'I', 'state' => 'moderate'],
                'SN' => ['pct' => 80, 'delta' => 30, 'side' => 'N', 'state' => 'strong'],
                'TF' => ['pct' => 61, 'delta' => 11, 'side' => 'T', 'state' => 'balanced'],
                'JP' => ['pct' => 55, 'delta' => 5, 'side' => 'P', 'state' => 'moderate'],
                'AT' => ['pct' => 66, 'delta' => 16, 'side' => 'A', 'state' => 'clear'],
            ],
            'axis_states' => [
                'EI' => 'moderate',
                'SN' => 'strong',
                'TF' => 'balanced',
                'JP' => 'moderate',
                'AT' => 'clear',
            ],
        ];

        $personalization = $service->buildForReportPayload($payload, [
            'type_code' => 'INTP-A',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase6a_contract',
        ]);

        $this->assertSame('SN', data_get($personalization, 'dominant_axes.0.axis'));
        $this->assertSame(
            'traits.why_this_type:SN.N.strong:identity.A:boundary.JP',
            $personalization['variant_keys']['traits.why_this_type'] ?? null
        );
        $this->assertSame(
            'traits.why_this_type:dominant.SN.N.strong',
            $personalization['contrast_keys']['traits.why_this_type'] ?? null
        );
        $this->assertSame(
            'SN',
            $personalization['sections']['traits.why_this_type']['primary_axis']['axis'] ?? null
        );
        $this->assertSame(
            'SN',
            $personalization['sections']['growth.stability_confidence']['primary_axis']['axis'] ?? null
        );
    }

    public function test_it_uses_close_call_thresholds_for_stability_fallback_when_clarity_is_absent(): void
    {
        $service = app(MbtiResultPersonalizationService::class);

        $stablePayload = [
            'versions' => [
                'engine' => 'v1.2',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'ENFP-T',
            ],
            'scores' => [
                'EI' => ['pct' => 78, 'delta' => 28, 'side' => 'E', 'state' => 'strong'],
                'SN' => ['pct' => 66, 'delta' => 16, 'side' => 'N', 'state' => 'clear'],
                'TF' => ['pct' => 64, 'delta' => 14, 'side' => 'T', 'state' => 'clear'],
                'JP' => ['pct' => 63, 'delta' => 13, 'side' => 'J', 'state' => 'clear'],
                'AT' => ['pct' => 68, 'delta' => 18, 'side' => 'T', 'state' => 'clear'],
            ],
            'axis_states' => [
                'EI' => 'strong',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
        ];

        $mixedPayload = $stablePayload;
        $mixedPayload['scores']['JP'] = ['pct' => 57, 'delta' => 7, 'side' => 'J', 'state' => 'moderate'];
        $mixedPayload['axis_states']['JP'] = 'moderate';

        $contextSensitivePayload = $mixedPayload;
        $contextSensitivePayload['scores']['TF'] = ['pct' => 59, 'delta' => 9, 'side' => 'T', 'state' => 'balanced'];
        $contextSensitivePayload['axis_states']['TF'] = 'balanced';

        $stable = $service->buildForReportPayload($stablePayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase6a_contract',
        ]);
        $mixed = $service->buildForReportPayload($mixedPayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase6a_contract',
        ]);
        $contextSensitive = $service->buildForReportPayload($contextSensitivePayload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase6a_contract',
        ]);

        $this->assertContains('stability.bucket.stable', data_get($stable, 'confidence_or_stability_keys', []));
        $this->assertSame(
            'growth.stability_confidence:stability.stable',
            $stable['contrast_keys']['growth.stability_confidence'] ?? null
        );
        $this->assertContains('stability.bucket.mixed', data_get($mixed, 'confidence_or_stability_keys', []));
        $this->assertSame(
            'growth.stability_confidence:stability.mixed',
            $mixed['contrast_keys']['growth.stability_confidence'] ?? null
        );
        $this->assertContains('stability.bucket.context_sensitive', data_get($contextSensitive, 'confidence_or_stability_keys', []));
        $this->assertSame(
            'growth.stability_confidence:stability.context_sensitive',
            $contextSensitive['contrast_keys']['growth.stability_confidence'] ?? null
        );
    }

    public function test_it_picks_neighbor_types_from_the_closest_non_at_axes(): void
    {
        $service = app(MbtiResultPersonalizationService::class);

        $payload = [
            'versions' => [
                'engine' => 'v1.2',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'ENFP-T',
            ],
            'scores' => [
                'EI' => ['pct' => 70, 'delta' => 20, 'side' => 'E', 'state' => 'clear'],
                'SN' => ['pct' => 72, 'delta' => 22, 'side' => 'N', 'state' => 'clear'],
                'TF' => ['pct' => 68, 'delta' => 18, 'side' => 'T', 'state' => 'clear'],
                'JP' => ['pct' => 66, 'delta' => 16, 'side' => 'J', 'state' => 'clear'],
                'AT' => ['pct' => 52, 'delta' => 2, 'side' => 'T', 'state' => 'balanced'],
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'balanced',
            ],
        ];

        $personalization = $service->buildForReportPayload($payload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase6a_contract',
        ]);

        $this->assertSame(['AT', 'JP'], array_map(
            static fn (array $axis): string => (string) ($axis['axis'] ?? ''),
            data_get($personalization, 'close_call_axes', [])
        ));
        $this->assertSame(
            ['ENFJ'],
            data_get($personalization, 'neighbor_type_keys')
        );
    }

    public function test_it_falls_back_to_english_defaults_when_requested_locale_does_not_match_pack_locale(): void
    {
        $service = app(MbtiResultPersonalizationService::class);

        $payload = [
            'versions' => [
                'engine' => 'report_phase4a_contract',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'ENFP-T',
            ],
            'scores' => [
                'EI' => ['pct' => 67, 'delta' => 17, 'side' => 'E', 'state' => 'clear'],
                'SN' => ['pct' => 64, 'delta' => 14, 'side' => 'N', 'state' => 'clear'],
                'TF' => ['pct' => 59, 'delta' => 9, 'side' => 'T', 'state' => 'balanced'],
                'JP' => ['pct' => 57, 'delta' => 7, 'side' => 'P', 'state' => 'moderate'],
                'AT' => ['pct' => 68, 'delta' => 18, 'side' => 'T', 'state' => 'clear'],
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'balanced',
                'JP' => 'moderate',
                'AT' => 'clear',
            ],
        ];

        $english = $service->buildForReportPayload($payload, [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'en',
            'engine_version' => 'report_phase4a_contract',
        ]);

        $this->assertSame('en', $english['locale']);
        $this->assertStringContainsString(
            'stable Extraversion preference',
            (string) data_get($english, 'sections.overview.blocks.0.text', '')
        );
        $this->assertStringContainsString(
            'At work, you usually start through',
            (string) data_get($english, 'scene_fingerprint.work.summary', '')
        );
        $this->assertStringNotContainsString(
            '稳定的外倾倾向',
            (string) data_get($english, 'sections.overview.blocks.0.text', '')
        );
        $this->assertStringNotContainsString(
            '在工作里',
            (string) data_get($english, 'scene_fingerprint.work.summary', '')
        );
    }

    public function test_it_can_emit_visible_controlled_narrative_without_changing_canonical_truth(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.narrative.enabled', true);
        config()->set('ai.narrative.provider', 'mock');
        config()->set('ai.breaker_enabled', false);

        $personalization = app(MbtiResultPersonalizationService::class)->buildForReportPayload([
            'versions' => [
                'engine' => 'v1.2',
                'content_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
            ],
            'profile' => [
                'type_code' => 'INTJ-A',
            ],
            'scores' => [
                'EI' => ['pct' => 43, 'delta' => -7, 'side' => 'I', 'state' => 'moderate'],
                'SN' => ['pct' => 71, 'delta' => 21, 'side' => 'N', 'state' => 'strong'],
                'TF' => ['pct' => 66, 'delta' => 16, 'side' => 'T', 'state' => 'clear'],
                'JP' => ['pct' => 61, 'delta' => 11, 'side' => 'J', 'state' => 'clear'],
                'AT' => ['pct' => 59, 'delta' => 9, 'side' => 'A', 'state' => 'balanced'],
            ],
            'axis_states' => [
                'EI' => 'moderate',
                'SN' => 'strong',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'balanced',
            ],
        ], [
            'type_code' => 'INTJ-A',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase4a_contract',
        ]);

        $this->assertSame('mock', data_get($personalization, 'narrative_runtime_contract_v1.runtime_mode'));
        $this->assertNotSame('', trim((string) data_get($personalization, 'controlled_narrative_v1.narrative_intro')));
        $this->assertNotSame('', trim((string) data_get($personalization, 'controlled_narrative_v1.narrative_summary')));
        $this->assertSame('cultural_calibration.v1', data_get($personalization, 'cultural_calibration_v1.version'));
        $this->assertSame('zh-CN', data_get($personalization, 'cultural_calibration_v1.locale_context'));
        $this->assertSame('INTJ-A', (string) ($personalization['type_code'] ?? ''));
        $this->assertSame('A', (string) ($personalization['identity'] ?? ''));
    }
}
