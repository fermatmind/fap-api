<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiWorkingLifeConsolidationService;
use Tests\TestCase;

final class MbtiWorkingLifeConsolidationServiceTest extends TestCase
{
    public function test_it_builds_working_life_authority_from_existing_career_and_cross_assessment_signals(): void
    {
        $service = app(MbtiWorkingLifeConsolidationService::class);

        $authority = $service->buildAuthority([
            'role_fit_keys' => ['role_fit.role.NF'],
            'collaboration_fit_keys' => ['collaboration_fit.primary.EI.E.clear'],
            'work_env_preference_keys' => ['work_env.preference.high_collaboration'],
            'career_next_step_keys' => ['career_next_step.theme.clarify_decision_criteria'],
            'ordered_recommendation_keys' => ['read-career', 'read-action', 'read-explain'],
            'recommended_read_candidates' => [
                ['key' => 'read-career', 'tags' => ['career', 'work']],
                ['key' => 'read-action', 'tags' => ['action', 'growth']],
                ['key' => 'read-explain', 'tags' => ['mbti']],
            ],
            'orchestration' => [
                'primary_focus_key' => 'career.work_experiments',
                'secondary_focus_keys' => ['career.next_step'],
                'cta_priority_keys' => ['career_bridge', 'workspace_lite', 'share_result'],
            ],
            'user_state' => [
                'has_unlock' => true,
                'current_intent_cluster' => 'career_move',
            ],
            'supporting_scales' => ['BIG5_OCEAN'],
            'big5_influence_keys' => ['big5.band.c.low'],
            'synthesis_keys' => ['big5.career_next_step.low.reduce_activation_friction'],
            'cross_assessment_v1' => [
                'section_enhancements' => [
                    'career.next_step' => [
                        'synthesis_key' => 'big5.career_next_step.low.reduce_activation_friction',
                    ],
                ],
            ],
        ]);

        $this->assertSame('mbti.working_life.v1', $authority['version']);
        $this->assertSame('career.work_experiments', $authority['career_focus_key']);
        $this->assertSame(
            ['career.work_experiments', 'career.next_step', 'career.work_environment', 'career.collaboration_fit'],
            $authority['career_journey_keys']
        );
        $this->assertSame(
            ['career.work_experiments', 'career.next_step', 'career_bridge', 'workspace_lite'],
            $authority['career_action_priority_keys']
        );
        $this->assertSame(['read-career', 'read-action'], $authority['career_reading_keys']);
        $this->assertSame(['BIG5_OCEAN'], $authority['supporting_scales']);
        $this->assertSame(
            ['big5.career_next_step.low.reduce_activation_friction'],
            $authority['synthesis_keys']
        );
    }
}
