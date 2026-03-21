<?php

declare(strict_types=1);

namespace Tests\Unit\Services\InsightGraph;

use App\Services\InsightGraph\RelationshipSyncContractService;
use Tests\TestCase;

final class RelationshipSyncContractServiceTest extends TestCase
{
    public function test_it_builds_a_pending_relationship_sync_contract(): void
    {
        $service = new RelationshipSyncContractService;

        $contract = $service->build(
            ['type_code' => 'INTJ-A'],
            [],
            ['title' => null, 'summary' => null, 'axes' => []],
            'pending',
            'zh-CN',
            '/zh/tests/mbti-personality-test-16-personality-types/take?share_id=share-1&compare_invite_id=invite-1'
        );

        $this->assertSame('relationship.sync.v1', $contract['version']);
        $this->assertSame('public_compare_invite_safe', $contract['dyadic_scope']);
        $this->assertSame('share_compare_invite_pending', $contract['subject_join_mode']);
        $this->assertSame(['dyadic_action.complete_compare_invite'], $contract['dyadic_action_prompt_keys']);
        $this->assertSame('dyadic_action.complete_compare_invite', data_get($contract, 'action_prompt.key'));
        $this->assertSame('/zh/tests/mbti-personality-test-16-personality-types/take?share_id=share-1&compare_invite_id=invite-1', data_get($contract, 'action_prompt.cta_path'));
    }

    public function test_it_builds_a_ready_relationship_sync_contract_and_graph(): void
    {
        $service = new RelationshipSyncContractService;

        $contract = $service->build(
            ['type_code' => 'INTJ-A'],
            ['type_code' => 'ENFP-T'],
            [
                'title' => 'Relationship sync summary',
                'summary' => 'A first dyadic summary.',
                'shared_count' => 2,
                'diverging_count' => 3,
                'axes' => [
                    ['code' => 'EI', 'label' => 'Energy', 'inviter_side' => 'I', 'invitee_side' => 'E'],
                    ['code' => 'SN', 'label' => 'Perception', 'inviter_side' => 'N', 'invitee_side' => 'S'],
                    ['code' => 'TF', 'label' => 'Decision', 'inviter_side' => 'T', 'invitee_side' => 'F'],
                    ['code' => 'JP', 'label' => 'Structure', 'inviter_side' => 'J', 'invitee_side' => 'P'],
                    ['code' => 'AT', 'label' => 'Stability', 'inviter_side' => 'A', 'invitee_side' => 'T'],
                ],
            ],
            'ready',
            'en',
            null
        );

        $graph = $service->buildGraph($contract);

        $this->assertSame('share_compare_invite_joined', $contract['subject_join_mode']);
        $this->assertSame(2, $contract['shared_count']);
        $this->assertSame(3, $contract['diverging_count']);
        $this->assertContains('friction.energy_mismatch', $contract['friction_keys']);
        $this->assertContains('complement.idea_grounding', $contract['complement_keys']);
        $this->assertContains('communication_bridge.energy_pacing', $contract['communication_bridge_keys']);
        $this->assertContains('decision_tension.logic_vs_empathy', $contract['decision_tension_keys']);
        $this->assertContains('stress_interplay.reassurance_gap', $contract['stress_interplay_keys']);
        $this->assertContains('dyadic_action.name_decision_rule', $contract['dyadic_action_prompt_keys']);
        $this->assertSame('dyadic.graph.v1', $graph['version']);
        $this->assertSame('public_compare_invite_safe', $graph['graph_scope']);
        $this->assertSame(['MBTI'], $graph['supporting_scales']);
        $this->assertSame('relationship_sync', $graph['root_node']);
        $this->assertNotEmpty($graph['nodes']);
        $this->assertNotEmpty($graph['edges']);
    }
}
