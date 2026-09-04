<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Platform12\Platform12ReadOnlyRuntimeGate;
use App\Services\SeoCouncil\Policy\CouncilAdmissionGateway;
use App\Services\SeoCouncil\Policy\CouncilAdmissionRequestFactory;
use App\Services\SeoCouncil\Routing\DeterministicMissionRouter;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform12A05ReadOnlyRuntimeGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('seo_council.read_only_runtime_test_enabled', true);
        config()->set('seo_council.read_only_runtime_state', 'ACTIVE_READ_ONLY');
        config()->set('seo_council.read_only_runtime_expected_version_vector', []);
        config()->set('seo_council.mission_persistence_enabled', false);
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'testing');

        parent::tearDown();
    }

    public function test_l0_diagnosis_and_l1_candidate_are_admitted_only_to_structured_read_only_output(): void
    {
        $l0 = app(LocalSkillMissionAdapter::class)->submit($this->request('L0', 'l0'));
        $l1 = app(LocalSkillMissionAdapter::class)->submit($this->request('L1', 'l1'));

        foreach ([$l0, $l1] as $receipt) {
            $admissionStep = collect($receipt['steps'])->firstWhere('step_type', '11c_admission');
            $this->assertSame('PASS', $admissionStep['status']);
            $this->assertFalse($receipt['execution_allowed']);
            $this->assertFalse($receipt['output_boundary']['execution_allowed']);
            $this->assertFalse($receipt['output_boundary']['write_allowed']);
            $this->assertTrue($receipt['output_boundary']['artifact_only']);
            foreach (['business_writes', 'cms_writes', 'url_truth_writes', 'search_submissions'] as $field) {
                $this->assertSame(0, $receipt['negative_guarantees'][$field], $field);
            }
        }
        $this->assertSame('structured_diagnosis', $l0['output_boundary']['kind']);
        $this->assertSame('structured_candidate', $l1['output_boundary']['kind']);
    }

    public function test_hold_state_and_version_drift_fail_closed_before_runtime_admission(): void
    {
        config()->set('seo_council.read_only_runtime_state', 'HOLD');
        $hold = $this->admission($this->request('L0', 'hold'));
        $this->assertSame('HOLD', $hold['decision']);
        $this->assertSame(['READ_ONLY_RUNTIME_HOLD'], $hold['reason_codes']);

        config()->set('seo_council.read_only_runtime_state', 'ACTIVE_READ_ONLY');
        $current = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector'];
        $current['role'] = str_repeat('0', 64);
        config()->set('seo_council.read_only_runtime_expected_version_vector', $current);
        $snapshot = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot();
        $drift = $this->admission($this->request('L0', 'drift'));

        $this->assertSame('HOLD', $snapshot['read_only_runtime_state']);
        $this->assertSame('CAPABILITY_VERSION_DRIFT', $snapshot['read_only_runtime_reason']);
        $this->assertSame(['role'], $snapshot['changed_dimensions']);
        $this->assertFalse($snapshot['read_only_runtime_enabled']);
        $this->assertSame('HOLD', $drift['decision']);
        $this->assertSame(['CAPABILITY_VERSION_DRIFT'], $drift['reason_codes']);
    }

    public function test_private_safety_and_authority_denials_keep_priority_over_read_only_gate(): void
    {
        $privateRequest = $this->policyRequest($this->request('L0', 'private'));
        $privateRequest['request_metadata']['source_label'] = 'owner@example.com';
        $private = app(CouncilAdmissionGateway::class)->admission('local_skill', $privateRequest);
        $this->assertSame('DENY', $private['decision']);
        $this->assertSame(['PRIVATE_DATA_DENIED'], $private['reason_codes']);

        foreach (['L2', 'L3', 'L4'] as $autonomy) {
            $expanded = $this->policyRequest($this->request('L0', strtolower($autonomy)));
            $expanded['autonomy'] = $autonomy;
            $decision = app(CouncilAdmissionGateway::class)->admission('local_skill', $expanded);
            $this->assertSame('DENY', $decision['decision'], $autonomy);
        }

        foreach (['tool_scope', 'egress_scope'] as $field) {
            $expanded = $this->policyRequest($this->request('L0', $field));
            $expanded[$field] = ['expanded'];
            $decision = app(CouncilAdmissionGateway::class)->admission('local_skill', $expanded);
            $this->assertSame('DENY', $decision['decision'], $field);
        }

        $requestedRole = $this->request('L0', 'role');
        $requestedRole['requested_role'] = 'seo.cms_writer';
        $routed = app(DeterministicMissionRouter::class)->route($this->missionData($requestedRole));
        $this->assertSame('REQUESTED_ROLE_EXPANSION_HOLD', $routed['status']);
        $this->assertSame([], $routed['roles']);
    }

    public function test_production_snapshot_stays_disabled_even_with_test_controls_set_active(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $snapshot = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot();
        $decision = $this->admission($this->request('L0', 'production'));

        $this->assertSame('DEPLOYED_DISABLED', $snapshot['orchestrator_state']);
        $this->assertSame('DETERMINISTIC_ROUTE_HOLD_ONLY', $snapshot['runtime_mode']);
        $this->assertSame('HOLD', $snapshot['read_only_runtime_state']);
        $this->assertSame('PRODUCTION_DISABLED', $snapshot['read_only_runtime_reason']);
        $this->assertFalse($snapshot['read_only_runtime_enabled']);
        $this->assertFalse($snapshot['execution_allowed']);
        $this->assertFalse($snapshot['write_allowed']);
        $this->assertNotSame('ALLOW', $decision['decision']);
    }

    public function test_lifecycle_allows_only_offline_shadow_active_read_only_degraded_hold_path(): void
    {
        $gate = app(Platform12ReadOnlyRuntimeGate::class);

        foreach ([
            ['OFFLINE_EVAL', 'SHADOW'],
            ['SHADOW', 'ACTIVE_READ_ONLY'],
            ['ACTIVE_READ_ONLY', 'DEGRADED'],
            ['DEGRADED', 'HOLD'],
            ['HOLD', 'OFFLINE_EVAL'],
        ] as [$from, $to]) {
            $transition = $gate->transition($from, $to);
            $this->assertSame('ACCEPTED', $transition['status']);
            $this->assertSame($to, $transition['state']);
            $this->assertFalse($transition['execution_allowed']);
            $this->assertFalse($transition['write_allowed']);
        }

        foreach ([['OFFLINE_EVAL', 'ACTIVE_READ_ONLY'], ['HOLD', 'ACTIVE_READ_ONLY']] as [$from, $to]) {
            $transition = $gate->transition($from, $to);
            $this->assertSame('HOLD', $transition['status']);
            $this->assertSame('ILLEGAL_LIFECYCLE_TRANSITION', $transition['reason']);
        }
    }

    public function test_mission_contract_accepts_l1_but_rejects_l2_and_above(): void
    {
        $validator = app(CouncilContractValidator::class);
        $this->assertSame('L1', $validator->missionRequest($this->request('L1', 'contract'))['autonomy']);

        foreach (['L2', 'L3', 'L4'] as $autonomy) {
            $input = $this->request('L0', 'contract-'.strtolower($autonomy));
            $input['autonomy'] = $autonomy;
            try {
                $validator->missionRequest($input);
                $this->fail($autonomy.' was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('MISSION_SCOPE_EXPANSION_DENIED', $exception->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function admission(array $input): array
    {
        return app(CouncilAdmissionGateway::class)->admission('local_skill', $this->policyRequest($input));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function policyRequest(array $input): array
    {
        return app(CouncilAdmissionRequestFactory::class)->make($this->missionData($input));
    }

    /** @param array<string, mixed> $input */
    private function missionData(array $input): MissionRequestData
    {
        return MissionRequestData::fromInput(
            $input,
            'local_skill',
            app(CouncilContractValidator::class),
            app(SeoRegistryHasher::class),
        );
    }

    /** @return array<string, mixed> */
    private function request(string $autonomy, string $suffix): array
    {
        return [
            'mission_id' => 'mission:readonly:'.$suffix,
            'idempotency_key' => 'idempotency:readonly:'.$suffix,
            'mission_type' => 'bounded_review',
            'family' => 'tests',
            'locale' => 'zh-CN',
            'review_domain' => 'technical',
            'requested_role' => null,
            'evidence_bundle_refs' => [[
                'bundle_id' => 'bundle:readonly:'.$suffix,
                'bundle_version' => 1,
                'bundle_hash' => hash('sha256', 'readonly-'.$suffix),
                'evidence_type' => 'runtime_health',
                'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ]],
            'autonomy' => $autonomy,
            'budget' => [
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'execution_seconds' => 0,
                'retry_count' => 0,
                'context_bytes' => 0,
                'cost_amount' => 0,
                'currency' => 'USD',
            ],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ];
    }
}
