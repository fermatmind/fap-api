<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\CliMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\ScheduledMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\SeoOperationsUiMissionAdapter;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\SeoCouncilOrchestrator;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisCloseoutBuilder;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisModeRegistry;
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
        $receipt = $builder->build($sha);

        $this->assertTrue($builder->verify($receipt, $sha));
        $this->assertSame('CLOSED', $receipt['SEO-PLATFORM-11E']);
        $this->assertTrue($receipt['ready_for_11F']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertFalse($builder->verify($receipt, str_repeat('f', 40)));
        $tampered = $receipt;
        $tampered['fixture_metrics']['false_negative'] = 1;
        $this->assertFalse($builder->verify($tampered, $sha));
        foreach (['production_sha', 'registry_hash', 'binding_hash', 'policy_hash', 'receipt_hash'] as $field) {
            $tampered = $receipt;
            $tampered[$field] = $field === 'production_sha' ? str_repeat('e', 40) : str_repeat('e', 64);
            $this->assertFalse($builder->verify($tampered, $sha), $field);
        }

        $tester = new CommandTester(Artisan::all()['seo:council-closeout']);
        $this->assertSame(0, $tester->execute(['--expected-sha' => $sha, '--json' => true]));
        $council = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('CLOSED', $council['SEO-PLATFORM-11E']);
        $this->assertTrue($council['ready_for_11F']);
        $this->assertSame($receipt['receipt_hash'], $council['technical_diagnosis']['receipt_hash']);
    }

    /** @return array<string, mixed> */
    private function technicalMission(): array
    {
        return [
            'mission_id' => 'mission:11e:technical', 'idempotency_key' => 'idempotency:11e:technical',
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
