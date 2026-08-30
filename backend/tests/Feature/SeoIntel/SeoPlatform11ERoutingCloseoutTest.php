<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\CliMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\ScheduledMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\SeoOperationsUiMissionAdapter;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Policy\CouncilAdmissionGateway;
use App\Services\SeoCouncil\SeoCouncilOrchestrator;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisActivityLedger;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisCloseoutBuilder;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisDependencyBindingSource;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisModeRegistry;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisRunner;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisRuntimeGate;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform11ERoutingCloseoutTest extends TestCase
{
    public function test_unique_orchestrator_registers_only_disabled_technical_mode_after_admission(): void
    {
        $binding = app(RoleCapabilityBindingRegistry::class);
        $mission = $binding->mission('bounded_review');
        $variant = $binding->selectorVariant($mission, 'technical');

        $this->assertSame(1, $mission['max_modes']);
        $this->assertFalse($mission['allow_delegation']);
        $this->assertSame(['seo.expert.technical_search_authority'], $variant['eligible_roles']);
        $this->assertCount(1, glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []);

        $runtime = app(TechnicalDiagnosisModeRegistry::class)->capabilitySnapshot();
        $this->assertSame('technical_search_diagnosis', $runtime['mode_id']);
        $this->assertFalse($runtime['production_execution_enabled']);

        $flow = (new \ReflectionClass(SeoCouncilOrchestrator::class))->getConstant('FLOW');
        $this->assertSame([
            'mission_request_validation', 'admission_request_binding', '11c_admission',
            'dependency_snapshot', 'runtime_capability_snapshot', 'role_capability_binding',
            'evidence_context_verification', 'technical_mode_selection',
        ], array_slice($flow, 1, 8));
    }

    public function test_all_five_entrypoints_submit_the_same_technical_mission_and_fail_closed_at_admission(): void
    {
        $input = $this->technicalMission();
        $receipts = [
            app(LocalSkillMissionAdapter::class)->submit($input),
            app(CliMissionAdapter::class)->submit($input),
            app(ScheduledMissionAdapter::class)->submit($input),
            app(ApiMissionAdapter::class)->submit($input),
            app(SeoOperationsUiMissionAdapter::class)->submit($input),
        ];

        $this->assertCount(1, array_unique(array_column($receipts, 'request_hash')));
        $this->assertCount(1, array_unique(array_map(static fn (array $receipt): string => $receipt['binding_ref']['hash'], $receipts)));
        $this->assertSame(['local_skill', 'cli', 'scheduler', 'api', 'seo_operations_ui'], array_column(array_column($receipts, 'caller_provenance'), 'caller_type'));
        foreach ($receipts as $receipt) {
            $this->assertSame('POLICY_HOLD', $receipt['status']);
            $this->assertSame('ROLE_CAPABILITY_BINDING_UNAVAILABLE', $receipt['stop_reason']);
            $this->assertSame([], $receipt['route_plan']);
            $this->assertSame('HOLD', $receipt['steps'][3]['status']);
            $this->assertSame('NOT_RUN', $receipt['steps'][4]['status']);
            $this->assertFalse($receipt['execution_allowed']);
        }
    }

    public function test_closeout_is_exact_sha_bound_tamper_evident_and_nested_in_council_receipt(): void
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], dirname(base_path()));
        $process->mustRun();
        $sha = trim($process->getOutput());
        $builder = app(TechnicalDiagnosisCloseoutBuilder::class);
        $receipt = $builder->build($sha, 'ci_candidate');

        $this->assertTrue($builder->verify($receipt, $sha, 'ci_candidate'));
        $this->assertSame('CANDIDATE_READY', $receipt['closeout_state']);
        $this->assertSame('HOLD', $receipt['SEO-PLATFORM-11E']);
        $this->assertFalse($receipt['ready_for_11F']);
        $this->assertSame('OFFLINE_FIXTURE', $receipt['dependency_mode']);
        $this->assertNull($receipt['observed_active_sha']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertFalse($builder->verify($receipt, str_repeat('f', 40)));
        $tampered = $receipt;
        $tampered['fixture_metrics']['false_negative'] = 1;
        $this->assertFalse($builder->verify($tampered, $sha));
        foreach (['candidate_sha', 'registry_hash', 'binding_hash', 'policy_hash', 'receipt_hash'] as $field) {
            $tampered = $receipt;
            $tampered[$field] = $field === 'candidate_sha' ? str_repeat('e', 40) : str_repeat('e', 64);
            $this->assertFalse($builder->verify($tampered, $sha), $field);
        }

        $tester = new CommandTester(Artisan::all()['seo:council-closeout']);
        $this->assertSame(0, $tester->execute(['--expected-sha' => $sha, '--closeout-environment' => 'ci_candidate', '--json' => true]));
        $council = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('CLOSED', $council['SEO-PLATFORM-11D']);
        $this->assertSame('HOLD', $council['SEO-PLATFORM-11E']);
        $this->assertFalse($council['ready_for_11F']);
        $this->assertTrue($builder->verify($council['technical_diagnosis'], $sha, 'ci_candidate'));
    }

    public function test_production_closeout_requires_real_runtime_binding_and_exact_active_sha(): void
    {
        $sha = str_repeat('a', 40);
        $this->app->instance(TechnicalDiagnosisDependencyBindingSource::class, new class implements TechnicalDiagnosisDependencyBindingSource
        {
            public function technicalDiagnosisBinding(string $releaseSha): array
            {
                return [
                    'url_truth_revision' => 'url-truth-set-v1:'.str_repeat('1', 32),
                    'url_truth_projection_hash' => str_repeat('2', 64),
                    'runtime_evidence_revision' => 'seo-platform-07-technical-health.v1:'.str_repeat('3', 32),
                    'runtime_evidence_hash' => str_repeat('4', 64),
                    'authority_revision' => 'authority-set-v1:'.str_repeat('5', 32),
                    'deployment_revision' => $releaseSha,
                    'source_capability_state' => 'available',
                ];
            }
        });
        $builder = app(TechnicalDiagnosisCloseoutBuilder::class);
        $staging = $builder->build($sha, 'staging_runtime');
        $production = $builder->build($sha, 'production_runtime');

        $this->assertSame('STAGING_READY', $staging['closeout_state']);
        $this->assertSame('HOLD', $staging['SEO-PLATFORM-11E']);
        $this->assertSame($sha, $staging['observed_active_sha']);
        $this->assertSame('CLOSED', $production['closeout_state']);
        $this->assertSame('CLOSED', $production['SEO-PLATFORM-11E']);
        $this->assertTrue($production['ready_for_11F']);
        $this->assertSame($sha, $production['observed_active_sha']);
        foreach (['url_truth_revision', 'runtime_evidence_revision', 'authority_revision'] as $field) {
            $this->assertStringNotContainsString('fixture', $production[$field]);
            $this->assertStringNotContainsString('offline-eval', $production[$field]);
        }
    }

    public function test_orchestrator_calls_runner_once_only_after_allow_ready_and_runtime_gate(): void
    {
        $hasher = app(SeoRegistryHasher::class);
        $runner = new class($hasher) implements TechnicalDiagnosisRunner
        {
            public int $calls = 0;

            public function __construct(private readonly SeoRegistryHasher $hasher) {}

            public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array
            {
                $this->calls++;
                $output = [
                    'output_id' => $this->hasher->hash([$request->requestHash, $handoff['handoff_hash']]),
                    'handoff_hash' => $handoff['handoff_hash'], 'role_id' => $handoff['target_role_id'],
                    'status' => 'PASS', 'summary_code' => 'technical_diagnosis_ready',
                    'execution_allowed' => false, 'model_calls' => 0, 'tool_calls' => 0,
                    'external_calls' => 0, 'write_count' => 0,
                ];
                $output['output_hash'] = $this->hasher->hash($output);

                return $output;
            }
        };
        $this->app->instance(TechnicalDiagnosisRunner::class, $runner);

        app(LocalSkillMissionAdapter::class)->submit($this->technicalMission('policy-hold'));
        $this->assertSame(0, $runner->calls);

        $this->app->instance(CouncilAdmissionGateway::class, new class implements CouncilAdmissionGateway
        {
            public function admission(string $callerType, array $request): array
            {
                return ['decision' => 'DENY', 'reason_codes' => ['CONTROLLED_TEST_DENY'], 'execution_allowed' => false];
            }
        });
        app(LocalSkillMissionAdapter::class)->submit($this->technicalMission('policy-deny'));
        $this->assertSame(0, $runner->calls);

        $this->app->instance(CouncilAdmissionGateway::class, new class implements CouncilAdmissionGateway
        {
            public function admission(string $callerType, array $request): array
            {
                return ['decision' => 'ALLOW', 'reason_codes' => ['CONTROLLED_TEST_ALLOW'], 'execution_allowed' => false];
            }
        });
        $this->app->instance(TechnicalDiagnosisRuntimeGate::class, new class implements TechnicalDiagnosisRuntimeGate
        {
            public function allows(array $capabilitySnapshot): bool
            {
                return true;
            }
        });
        $receipt = app(LocalSkillMissionAdapter::class)->submit($this->technicalMission('controlled-allow'));

        $this->assertSame(1, $runner->calls);
        $this->assertSame('TECHNICAL_DIAGNOSIS_READY', $receipt['status']);
        $this->assertSame('mode_output', $receipt['route_plan'][1]['kind']);
        $this->assertSame('PASS', $receipt['steps'][13]['status']);
    }

    public function test_activity_mutations_hold_closeout_instead_of_using_literal_zeroes(): void
    {
        $sha = str_repeat('a', 40);
        foreach ([
            'model_calls', 'tool_calls', 'external_calls', 'business_writes',
            'active_manifest_count', 'trusted_key_count', 'l4_allow_count',
        ] as $activity) {
            $ledger = new TechnicalDiagnosisActivityLedger(app(PolicyGatewayRegistry::class));
            $ledger->record($activity);
            $this->app->instance(TechnicalDiagnosisActivityLedger::class, $ledger);
            $receipt = app(TechnicalDiagnosisCloseoutBuilder::class)->build($sha, 'ci_candidate');

            $this->assertSame(1, $receipt[$activity], $activity);
            $this->assertSame('DEPENDENCY_HOLD', $receipt['closeout_state'], $activity);
            $this->assertSame('HOLD', $receipt['SEO-PLATFORM-11E'], $activity);
        }
    }

    public function test_disabled_runtime_and_invalid_mode_output_fail_closed(): void
    {
        $runner = new class implements TechnicalDiagnosisRunner
        {
            public int $calls = 0;

            public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array
            {
                $this->calls++;

                return [
                    'output_id' => str_repeat('a', 64), 'handoff_hash' => $handoff['handoff_hash'],
                    'role_id' => $handoff['target_role_id'], 'status' => 'PASS', 'summary_code' => 'forged_output',
                    'execution_allowed' => false, 'model_calls' => 0, 'tool_calls' => 0,
                    'external_calls' => 0, 'write_count' => 0, 'output_hash' => str_repeat('b', 64),
                ];
            }
        };
        $this->app->instance(TechnicalDiagnosisRunner::class, $runner);
        $this->app->instance(CouncilAdmissionGateway::class, new class implements CouncilAdmissionGateway
        {
            public function admission(string $callerType, array $request): array
            {
                return ['decision' => 'ALLOW', 'reason_codes' => ['CONTROLLED_TEST_ALLOW'], 'execution_allowed' => false];
            }
        });

        $disabled = app(LocalSkillMissionAdapter::class)->submit($this->technicalMission('runtime-disabled'));
        $this->assertSame(0, $runner->calls);
        $this->assertSame('technical_diagnosis_production_disabled', $disabled['stop_reason']);

        $this->app->instance(TechnicalDiagnosisRuntimeGate::class, new class implements TechnicalDiagnosisRuntimeGate
        {
            public function allows(array $capabilitySnapshot): bool
            {
                return true;
            }
        });
        $invalid = app(LocalSkillMissionAdapter::class)->submit($this->technicalMission('invalid-output'));
        $this->assertSame(1, $runner->calls);
        $this->assertSame('EVIDENCE_HOLD', $invalid['status']);
        $this->assertSame('technical_mode_output_contract_hold', $invalid['stop_reason']);
    }

    /** @return array<string, mixed> */
    private function technicalMission(string $suffix = 'technical'): array
    {
        return [
            'mission_id' => 'mission:11e:'.$suffix, 'idempotency_key' => 'idempotency:11e:'.$suffix,
            'mission_type' => 'bounded_review', 'family' => 'tests', 'locale' => 'en',
            'review_domain' => 'technical', 'requested_role' => null,
            'evidence_bundle_refs' => [[
                'bundle_id' => 'bundle:11e:technical', 'bundle_version' => 1,
                'bundle_hash' => hash('sha256', 'bundle:11e:technical'),
                'evidence_type' => 'runtime_health', 'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ]],
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'resume_from' => null,
        ];
    }
}
