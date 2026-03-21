<?php

declare(strict_types=1);

namespace Tests\Unit\Services\InsightGraph;

use App\Models\MbtiCompareInvite;
use App\Services\InsightGraph\PrivateRelationshipContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class PrivateRelationshipContractServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_private_relationship_and_consent_contracts(): void
    {
        $service = new PrivateRelationshipContractService;

        $relationship = $service->buildPrivateRelationship(
            [
                'type_code' => 'ENFP-T',
                'mbti_public_projection_v1' => [
                    'summary_card' => ['title' => 'Campaigner'],
                ],
            ],
            [
                'type_code' => 'INFJ-A',
                'mbti_public_projection_v1' => [
                    'summary_card' => ['title' => 'Advocate'],
                ],
            ],
            [
                'shared_count' => 2,
                'diverging_count' => 2,
                'overview' => [
                    'title' => 'Private relationship sync',
                    'summary' => 'A protected relationship overview.',
                ],
                'friction_keys' => ['friction.energy_mismatch'],
                'complement_keys' => ['complement.heart_head_balance'],
                'communication_bridge_keys' => ['communication_bridge.energy_pacing'],
                'decision_tension_keys' => ['decision_tension.logic_vs_empathy'],
                'stress_interplay_keys' => ['stress_interplay.shared_recovery_rhythm'],
                'dyadic_action_prompt_keys' => ['dyadic_action.name_decision_rule'],
                'sections' => [
                    [
                        'key' => 'communication_bridge',
                        'title' => 'Communication bridge',
                        'summary' => 'Name the response pace.',
                        'bullets' => ['Say clearly whether you need to think first or speak first.'],
                    ],
                ],
                'action_prompt' => [
                    'key' => 'dyadic_action.name_decision_rule',
                    'title' => 'Name the decision rule first',
                    'summary' => 'Say what each person is optimizing for before debating the answer.',
                    'cta_label' => 'Open my MBTI reports',
                    'cta_path' => '/en/result/attempt-001',
                ],
            ],
            'inviter',
            'private_access_ready',
            'share_compare_invite_purchased',
            'en',
            '/en/result/attempt-001',
            'Open my MBTI reports'
        );

        $this->assertSame('private_relationship_protected', $relationship['relationship_scope']);
        $this->assertSame('private.relationship.v1', $relationship['relationship_contract_version']);
        $this->assertSame('private_access_ready', $relationship['access_state']);
        $this->assertSame('share_compare_invite_purchased', $relationship['subject_join_mode']);
        $this->assertSame('inviter', $relationship['participant_role']);
        $this->assertSame('Private relationship sync', data_get($relationship, 'overview.title'));
        $this->assertSame('communication_bridge', data_get($relationship, 'private_sync_sections.0.key'));
        $this->assertSame('dyadic_action.name_decision_rule', data_get($relationship, 'private_action_prompt.key'));
        $this->assertNotSame('', (string) ($relationship['relationship_fingerprint'] ?? ''));

        $invite = new MbtiCompareInvite;
        $invite->accepted_at = Carbon::parse('2026-03-21T08:00:00+08:00');
        $invite->completed_at = Carbon::parse('2026-03-21T08:05:00+08:00');
        $invite->purchased_at = Carbon::parse('2026-03-21T08:10:00+08:00');

        $consent = $service->buildDyadicConsent(
            $invite,
            'purchased',
            'private_access_ready',
            'share_compare_invite_purchased'
        );

        $this->assertSame('private_relationship_protected', $consent['consent_scope']);
        $this->assertSame('purchased', $consent['consent_state']);
        $this->assertSame('not_supported_yet', $consent['revocation_state']);
        $this->assertSame('not_enforced_yet', $consent['expiry_state']);
        $this->assertSame('dyadic.consent.v1', $consent['consent_artifact_version']);

        $graph = $service->buildProtectedGraph($relationship);

        $this->assertSame('private_relationship_protected', $graph['graph_scope']);
        $this->assertSame('dyadic.graph.v1', $graph['graph_contract_version']);
        $this->assertSame('private_relationship', $graph['root_node']);
        $this->assertSame('MBTI', data_get($graph, 'supporting_scales.0'));
        $this->assertNotEmpty($graph['nodes']);
    }
}
