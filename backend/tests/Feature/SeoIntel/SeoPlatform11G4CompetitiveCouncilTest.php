<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Competitive\CompetitiveCloseoutBuilder;
use App\Services\SeoCouncil\Competitive\CompetitiveEvidenceBundleLoader;
use App\Services\SeoCouncil\Competitive\CompetitiveModeRegistry;
use App\Services\SeoCouncil\Competitive\CompetitiveRunner;
use App\Services\SeoCouncil\Competitive\CompetitiveRuntimeGate;
use App\Services\SeoCouncil\Competitive\ReadOnlyCompetitiveEvidenceBundleLoader;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Policy\CouncilAdmissionGateway;
use App\Services\SeoCouncil\Routing\GoldenRoutingEvaluator;
use Tests\TestCase;

final class SeoPlatform11G4CompetitiveCouncilTest extends TestCase
{
    public function test_binding_v3_and_routing_v2_require_both_competitive_evidence_types(): void
    {
        $binding = app(RoleCapabilityBindingRegistry::class);
        $mission = $binding->mission('bounded_review');
        $competitor = $binding->selectorVariant($mission, 'competitor');
        $metrics = app(GoldenRoutingEvaluator::class)->evaluate();

        $this->assertSame(RoleCapabilityBindingRegistry::V1_FILE_SHA256, hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v1.json')));
        $this->assertSame(RoleCapabilityBindingRegistry::V2_FILE_SHA256, hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v2.json')));
        $this->assertSame('3.0.0', $binding->reference()['version']);
        $this->assertSame(['gateway_competitor_public', 'search_measurement'], $competitor['required_evidence']);
        foreach (['weekly_opportunity', 'monthly_portfolio', 'breakthrough_sprint'] as $missionId) {
            $conditional = collect($binding->mission($missionId)['route_rule']['conditional_roles'])
                ->firstWhere('role_id', 'seo.expert.competitor_research');
            $this->assertSame(['gateway_competitor_public', 'search_measurement'], $conditional['evidence_types']);
        }
        $this->assertSame('2.0.0', $metrics['corpus_version']);
        $this->assertSame(['numerator' => 32, 'denominator' => 32, 'measurement_state' => 'observed'], $metrics['routing_precision']);
        $this->assertSame($metrics['routing_precision'], $metrics['routing_recall']);
    }

    public function test_mode_and_loader_are_offline_read_only_and_have_no_gateway_dependency(): void
    {
        $runtime = app(CompetitiveModeRegistry::class)->capabilitySnapshot();
        $loader = app(CompetitiveEvidenceBundleLoader::class);

        $this->assertSame('OFFLINE_EVAL_READY', $runtime['mode_state']);
        $this->assertInstanceOf(ReadOnlyCompetitiveEvidenceBundleLoader::class, $loader);
        foreach (['production_model_enabled', 'production_tool_enabled', 'production_execution_enabled', 'production_write_enabled', 'external_egress_enabled', 'allow_delegation', 'execution_allowed'] as $field) {
            $this->assertFalse($runtime[$field], $field);
        }
        $source = (string) file_get_contents((new \ReflectionClass($loader))->getFileName());
        $this->assertStringNotContainsString('ExternalContentGateway', $source);
        $this->assertStringNotContainsString('ExternalContentTransport', $source);
        $this->assertSame([], $loader->load($this->missionData('loader', true), str_repeat('a', 40), 'ci_candidate'));
    }

    public function test_ci_closeout_is_offline_eval_ready_and_never_claims_closed(): void
    {
        $sha = str_repeat('a', 40);
        $builder = app(CompetitiveCloseoutBuilder::class);
        $receipt = $builder->build($sha);

        $this->assertTrue($builder->verify($receipt, $sha));
        $this->assertSame('OFFLINE_EVAL_READY', $receipt['SEO-PLATFORM-11G']);
        $this->assertFalse($receipt['ready_for_11H']);
        $this->assertFalse($receipt['11i_handoff_ready']);
        $this->assertSame(0, $receipt['dependency_ingestion']['external_reads']);
        $this->assertSame(0, $receipt['external_calls']);
        $this->assertSame(0, $receipt['production_permissions']);
        $this->assertFalse($receipt['execution_allowed']);
    }

    public function test_orchestrator_requires_dual_evidence_and_keeps_all_permissions_zero(): void
    {
        $runner = new class(app(SeoRegistryHasher::class)) implements CompetitiveRunner
        {
            public int $calls = 0;

            public function __construct(private readonly SeoRegistryHasher $hasher) {}

            public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array
            {
                $this->calls++;
                $output = [
                    'output_id' => $this->hasher->hash([$request->requestHash, $handoff['handoff_hash']]),
                    'handoff_hash' => $handoff['handoff_hash'],
                    'role_id' => $handoff['target_role_id'],
                    'status' => 'PASS',
                    'summary_code' => 'competitive_evidence_ready',
                    'execution_allowed' => false,
                    'model_calls' => 0,
                    'tool_calls' => 0,
                    'external_calls' => 0,
                    'write_count' => 0,
                ];
                $output['output_hash'] = $this->hasher->hash($output);

                return $output;
            }
        };
        $this->app->instance(CompetitiveRunner::class, $runner);
        $this->allowAdmission();

        $missing = app(LocalSkillMissionAdapter::class)->submit($this->mission('missing-search', false));
        $this->assertSame('EVIDENCE_HOLD', $missing['status']);
        $this->assertSame(0, $runner->calls);

        $offline = app(LocalSkillMissionAdapter::class)->submit($this->mission('offline', true));
        $this->assertSame('COMPETITIVE_HOLD', $offline['status']);
        $this->assertSame('competitive_mode_offline_eval_only', $offline['stop_reason']);
        $this->assertSame(0, $runner->calls);

        $this->app->instance(CompetitiveRuntimeGate::class, new class implements CompetitiveRuntimeGate
        {
            public function allows(array $capabilitySnapshot): bool
            {
                return true;
            }
        });
        $ready = app(LocalSkillMissionAdapter::class)->submit($this->mission('controlled', true));
        $this->assertSame('COMPETITIVE_READY', $ready['status']);
        $this->assertSame(1, $runner->calls);
        $this->assertFalse($ready['execution_allowed']);
        foreach (['model_calls', 'tool_calls', 'external_calls', 'agent_write_permissions', 'business_writes', 'cms_writes', 'url_truth_writes', 'search_submissions'] as $field) {
            $this->assertSame(0, $ready['negative_guarantees'][$field], $field);
        }
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
    private function mission(string $suffix, bool $includeMeasurement): array
    {
        $refs = [[
            'bundle_id' => 'bundle:11g:gateway:'.$suffix,
            'bundle_version' => 1,
            'bundle_hash' => hash('sha256', 'gateway:'.$suffix),
            'evidence_type' => 'gateway_competitor_public',
            'status' => 'READY',
            'authority_revision' => str_repeat('a', 64),
        ]];
        if ($includeMeasurement) {
            $refs[] = [
                'bundle_id' => 'bundle:11g:measurement:'.$suffix,
                'bundle_version' => 1,
                'bundle_hash' => hash('sha256', 'measurement:'.$suffix),
                'evidence_type' => 'search_measurement',
                'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ];
        }

        return [
            'mission_id' => 'mission:11g:'.$suffix,
            'idempotency_key' => 'idempotency:11g:'.$suffix,
            'mission_type' => 'bounded_review',
            'family' => 'tests',
            'locale' => 'en',
            'review_domain' => 'competitor',
            'requested_role' => null,
            'evidence_bundle_refs' => $refs,
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ];
    }

    private function missionData(string $suffix, bool $includeMeasurement): MissionRequestData
    {
        return MissionRequestData::fromInput(
            $this->mission($suffix, $includeMeasurement),
            'cli',
            app(\App\Services\SeoCouncil\Contracts\CouncilContractValidator::class),
            app(SeoRegistryHasher::class),
        );
    }
}
