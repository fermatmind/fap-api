<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiAdaptiveSelectionService;
use App\Services\Mbti\MbtiResultPersonalizationService;
use ReflectionMethod;
use Tests\TestCase;

final class MbtiAdaptiveSelectionServiceTest extends TestCase
{
    public function test_it_rewrites_sections_actions_recommendations_and_cta_from_observed_signals(): void
    {
        $base = app(MbtiResultPersonalizationService::class)->buildForReportPayload($this->makePayload(), [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase4a_contract',
        ]);

        $adaptive = app(MbtiAdaptiveSelectionService::class)->attach(array_replace_recursive($base, [
            'user_state' => [
                'has_feedback' => true,
                'has_action_engagement' => true,
                'feedback_sentiment' => 'negative',
                'feedback_coverage' => 'explainability_only',
                'action_completion_tendency' => 'repeatable',
                'last_deep_read_section' => 'traits.why_this_type',
                'current_intent_cluster' => 'career_move',
            ],
            'continuity' => [
                'recommended_resume_keys' => ['career.next_step', 'growth.next_actions'],
            ],
            'action_journey_v1' => [
                'completed_action_keys' => [
                    'work_experiment.theme.name_decision_rule',
                    'weekly_action.theme.name_decision_rule',
                ],
                'journey_state' => 'resume_action_loop',
                'progress_state' => 'repeatable',
            ],
            'longitudinal_memory_v1' => [
                'memory_contract_version' => 'mbti.longitudinal_memory.v1',
                'memory_fingerprint' => 'memory-fixture',
                'memory_state' => 'resume_ready',
                'behavior_delta_keys' => [
                    'behavior.revisit.repeat',
                    'behavior.section.career_next_step.repeat',
                ],
                'dominant_interest_keys' => ['career', 'growth'],
                'resume_bias_keys' => ['career.next_step', 'career.work_experiments', 'growth.next_actions'],
                'memory_rewrite_reason' => 'resume_career_focus',
                'memory_evidence' => [
                    'negative_feedback_scores' => ['explainability' => 2],
                    'continue_target_scores' => ['career_recommendation' => 2],
                ],
            ],
        ]));

        $this->assertSame('mbti.adaptive_selection.v1', data_get($adaptive, 'adaptive_selection_v1.adaptive_contract_version'));
        $this->assertNotSame('', trim((string) data_get($adaptive, 'adaptive_selection_v1.adaptive_fingerprint')));
        $this->assertSame('feedback_redirect_to_action', data_get($adaptive, 'adaptive_selection_v1.selection_rewrite_reason'));
        $this->assertContains(
            data_get($adaptive, 'adaptive_selection_v1.next_best_action_v1.section_key'),
            ['career.next_step', 'career.work_experiments']
        );
        $this->assertNotSame('', trim((string) data_get($adaptive, 'adaptive_selection_v1.next_best_action_v1.key')));
        $this->assertSame('career', data_get($adaptive, 'adaptive_selection_v1.next_best_action_v1.family'));
        $this->assertSame(-3, data_get($adaptive, 'adaptive_selection_v1.content_feedback_weights.explainability'));
        $this->assertGreaterThan(0, (int) ((data_get($adaptive, 'adaptive_selection_v1.action_effect_weights') ?? [])['career.next_step'] ?? 0));
        $this->assertGreaterThan(0, (int) data_get($adaptive, 'adaptive_selection_v1.recommendation_effect_weights.career'));
        $this->assertGreaterThan(0, (int) data_get($adaptive, 'adaptive_selection_v1.cta_effect_weights.career_bridge'));
        $this->assertStringContainsString(
            'adaptive.feedback_redirect_to_action',
            (string) (($adaptive['section_selection_keys']['growth.next_actions'] ?? ''))
        );
        $this->assertContains(
            'relationships.try_this_week',
            array_keys((array) data_get($adaptive, 'adaptive_selection_v1.action_effect_weights', []))
        );
        $this->assertCount(4, (array) ($this->section($adaptive, 'relationships.try_this_week')['selected_blocks'] ?? []));
        $this->assertContains(
            'relationship_low_intensity_reconnect',
            array_map(
                static fn (array $block): string => (string) ($block['kind'] ?? ''),
                array_values((array) ($this->section($adaptive, 'relationships.try_this_week')['blocks'] ?? []))
            )
        );
        $this->assertStringContainsString(
            'adaptive.feedback_redirect_to_action',
            (string) (($adaptive['action_selection_keys']['career.next_step'] ?? ''))
        );
        $this->assertContains('career_bridge', array_slice((array) data_get($adaptive, 'orchestration.cta_priority_keys', []), 0, 2));
        $this->assertContains('career.next_step', (array) data_get($adaptive, 'continuity.recommended_resume_keys', []));
        $this->assertSame(
            data_get($adaptive, 'adaptive_selection_v1.next_best_action_v1.section_key'),
            data_get($adaptive, 'continuity.carryover_focus_key')
        );
        $this->assertSame('adaptive_next_best_action', data_get($adaptive, 'continuity.carryover_reason'));
        $this->assertSame(
            data_get($adaptive, 'adaptive_selection_v1.next_best_action_v1.key'),
            data_get($adaptive, 'continuity.carryover_action_keys.0')
        );
        $this->assertContains('read-career', (array) data_get($adaptive, 'recommendation_selection_keys', []));
        $this->assertNotSame(
            (string) (($base['section_selection_keys']['growth.next_actions'] ?? '')),
            (string) (($adaptive['section_selection_keys']['growth.next_actions'] ?? ''))
        );
        $this->assertNotSame(
            trim((string) data_get($base, 'selection_fingerprint')),
            trim((string) data_get($adaptive, 'selection_fingerprint'))
        );
        $this->assertSame(
            'feedback_redirect_to_action',
            data_get($adaptive, 'selection_evidence.adaptive.selection_rewrite_reason')
        );
    }

    public function test_it_does_not_activate_without_real_feedback_memory_or_action_history(): void
    {
        $base = app(MbtiResultPersonalizationService::class)->buildForReportPayload($this->makePayload(), [
            'type_code' => 'ENFP-T',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'locale' => 'zh-CN',
            'engine_version' => 'report_phase4a_contract',
        ]);

        $noAdaptive = app(MbtiAdaptiveSelectionService::class)->attach(array_replace_recursive($base, [
            'user_state' => [
                'has_feedback' => false,
                'has_action_engagement' => false,
                'feedback_sentiment' => 'none',
                'feedback_coverage' => 'none',
                'action_completion_tendency' => 'idle',
                'last_deep_read_section' => '',
                'current_intent_cluster' => 'default',
            ],
            'continuity' => [
                'recommended_resume_keys' => ['growth.next_actions', 'career.next_step'],
            ],
            'longitudinal_memory_v1' => [],
            'action_journey_v1' => [
                'completed_action_keys' => [],
            ],
        ]));

        $this->assertArrayNotHasKey('adaptive_selection_v1', $noAdaptive);
    }

    public function test_it_preserves_existing_cta_order_when_adaptive_weights_are_equal(): void
    {
        $service = app(MbtiAdaptiveSelectionService::class);
        $method = new ReflectionMethod($service, 'mergeOrchestration');
        $method->setAccessible(true);

        /** @var array<string, mixed> $merged */
        $merged = $method->invoke($service, [
            'orchestration' => [
                'cta_priority_keys' => ['unlock_full_report', 'career_bridge', 'share_result', 'workspace_lite'],
            ],
        ], [
            'cta_effect_weights' => [
                'unlock_full_report' => 0,
                'career_bridge' => 0,
                'share_result' => 0,
                'workspace_lite' => 0,
            ],
        ]);

        $this->assertSame(
            ['unlock_full_report', 'career_bridge', 'share_result', 'workspace_lite'],
            array_values($merged['cta_priority_keys'] ?? [])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makePayload(): array
    {
        return [
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
                    'key' => 'read-action',
                    'type' => 'article',
                    'title' => '一周行动实验',
                    'priority' => 30,
                    'tags' => ['growth', 'action'],
                    'url' => 'https://example.com/read-action',
                ],
                [
                    'id' => 'read-career',
                    'key' => 'read-career',
                    'type' => 'article',
                    'title' => '职业环境匹配',
                    'priority' => 10,
                    'tags' => ['career', 'work'],
                    'url' => 'https://example.com/read-career',
                ],
                [
                    'id' => 'read-explain',
                    'key' => 'read-explain',
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(array $payload, string $sectionKey): array
    {
        return (array) (($payload['sections'][$sectionKey] ?? []));
    }
}
