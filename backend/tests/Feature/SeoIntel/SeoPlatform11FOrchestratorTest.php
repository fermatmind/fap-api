<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Measurement\MeasurementRunner;
use App\Services\SeoCouncil\Measurement\MeasurementRuntimeGate;
use App\Services\SeoCouncil\Policy\CouncilAdmissionGateway;
use App\Services\SeoCouncil\SeoCouncilOrchestrator;
use Tests\TestCase;

final class SeoPlatform11FOrchestratorTest extends TestCase
{
    public function test_binding_selects_exactly_one_measurement_mode_and_entrypoints_do_not_reference_modes(): void
    {
        $binding = app(RoleCapabilityBindingRegistry::class);
        $mission = $binding->mission('bounded_review');
        $this->assertSame(1, $mission['max_modes']);
        $this->assertFalse($mission['allow_delegation']);
        $this->assertSame(['seo.expert.search_analytics_measurement'], $binding->selectorVariant($mission, 'analytics')['eligible_roles']);
        $this->assertSame(['seo.expert.commercial_funnel_cro'], $binding->selectorVariant($mission, 'cro')['eligible_roles']);
        $this->assertCount(1, glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []);

        $flow = (new \ReflectionClass(SeoCouncilOrchestrator::class))->getConstant('FLOW');
        $this->assertContains('11c_admission', $flow);
        $this->assertContains('role_capability_binding', $flow);
        $this->assertContains('run_receipt', $flow);

        foreach (glob(app_path('Services/SeoCouncil/Entrypoints/*.php')) ?: [] as $entrypoint) {
            $source = (string) file_get_contents($entrypoint);
            $this->assertStringNotContainsString('SearchMeasurementMode', $source, $entrypoint);
            $this->assertStringNotContainsString('CommercialFunnelCROMode', $source, $entrypoint);
        }
    }

    public function test_runner_is_reachable_only_after_admission_binding_and_controlled_runtime_gate(): void
    {
        $hasher = app(SeoRegistryHasher::class);
        $runner = new class($hasher) implements MeasurementRunner
        {
            public int $calls = 0;

            public function __construct(private readonly SeoRegistryHasher $hasher) {}

            public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array
            {
                $this->calls++;
                $output = [
                    'output_id' => $this->hasher->hash([$request->requestHash, $handoff['handoff_hash']]),
                    'handoff_hash' => $handoff['handoff_hash'], 'role_id' => $handoff['target_role_id'],
                    'status' => 'PASS', 'summary_code' => 'measurement_ready', 'execution_allowed' => false,
                    'model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'write_count' => 0,
                ];
                $output['output_hash'] = $this->hasher->hash($output);

                return $output;
            }
        };
        $this->app->instance(MeasurementRunner::class, $runner);
        $this->allowAdmission();

        $disabled = app(LocalSkillMissionAdapter::class)->submit($this->measurementMission('runtime-disabled'));
        $this->assertSame(0, $runner->calls);
        $this->assertSame('MEASUREMENT_HOLD', $disabled['status']);
        $this->assertSame('measurement_mode_offline_eval_only', $disabled['stop_reason']);

        $this->app->instance(MeasurementRuntimeGate::class, new class implements MeasurementRuntimeGate
        {
            public function allows(array $capabilitySnapshot): bool
            {
                return true;
            }
        });
        $ready = app(LocalSkillMissionAdapter::class)->submit($this->measurementMission('controlled-runtime'));
        $this->assertSame(1, $runner->calls);
        $this->assertSame('MEASUREMENT_READY', $ready['status']);
        $this->assertSame('mode_output', $ready['route_plan'][1]['kind']);
        $this->assertFalse($ready['execution_allowed']);

        $expanded = $this->measurementMission('role-expansion');
        $expanded['requested_role'] = 'seo.expert.commercial_funnel_cro';
        try {
            app(LocalSkillMissionAdapter::class)->submit($expanded);
            $this->fail('Role expansion must be rejected before orchestration.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('REQUESTED_ROLE_EXPANSION_DENIED', $exception->getMessage());
        }
        $this->assertSame(1, $runner->calls);
    }

    public function test_policy_deny_and_forged_execution_allow_never_reach_runner(): void
    {
        $runner = new class implements MeasurementRunner
        {
            public int $calls = 0;

            public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array
            {
                $this->calls++;

                return [];
            }
        };
        $this->app->instance(MeasurementRunner::class, $runner);
        $this->app->instance(MeasurementRuntimeGate::class, new class implements MeasurementRuntimeGate
        {
            public function allows(array $capabilitySnapshot): bool
            {
                return true;
            }
        });
        $this->app->instance(CouncilAdmissionGateway::class, new class implements CouncilAdmissionGateway
        {
            public function admission(string $callerType, array $request): array
            {
                return ['decision' => 'DENY', 'reason_codes' => ['CONTROLLED_TEST_DENY'], 'execution_allowed' => false];
            }
        });
        $denied = app(LocalSkillMissionAdapter::class)->submit($this->measurementMission('policy-deny'));
        $this->assertSame('POLICY_HOLD', $denied['status']);
        $this->assertSame(0, $runner->calls);

        $this->app->instance(CouncilAdmissionGateway::class, new class implements CouncilAdmissionGateway
        {
            public function admission(string $callerType, array $request): array
            {
                return ['decision' => 'ALLOW', 'reason_codes' => [], 'execution_allowed' => true];
            }
        });
        $forged = app(LocalSkillMissionAdapter::class)->submit($this->measurementMission('forged-execution'));
        $this->assertSame('POLICY_HOLD', $forged['status']);
        $this->assertSame(0, $runner->calls);
    }

    private function allowAdmission(): void
    {
        $this->app->instance(CouncilAdmissionGateway::class, new class implements CouncilAdmissionGateway
        {
            public function admission(string $callerType, array $request): array
            {
                return ['decision' => 'ALLOW', 'reason_codes' => ['CONTROLLED_TEST_ALLOW'], 'execution_allowed' => false];
            }
        });
    }

    /** @return array<string, mixed> */
    private function measurementMission(string $suffix): array
    {
        return [
            'mission_id' => 'mission:11f:'.$suffix, 'idempotency_key' => 'idempotency:11f:'.$suffix,
            'mission_type' => 'bounded_review', 'family' => 'tests', 'locale' => 'en',
            'review_domain' => 'analytics', 'requested_role' => null,
            'evidence_bundle_refs' => [[
                'bundle_id' => 'bundle:11f:measurement', 'bundle_version' => 2,
                'bundle_hash' => hash('sha256', 'bundle:11f:measurement'),
                'evidence_type' => 'search_measurement', 'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ]],
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'resume_from' => null,
        ];
    }
}
