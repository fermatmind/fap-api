<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Assessments;

use App\Services\Assessments\WorkspaceSurfaceContractService;
use Tests\TestCase;

final class WorkspaceSurfaceContractServiceTest extends TestCase
{
    public function test_builds_workspace_surface_v1_from_team_dynamics(): void
    {
        $service = app(WorkspaceSurfaceContractService::class);

        $surface = $service->build([
            'version' => 'team_dynamics.v1',
            'team_focus_key' => 'decision_balance',
            'team_member_count' => 4,
            'analyzed_member_count' => 3,
            'supporting_scales' => ['MBTI', 'BIG5_OCEAN'],
            'team_action_prompt_keys' => ['review_team_action_prompts', 'align_decision_norms'],
            'workspace_scope' => 'tenant_protected',
        ], [
            'completed' => 3,
            'total' => 4,
        ]);

        $this->assertSame('workspace.surface.v1', $surface['version'] ?? null);
        $this->assertSame('decision_balance', $surface['workspace_focus_key'] ?? null);
        $this->assertSame(['review_team_action_prompts', 'align_decision_norms'], $surface['manager_action_keys'] ?? null);
        $this->assertSame(['completed_assignments', 'pending_assignments'], $surface['member_drill_in_keys'] ?? null);
        $this->assertSame('tenant_protected', $surface['workspace_scope'] ?? null);
        $this->assertNotEmpty($surface['workspace_surface_fingerprint'] ?? null);
    }
}
