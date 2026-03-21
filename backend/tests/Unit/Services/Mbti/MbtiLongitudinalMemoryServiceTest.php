<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Models\Attempt;
use App\Services\Mbti\MbtiLongitudinalMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiLongitudinalMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_builds_memory_contract_and_rewrites_sections_from_history_signals(): void
    {
        $service = app(MbtiLongitudinalMemoryService::class);

        $anonId = 'mbti_memory_history_anon';
        $currentAttemptId = $this->createAttempt($anonId, now()->subDay());
        $previousAttemptId = $this->createAttempt($anonId, now()->subDays(8));

        DB::table('events')->insert([
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => $previousAttemptId,
                'event_code' => 'result_view',
                'event_name' => 'result_view',
                'anon_id' => $anonId,
                'scale_code' => 'MBTI',
                'meta_json' => null,
                'occurred_at' => now()->subDays(8),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => $previousAttemptId,
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'anon_id' => $anonId,
                'scale_code' => 'MBTI',
                'meta_json' => json_encode([
                    'sectionKey' => 'career.next_step',
                    'interaction' => 'dwell_2500ms',
                    'continueTarget' => 'career_recommendation',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => now()->subDays(8)->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => $previousAttemptId,
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'anon_id' => $anonId,
                'scale_code' => 'MBTI',
                'meta_json' => json_encode([
                    'sectionKey' => 'career.work_experiments',
                    'interaction' => 'click',
                    'recommendationKey' => 'read-career',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => now()->subDays(7)->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $personalization = $service->attach([
            'type_code' => 'INTJ-A',
            'identity' => 'A',
            'selection_fingerprint' => 'same-type-only',
            'section_selection_keys' => [
                'career.next_step' => 'career.next_step:seed.same_type_seed',
            ],
            'action_selection_keys' => [],
            'recommendation_selection_keys' => ['read-growth', 'read-explain'],
            'recommended_read_candidates' => [
                ['key' => 'read-growth', 'title' => 'Growth loop', 'priority' => 20, 'tags' => ['growth']],
                ['key' => 'read-career', 'title' => 'Career next step', 'priority' => 10, 'tags' => ['career', 'work']],
                ['key' => 'read-explain', 'title' => 'Type explainability', 'priority' => 15, 'tags' => ['mbti', 'type']],
            ],
            'ordered_recommendation_keys' => ['read-growth', 'read-explain', 'read-career'],
            'ordered_action_keys' => ['career_bridge.theme.clarify_decision_criteria', 'weekly_action.theme.protect_energy_lane'],
            'continuity' => [
                'recommended_resume_keys' => ['growth.next_actions'],
                'carryover_focus_key' => 'growth.next_actions',
            ],
            'user_state' => [
                'is_revisit' => true,
                'action_completion_tendency' => 'idle',
                'last_deep_read_section' => 'growth.next_actions',
            ],
            'sections' => [
                'career.next_step' => [
                    'selected_blocks' => ['career.next_step.identity.a'],
                    'blocks' => [
                        ['id' => 'career.next_step.identity.a', 'kind' => 'identity'],
                        ['id' => 'career.next_step.axis.tf', 'kind' => 'axis_strength'],
                        ['id' => 'career.next_step.boundary.jp', 'kind' => 'boundary'],
                    ],
                ],
                'career.work_experiments' => [
                    'selected_blocks' => ['career.work_experiments.identity.a'],
                    'action_key' => 'work_experiment.theme.protect_energy_lane',
                    'blocks' => [
                        ['id' => 'career.work_experiments.identity.a', 'kind' => 'identity'],
                        ['id' => 'career.work_experiments.axis.ei', 'kind' => 'axis_strength'],
                        ['id' => 'career.work_experiments.boundary.jp', 'kind' => 'boundary'],
                        ['id' => 'career.work_experiments.work', 'kind' => 'work_experiment'],
                    ],
                ],
            ],
        ], [
            'org_id' => 0,
            'anon_id' => $anonId,
            'attempt_id' => $currentAttemptId,
        ]);

        $this->assertSame('mbti.longitudinal_memory.v1', data_get($personalization, 'longitudinal_memory_v1.memory_contract_version'));
        $this->assertSame('resume_career_focus', data_get($personalization, 'longitudinal_memory_v1.memory_rewrite_reason'));
        $this->assertContains('career.next_step', data_get($personalization, 'longitudinal_memory_v1.section_history_keys', []));
        $this->assertContains('career', data_get($personalization, 'longitudinal_memory_v1.dominant_interest_keys', []));
        $this->assertContains('career.next_step', data_get($personalization, 'longitudinal_memory_v1.resume_bias_keys', []));
        $careerNextStepSelectedBlocks = (array) (($personalization['sections']['career.next_step']['selected_blocks'] ?? []));
        $this->assertContains('career.next_step.axis.tf', $careerNextStepSelectedBlocks);
        $this->assertContains('career.next_step.boundary.jp', $careerNextStepSelectedBlocks);
        $this->assertStringContainsString(
            ':memory.resume_career_focus',
            (string) (($personalization['section_selection_keys']['career.next_step'] ?? ''))
        );
        $this->assertContains('read-career', data_get($personalization, 'recommendation_selection_keys', []));
        $this->assertNotSame('same-type-only', data_get($personalization, 'selection_fingerprint'));
    }

    public function test_attach_skips_memory_when_continuity_exists_without_real_history_anchor(): void
    {
        $service = app(MbtiLongitudinalMemoryService::class);

        $anonId = 'mbti_memory_no_anchor_anon';
        $currentAttemptId = $this->createAttempt($anonId, now()->subDay());

        $personalization = $service->attach([
            'type_code' => 'INTJ-A',
            'identity' => 'A',
            'selection_fingerprint' => 'same-type-only',
            'ordered_recommendation_keys' => ['read-growth', 'read-explain', 'read-career'],
            'continuity' => [
                'carryover_focus_key' => 'growth.next_actions',
                'recommended_resume_keys' => ['growth.next_actions', 'career.next_step'],
                'carryover_action_keys' => ['weekly_action.theme.name_decision_rule'],
            ],
            'user_state' => [
                'is_revisit' => true,
                'last_deep_read_section' => 'career.next_step',
                'action_completion_tendency' => 'warming_up',
            ],
        ], [
            'org_id' => 0,
            'anon_id' => $anonId,
            'attempt_id' => $currentAttemptId,
        ]);

        $this->assertNull(data_get($personalization, 'longitudinal_memory_v1'));
        $this->assertSame('same-type-only', data_get($personalization, 'selection_fingerprint'));
    }

    public function test_attach_freezes_memory_window_to_the_current_attempt_timeline(): void
    {
        $service = app(MbtiLongitudinalMemoryService::class);

        $anonId = 'mbti_memory_cutoff_anon';
        $previousAttemptId = $this->createAttempt($anonId, now()->subDays(8));
        $currentAttemptId = $this->createAttempt($anonId, now()->subDays(5));
        $futureAttemptId = $this->createAttempt($anonId, now()->subDay());

        DB::table('events')->insert([
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => $previousAttemptId,
                'event_code' => 'result_view',
                'event_name' => 'result_view',
                'anon_id' => $anonId,
                'scale_code' => 'MBTI',
                'meta_json' => null,
                'occurred_at' => now()->subDays(8),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => $previousAttemptId,
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'anon_id' => $anonId,
                'scale_code' => 'MBTI',
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.next_actions',
                    'interaction' => 'dwell_2500ms',
                    'actionKey' => 'weekly_action.theme.name_decision_rule',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => now()->subDays(8)->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => $futureAttemptId,
                'event_code' => 'result_view',
                'event_name' => 'result_view',
                'anon_id' => $anonId,
                'scale_code' => 'MBTI',
                'meta_json' => null,
                'occurred_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'attempt_id' => $futureAttemptId,
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'anon_id' => $anonId,
                'scale_code' => 'MBTI',
                'meta_json' => json_encode([
                    'sectionKey' => 'career.next_step',
                    'interaction' => 'dwell_2500ms',
                    'continueTarget' => 'career_recommendation',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'occurred_at' => now()->subDay()->addMinutes(3),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $personalization = $service->attach([
            'type_code' => 'INTJ-A',
            'identity' => 'A',
            'selection_fingerprint' => 'same-type-only',
            'ordered_recommendation_keys' => ['read-growth', 'read-career', 'read-explain'],
            'ordered_action_keys' => ['weekly_action.theme.name_decision_rule', 'career_bridge.theme.clarify_decision_criteria'],
            'continuity' => [
                'recommended_resume_keys' => ['growth.next_actions'],
                'carryover_focus_key' => 'growth.next_actions',
            ],
            'user_state' => [
                'is_revisit' => true,
                'last_deep_read_section' => 'growth.next_actions',
                'action_completion_tendency' => 'warming_up',
            ],
            'sections' => [
                'growth.next_actions' => [
                    'selected_blocks' => ['growth.next_actions.identity.a'],
                    'action_key' => 'weekly_action.theme.name_decision_rule',
                    'blocks' => [
                        ['id' => 'growth.next_actions.identity.a', 'kind' => 'identity'],
                        ['id' => 'growth.next_actions.axis.ei', 'kind' => 'axis_strength'],
                        ['id' => 'growth.next_actions.boundary.tf', 'kind' => 'boundary'],
                        ['id' => 'growth.next_actions.next', 'kind' => 'next_action'],
                    ],
                ],
                'career.next_step' => [
                    'selected_blocks' => ['career.next_step.identity.a'],
                    'blocks' => [
                        ['id' => 'career.next_step.identity.a', 'kind' => 'identity'],
                        ['id' => 'career.next_step.axis.tf', 'kind' => 'axis_strength'],
                        ['id' => 'career.next_step.boundary.jp', 'kind' => 'boundary'],
                        ['id' => 'career.next_step.work', 'kind' => 'career_next_step'],
                    ],
                ],
            ],
        ], [
            'org_id' => 0,
            'anon_id' => $anonId,
            'attempt_id' => $currentAttemptId,
        ]);

        $this->assertSame('resume_growth_actions', data_get($personalization, 'longitudinal_memory_v1.memory_rewrite_reason'));
        $this->assertContains('growth.next_actions', data_get($personalization, 'longitudinal_memory_v1.section_history_keys', []));
        $this->assertNotContains('career.next_step', data_get($personalization, 'longitudinal_memory_v1.section_history_keys', []));
        $this->assertContains('growth', data_get($personalization, 'longitudinal_memory_v1.dominant_interest_keys', []));
        $this->assertNotContains('career', data_get($personalization, 'longitudinal_memory_v1.dominant_interest_keys', []));
    }

    private function createAttempt(string $anonId, \DateTimeInterface $submittedAt): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => $submittedAt,
            'submitted_at' => $submittedAt,
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
        ]);

        return $attemptId;
    }
}
