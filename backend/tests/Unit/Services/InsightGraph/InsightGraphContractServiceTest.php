<?php

declare(strict_types=1);

namespace Tests\Unit\Services\InsightGraph;

use App\Services\InsightGraph\InsightGraphContractService;
use Tests\TestCase;

final class InsightGraphContractServiceTest extends TestCase
{
    public function test_it_builds_a_public_share_safe_graph_and_embed_surface(): void
    {
        $service = new InsightGraphContractService;
        $payload = [
            'scale_code' => 'MBTI',
            'title' => 'Campaigner',
            'type_name' => 'Campaigner',
            'summary' => 'A public-safe summary.',
            'primary_cta_label' => 'Start MBTI test',
            'primary_cta_path' => '/en/tests/mbti-personality-test-16-personality-types/take',
            'controlled_narrative_v1' => [
                'narrative_summary' => 'Narrative layer summary.',
            ],
            'comparative_v1' => [
                'cohort_relative_position' => [
                    'label' => 'Above about 62% of the cohort',
                    'summary' => 'Above roughly 62% of the anonymized cohort.',
                ],
            ],
            'working_life_v1' => [
                'career_focus_key' => 'career.next_step',
                'supporting_scales' => ['BIG5_OCEAN'],
            ],
        ];
        $publicSurface = [
            'entry_surface' => 'mbti_share_landing',
            'public_summary_fingerprint' => 'share-fingerprint-123',
            'continue_reading_keys' => ['career.next_step', 'career.work_experiments'],
        ];

        $graph = $service->buildForShare($payload, $publicSurface);
        $embed = $service->buildEmbedSurface($graph, $publicSurface, $payload);

        $this->assertSame('insight.graph.v1', $graph['graph_contract_version']);
        $this->assertSame('result_summary', $graph['root_node']);
        $this->assertSame('public_share_safe', $graph['graph_scope']);
        $this->assertSame(['MBTI', 'BIG5_OCEAN'], $graph['supporting_scales']);
        $this->assertCount(5, $graph['nodes']);
        $this->assertSame('narrative', $graph['nodes'][1]['id']);
        $this->assertSame('embed.surface.v1', $embed['version']);
        $this->assertSame('mbti_share_embed_card', $embed['surface_key']);
        $this->assertSame('career.next_step', $embed['continue_target']);
        $this->assertSame(['result_summary', 'narrative', 'comparative', 'working_life', 'continue_reading'], $embed['allowed_node_ids']);
    }

    public function test_it_builds_a_tenant_protected_workspace_graph(): void
    {
        $service = new InsightGraphContractService;

        $graph = $service->buildForWorkspaceSummary([
            'team_focus_key' => 'team.communication.energy_translation',
            'supporting_scales' => ['MBTI', 'BIG5_OCEAN'],
            'communication_fit_keys' => ['team.communication.energy_translation'],
            'decision_mix_keys' => ['team.decision.logic_empathy_mix'],
            'stress_pattern_keys' => ['team.stress.stability_gap'],
        ], [
            'workspace_focus_key' => 'team.communication.energy_translation',
            'manager_action_keys' => ['team.action.sync_communication_cadence', 'check_member_progress'],
            'member_drill_in_keys' => ['completed_assignments', 'pending_assignments'],
            'supporting_scales' => ['MBTI', 'BIG5_OCEAN'],
        ], [
            'completed' => 2,
            'total' => 3,
        ]);

        $this->assertSame('insight.graph.v1', $graph['graph_contract_version']);
        $this->assertSame('tenant_protected', $graph['graph_scope']);
        $this->assertSame('result_summary', $graph['root_node']);
        $this->assertSame(['MBTI', 'BIG5_OCEAN'], $graph['supporting_scales']);
        $this->assertContains('team_dynamics', array_column($graph['nodes'], 'id'));
        $this->assertContains('workspace_surface', array_column($graph['nodes'], 'id'));
        $this->assertContains('member_progress', array_column($graph['nodes'], 'id'));
        $this->assertContains('continue_reading', array_column($graph['nodes'], 'id'));
    }
}
