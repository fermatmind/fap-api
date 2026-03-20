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
                'meta_json' => json_encode(['sectionKey' => 'growth.stability_confidence'], JSON_UNESCAPED_UNICODE),
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
                    'sectionKey' => 'growth.next_actions',
                    'actionKey' => 'weekly_action.theme.name_decision_rule',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(3),
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
            ],
            data_get($effective, 'user_state')
        );
        $this->assertSame('growth.stability_confidence', data_get($effective, 'orchestration.primary_focus_key'));
        $this->assertSame(
            ['career_bridge', 'workspace_lite', 'share_result'],
            data_get($effective, 'orchestration.cta_priority_keys')
        );
        $this->assertSame(
            ['career.work_experiments', 'relationships.try_this_week'],
            data_get($effective, 'orchestration.secondary_focus_keys')
        );
        $this->assertSame('growth.stability_confidence', data_get($effective, 'continuity.carryover_focus_key'));
        $this->assertSame('resume_action_loop', data_get($effective, 'continuity.carryover_reason'));
        $this->assertSame(
            ['growth.stability_confidence', 'career.work_experiments', 'relationships.try_this_week'],
            data_get($effective, 'continuity.recommended_resume_keys')
        );
        $this->assertSame(
            ['stability', 'work', 'decision'],
            data_get($effective, 'continuity.carryover_scene_keys')
        );
        $this->assertContains('watchout.stability.context_sensitive', data_get($effective, 'continuity.carryover_action_keys', []));
        $this->assertContains('work_experiment.theme.name_decision_rule', data_get($effective, 'continuity.carryover_action_keys', []));
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
