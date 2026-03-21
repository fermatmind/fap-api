<?php

declare(strict_types=1);

namespace Tests\Unit\Services\InsightGraph;

use App\Services\InsightGraph\PrivateRelationshipJourneyContractService;
use Tests\TestCase;

final class PrivateRelationshipJourneyContractServiceTest extends TestCase
{
    public function test_it_builds_private_relationship_journey_and_pulse_contracts(): void
    {
        $service = app(PrivateRelationshipJourneyContractService::class);

        $journey = $service->buildJourney(
            [
                'relationship_fingerprint' => 'private-relationship-fingerprint',
                'private_action_prompt' => [
                    'key' => 'dyadic_action.name_decision_rule',
                ],
                'friction_keys' => ['friction.energy_mismatch'],
                'communication_bridge_keys' => ['communication_bridge.energy_pacing'],
            ],
            [
                'access_state' => 'private_access_ready',
                'consent_fingerprint' => 'consent-fingerprint-001',
                'consent_refresh_required' => false,
                'private_relationship_access_version' => 'private.relationship.access.v1',
            ],
            [
                'completed_dyadic_action_keys' => ['dyadic_action.name_decision_rule'],
            ]
        );

        $this->assertSame('private_relationship_journey.v1', $journey['journey_contract_version']);
        $this->assertSame('private_relationship_journey.fp.v1', $journey['journey_fingerprint_version']);
        $this->assertSame('private_relationship_revisit', $journey['journey_scope']);
        $this->assertSame('practice_revisit', $journey['journey_state']);
        $this->assertSame('warming_up', $journey['progress_state']);
        $this->assertSame('dyadic_action.name_decision_rule', $journey['dyadic_action_focus_key']);
        $this->assertContains('dyadic_action.name_decision_rule', $journey['completed_dyadic_action_keys']);
        $this->assertContains('dyadic_pulse.review_tension_signal', $journey['recommended_next_dyadic_pulse_keys']);
        $this->assertSame('resume_dyadic_practice', $journey['revisit_reorder_reason']);
        $this->assertNotSame('', (string) ($journey['journey_fingerprint'] ?? ''));

        $pulse = $service->buildPulseCheck(
            [
                'private_action_prompt' => [
                    'key' => 'dyadic_action.name_decision_rule',
                ],
                'friction_keys' => ['friction.energy_mismatch'],
            ],
            [
                'access_state' => 'private_access_ready',
                'consent_state' => 'purchased',
                'consent_refresh_required' => false,
            ],
            $journey,
            []
        );

        $this->assertSame('dyadic_pulse_check.v1', $pulse['pulse_contract_version']);
        $this->assertSame('repeat_shared_practice', $pulse['pulse_state']);
        $this->assertContains('dyadic_pulse.repeat_shared_action', $pulse['pulse_prompt_keys']);
        $this->assertSame('dyadic_action.name_decision_rule', $pulse['next_pulse_target']);
    }

    public function test_it_restricts_journey_and_removes_pulse_when_access_is_revoked(): void
    {
        $service = app(PrivateRelationshipJourneyContractService::class);

        $journey = $service->buildJourney(
            [
                'relationship_fingerprint' => 'private-relationship-fingerprint',
                'private_action_prompt' => [
                    'key' => 'dyadic_action.name_decision_rule',
                ],
            ],
            [
                'access_state' => 'private_access_revoked',
                'consent_fingerprint' => 'consent-fingerprint-001',
                'consent_refresh_required' => false,
                'private_relationship_access_version' => 'private.relationship.access.v1',
            ],
            [
                'completed_dyadic_action_keys' => ['dyadic_action.name_decision_rule'],
            ]
        );

        $this->assertSame('access_revoked', $journey['journey_state']);
        $this->assertSame('restricted', $journey['progress_state']);
        $this->assertSame([], $journey['completed_dyadic_action_keys']);
        $this->assertSame([], $journey['recommended_next_dyadic_pulse_keys']);

        $pulse = $service->buildPulseCheck(
            [],
            [
                'access_state' => 'private_access_revoked',
                'consent_refresh_required' => false,
            ],
            $journey,
            []
        );

        $this->assertSame([], $pulse);
    }
}
