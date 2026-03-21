<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Team;

use App\Models\Assessment;
use App\Services\Team\TeamDynamicsSynthesisService;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class TeamDynamicsSynthesisServiceTest extends TestCase
{
    public function test_builds_mbti_team_dynamics_for_mixed_team(): void
    {
        $assessment = new Assessment([
            'org_id' => 101,
            'scale_code' => 'MBTI',
            'title' => 'Team MBTI',
        ]);

        $results = new Collection([
            (object) ['type_code' => 'INTJ-A', 'result_json' => []],
            (object) ['type_code' => 'ENFP-T', 'result_json' => []],
            (object) ['type_code' => 'ISTJ-A', 'result_json' => []],
        ]);

        $authority = app(TeamDynamicsSynthesisService::class)->buildForAssessment($assessment, $results, 3);

        $this->assertIsArray($authority);
        $this->assertSame('team_dynamics.v1', $authority['version'] ?? null);
        $this->assertSame(3, $authority['team_member_count'] ?? null);
        $this->assertSame(3, $authority['analyzed_member_count'] ?? null);
        $this->assertContains('MBTI', $authority['supporting_scales'] ?? []);
        $this->assertContains('team.communication.energy_translation', $authority['communication_fit_keys'] ?? []);
        $this->assertContains('team.decision.logic_empathy_mix', $authority['decision_mix_keys'] ?? []);
        $this->assertContains('team.blindspot.execution_alignment', $authority['team_blindspot_keys'] ?? []);
        $this->assertNotSame('', trim((string) ($authority['fingerprint'] ?? '')));
    }

    public function test_builds_big_five_team_dynamics_for_trait_mix(): void
    {
        $assessment = new Assessment([
            'org_id' => 202,
            'scale_code' => 'BIG5_OCEAN',
            'title' => 'Team Big Five',
        ]);

        $results = new Collection([
            (object) ['scores_pct' => ['O' => 72, 'C' => 75, 'E' => 28, 'A' => 70, 'N' => 30]],
            (object) ['scores_pct' => ['O' => 66, 'C' => 61, 'E' => 78, 'A' => 39, 'N' => 74]],
            (object) ['scores_pct' => ['O' => 58, 'C' => 44, 'E' => 62, 'A' => 42, 'N' => 69]],
        ]);

        $authority = app(TeamDynamicsSynthesisService::class)->buildForAssessment($assessment, $results, 3);

        $this->assertIsArray($authority);
        $this->assertContains('BIG5_OCEAN', $authority['supporting_scales'] ?? []);
        $this->assertNotEmpty($authority['communication_fit_keys'] ?? []);
        $this->assertNotEmpty($authority['decision_mix_keys'] ?? []);
        $this->assertNotEmpty($authority['stress_pattern_keys'] ?? []);
        $this->assertNotEmpty($authority['team_action_prompt_keys'] ?? []);
    }

    public function test_returns_null_when_not_enough_members_are_analyzed(): void
    {
        $assessment = new Assessment([
            'org_id' => 303,
            'scale_code' => 'MBTI',
            'title' => 'Too Small',
        ]);

        $results = new Collection([
            (object) ['type_code' => 'INTJ-A', 'result_json' => []],
        ]);

        $authority = app(TeamDynamicsSynthesisService::class)->buildForAssessment($assessment, $results, 1);

        $this->assertNull($authority);
    }
}
