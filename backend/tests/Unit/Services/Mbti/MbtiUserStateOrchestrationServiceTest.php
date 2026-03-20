<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiResultPersonalizationService;
use App\Services\Mbti\MbtiUserStateOrchestrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiUserStateOrchestrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlay_effective_marks_revisit_feedback_share_and_action_engagement(): void
    {
        $personalization = app(MbtiResultPersonalizationService::class)->buildForReportPayload(
            $this->reportPayload(),
            [
                'type_code' => 'ENFP-T',
                'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
                'locale' => 'zh-CN',
                'engine_version' => 'report_phase4a_contract',
                'has_unlock' => true,
            ]
        );

        DB::table('events')->insert([
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'result_view',
                'event_name' => 'result_view',
                'org_id' => 7,
                'attempt_id' => 'attempt-phase7b',
                'meta_json' => json_encode(['attempt_id' => 'attempt-phase7b'], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'accuracy_feedback',
                'event_name' => 'accuracy_feedback',
                'org_id' => 7,
                'attempt_id' => 'attempt-phase7b',
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.stability_confidence',
                    'feedback' => 'unclear',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(4),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 7,
                'attempt_id' => 'attempt-phase7b',
                'meta_json' => json_encode([
                    'sectionKey' => 'traits.close_call_axes',
                    'interaction' => 'dwell_2500ms',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(3),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 7,
                'attempt_id' => 'attempt-phase7b',
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.next_actions',
                    'actionKey' => 'weekly_action.theme.name_decision_rule',
                    'interaction' => 'click',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(2),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('shares')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => 'attempt-phase7b',
            'anon_id' => 'anon-phase7b',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'content_package_version' => 'v0.3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $effective = app(MbtiUserStateOrchestrationService::class)->overlayEffective(
            $personalization,
            7,
            'attempt-phase7b',
            true
        );

        $this->assertSame(
            [
                'is_first_view' => false,
                'is_revisit' => true,
                'has_unlock' => true,
                'has_feedback' => true,
                'has_share' => true,
                'has_action_engagement' => true,
                'feedback_sentiment' => 'negative',
                'feedback_coverage' => 'explainability_only',
                'action_completion_tendency' => 'repeatable',
                'last_deep_read_section' => 'traits.close_call_axes',
                'current_intent_cluster' => 'action_activation',
            ],
            data_get($effective, 'user_state')
        );
        $this->assertSame('traits.close_call_axes', data_get($effective, 'orchestration.primary_focus_key'));
        $this->assertSame(
            ['workspace_lite', 'career_bridge', 'share_result'],
            data_get($effective, 'orchestration.cta_priority_keys')
        );
        $this->assertSame(
            ['read-action', 'read-relationship', 'read-explain', 'read-career'],
            data_get($effective, 'ordered_recommendation_keys')
        );
        $this->assertSame('read-action', data_get($effective, 'reading_focus_key'));
        $this->assertSame(
            ['growth.weekly_experiments', 'career.work_experiments'],
            data_get($effective, 'orchestration.secondary_focus_keys')
        );
        $this->assertSame('work_experiment.theme.name_decision_rule', data_get($effective, 'action_focus_key'));
        $this->assertSame(
            [
                'work_experiment.theme.name_decision_rule',
                'weekly_action.theme.name_decision_rule',
                'relationship_action.theme.name_decision_rule',
                'watchout.stability.context_sensitive',
            ],
            data_get($effective, 'ordered_action_keys')
        );
        $this->assertContains('work_experiment.theme.name_decision_rule', data_get($effective, 'ordered_action_keys', []));
        $this->assertContains('relationship_action.theme.name_decision_rule', data_get($effective, 'ordered_action_keys', []));
        $this->assertSame('traits.close_call_axes', data_get($effective, 'continuity.carryover_focus_key'));
        $this->assertSame('resume_action_loop', data_get($effective, 'continuity.carryover_reason'));
        $this->assertSame(
            ['traits.close_call_axes', 'growth.weekly_experiments', 'career.work_experiments'],
            data_get($effective, 'continuity.recommended_resume_keys')
        );
        $this->assertSame(
            ['explainability', 'growth', 'work'],
            data_get($effective, 'continuity.carryover_scene_keys')
        );
        $this->assertContains('weekly_action.theme.name_decision_rule', data_get($effective, 'continuity.carryover_action_keys', []));
        $this->assertContains('work_experiment.theme.name_decision_rule', data_get($effective, 'continuity.carryover_action_keys', []));
    }

    public function test_overlay_effective_changes_focus_ordering_when_deepened_user_state_signals_shift(): void
    {
        $personalization = app(MbtiResultPersonalizationService::class)->buildForReportPayload(
            $this->reportPayload(),
            [
                'type_code' => 'ENFP-T',
                'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'dir_version' => 'MBTI-CN-v0.3',
                'locale' => 'zh-CN',
                'engine_version' => 'report_phase4a_contract',
                'has_unlock' => true,
            ]
        );

        DB::table('events')->insert([
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'result_view',
                'event_name' => 'result_view',
                'org_id' => 7,
                'attempt_id' => 'attempt-clarify',
                'meta_json' => json_encode(['attempt_id' => 'attempt-clarify'], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(6),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'accuracy_feedback',
                'event_name' => 'accuracy_feedback',
                'org_id' => 7,
                'attempt_id' => 'attempt-clarify',
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.stability_confidence',
                    'feedback' => 'unclear',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 7,
                'attempt_id' => 'attempt-clarify',
                'meta_json' => json_encode([
                    'sectionKey' => 'traits.close_call_axes',
                    'interaction' => 'dwell_2500ms',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(4),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 7,
                'attempt_id' => 'attempt-clarify',
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.next_actions',
                    'actionKey' => 'weekly_action.theme.name_decision_rule',
                    'interaction' => 'click',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(3),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'result_view',
                'event_name' => 'result_view',
                'org_id' => 7,
                'attempt_id' => 'attempt-career',
                'meta_json' => json_encode(['attempt_id' => 'attempt-career'], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(6),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'accuracy_feedback',
                'event_name' => 'accuracy_feedback',
                'org_id' => 7,
                'attempt_id' => 'attempt-career',
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.stability_confidence',
                    'feedback' => 'accurate',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 7,
                'attempt_id' => 'attempt-career',
                'meta_json' => json_encode([
                    'sectionKey' => 'career.work_experiments',
                    'actionKey' => 'work_experiment.theme.name_decision_rule',
                    'interaction' => 'click',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(4),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 7,
                'attempt_id' => 'attempt-career',
                'meta_json' => json_encode([
                    'ctaKey' => 'career_bridge',
                    'continueTarget' => 'career_recommendation',
                    'interaction' => 'click_cta',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(3),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('shares')->insert([
            [
                'id' => (string) Str::uuid(),
                'attempt_id' => 'attempt-clarify',
                'anon_id' => 'anon-clarify',
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.3',
                'content_package_version' => 'v0.3',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'attempt_id' => 'attempt-career',
                'anon_id' => 'anon-career',
                'scale_code' => 'MBTI',
                'scale_version' => 'v0.3',
                'content_package_version' => 'v0.3',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $service = app(MbtiUserStateOrchestrationService::class);
        $clarify = $service->overlayEffective($personalization, 7, 'attempt-clarify', true);
        $career = $service->overlayEffective($personalization, 7, 'attempt-career', true);

        $this->assertSame(true, data_get($clarify, 'user_state.has_feedback'));
        $this->assertSame(true, data_get($career, 'user_state.has_feedback'));
        $this->assertSame(true, data_get($clarify, 'user_state.has_share'));
        $this->assertSame(true, data_get($career, 'user_state.has_share'));
        $this->assertSame('negative', data_get($clarify, 'user_state.feedback_sentiment'));
        $this->assertSame('positive', data_get($career, 'user_state.feedback_sentiment'));
        $this->assertSame('traits.close_call_axes', data_get($clarify, 'user_state.last_deep_read_section'));
        $this->assertSame('career_move', data_get($career, 'user_state.current_intent_cluster'));
        $this->assertSame('traits.close_call_axes', data_get($clarify, 'orchestration.primary_focus_key'));
        $this->assertSame('career.work_experiments', data_get($career, 'orchestration.primary_focus_key'));
        $this->assertSame(
            ['workspace_lite', 'career_bridge', 'share_result'],
            data_get($clarify, 'orchestration.cta_priority_keys')
        );
        $this->assertSame(
            ['career_bridge', 'workspace_lite', 'share_result'],
            data_get($career, 'orchestration.cta_priority_keys')
        );
        $this->assertSame('read-action', data_get($clarify, 'reading_focus_key'));
        $this->assertSame('read-career', data_get($career, 'reading_focus_key'));
        $this->assertSame('work_experiment.theme.name_decision_rule', data_get($career, 'action_focus_key'));
        $this->assertNotSame(
            data_get($clarify, 'ordered_recommendation_keys'),
            data_get($career, 'ordered_recommendation_keys')
        );
        $this->assertSame(
            'work_experiment.theme.name_decision_rule',
            data_get($clarify, 'action_focus_key')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(): array
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
                    'id' => 'read-relationship',
                    'type' => 'article',
                    'title' => '关系边界阅读',
                    'priority' => 15,
                    'tags' => ['relationships', 'communication'],
                    'url' => 'https://example.com/read-relationship',
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
        ];
    }
}
