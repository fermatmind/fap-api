<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiActionJourneyContractService;
use Tests\TestCase;

final class MbtiActionJourneyContractServiceTest extends TestCase
{
    public function test_attach_builds_action_journey_and_pulse_from_revisit_signals(): void
    {
        $service = app(MbtiActionJourneyContractService::class);

        $personalization = $service->attach([
            'action_focus_key' => 'work_experiment.theme.name_decision_rule',
            'action_priority_keys' => [
                'work_experiment.theme.name_decision_rule',
                'weekly_action.theme.name_decision_rule',
                'relationship_action.theme.name_decision_rule',
            ],
            'reading_focus_key' => 'read-action',
            'continuity' => [
                'carryover_reason' => 'resume_action_loop',
                'carryover_action_keys' => [
                    'weekly_action.theme.name_decision_rule',
                    'work_experiment.theme.name_decision_rule',
                ],
                'recommended_resume_keys' => [
                    'traits.close_call_axes',
                    'growth.weekly_experiments',
                ],
            ],
            'user_state' => [
                'is_revisit' => true,
                'has_action_engagement' => true,
                'feedback_sentiment' => 'none',
                'action_completion_tendency' => 'repeatable',
                'last_deep_read_section' => 'traits.close_call_axes',
                'current_intent_cluster' => 'action_activation',
            ],
            'working_life_v1' => [
                'career_focus_key' => 'career.work_experiments',
            ],
        ]);

        $this->assertSame('action_journey.v1', data_get($personalization, 'action_journey_v1.journey_contract_version'));
        $this->assertSame('action_journey.fingerprint.v1', data_get($personalization, 'action_journey_v1.journey_fingerprint_version'));
        $this->assertSame('result_revisit', data_get($personalization, 'action_journey_v1.journey_scope'));
        $this->assertSame('resume_action_loop', data_get($personalization, 'action_journey_v1.journey_state'));
        $this->assertSame('repeatable', data_get($personalization, 'action_journey_v1.progress_state'));
        $this->assertSame('resume_action_loop', data_get($personalization, 'action_journey_v1.revisit_reorder_reason'));
        $this->assertSame(
            ['weekly_action.theme.name_decision_rule'],
            data_get($personalization, 'action_journey_v1.completed_action_keys')
        );
        $this->assertContains(
            'work_experiment.theme.name_decision_rule',
            data_get($personalization, 'action_journey_v1.recommended_next_pulse_keys', [])
        );
        $this->assertSame('pulse_check.v1', data_get($personalization, 'pulse_check_v1.pulse_contract_version'));
        $this->assertSame('reinforce', data_get($personalization, 'pulse_check_v1.pulse_state'));
        $this->assertContains('pulse.repeat_winning_action', data_get($personalization, 'pulse_check_v1.pulse_prompt_keys', []));
    }

    public function test_attach_keeps_first_view_in_start_state_without_completed_actions(): void
    {
        $service = app(MbtiActionJourneyContractService::class);

        $personalization = $service->attach([
            'action_focus_key' => 'weekly_action.theme.name_decision_rule',
            'action_priority_keys' => [
                'weekly_action.theme.name_decision_rule',
                'watchout.stability.context_sensitive',
            ],
            'reading_focus_key' => 'read-action',
            'continuity' => [
                'carryover_reason' => 'unlock_to_continue_focus',
                'recommended_resume_keys' => ['growth.next_actions'],
            ],
            'user_state' => [
                'is_revisit' => false,
                'has_action_engagement' => false,
                'feedback_sentiment' => 'none',
                'action_completion_tendency' => 'idle',
                'last_deep_read_section' => '',
                'current_intent_cluster' => 'default',
            ],
        ]);

        $this->assertSame('first_view_activation', data_get($personalization, 'action_journey_v1.journey_state'));
        $this->assertSame('not_started', data_get($personalization, 'action_journey_v1.progress_state'));
        $this->assertSame([], data_get($personalization, 'action_journey_v1.completed_action_keys'));
        $this->assertSame('initial_action_activation', data_get($personalization, 'action_journey_v1.revisit_reorder_reason'));
        $this->assertSame('not_due', data_get($personalization, 'pulse_check_v1.pulse_state'));
    }
}
