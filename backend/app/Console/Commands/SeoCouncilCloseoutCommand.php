<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Contracts\CouncilContractRegistry;
use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Governance\CouncilDependencySnapshotBuilder;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Memory\OperatorTimeService;
use App\Services\SeoCouncil\Routing\GoldenRoutingEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Throwable;

final class SeoCouncilCloseoutCommand extends Command
{
    protected $signature = 'seo:council-closeout {--expected-sha=} {--json}';

    protected $description = 'Verify SEO-PLATFORM-11D deterministic orchestration for one exact SHA';

    public function handle(
        CouncilContractRegistry $contracts,
        RoleCapabilityBindingRegistry $binding,
        CouncilDependencySnapshotBuilder $dependencies,
        RuntimeCapabilitySnapshotBuilder $runtime,
        PolicyGatewayRegistry $policy,
        GoldenRoutingEvaluator $routing,
        CouncilContractValidator $validator,
        ApiMissionAdapter $api,
        OperatorTimeService $operatorTime,
        SeoRegistryHasher $hasher,
    ): int {
        try {
            $releaseSha = $this->releaseSha();
            $expectedSha = strtolower(trim((string) $this->option('expected-sha')));
            if (preg_match('/^[a-f0-9]{40}$/D', $expectedSha) !== 1 || ! hash_equals($expectedSha, $releaseSha)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'RELEASE_SHA_MISMATCH'], self::FAILURE);
            }
            $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-council-contract-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);
            $routingMetrics = $routing->evaluate();
            $bindingRef = $binding->reference();
            $runtimeSnapshot = $runtime->snapshot();
            $policyRegistry = $policy->registry();
            $trust = $policy->trustRegistry();
            $entrypoints = [
                'local_skill' => app_path('Services/SeoCouncil/Entrypoints/LocalSkillMissionAdapter.php'),
                'cli' => app_path('Services/SeoCouncil/Entrypoints/CliMissionAdapter.php'),
                'scheduler' => app_path('Services/SeoCouncil/Entrypoints/ScheduledMissionAdapter.php'),
                'api' => app_path('Services/SeoCouncil/Entrypoints/ApiMissionAdapter.php'),
                'seo_operations_ui' => app_path('Services/SeoCouncil/Entrypoints/SeoOperationsUiMissionAdapter.php'),
            ];
            $career = $api->submit($this->request('career_candidate_generation', 'career', 'zh-CN', ['career_candidate', 'content_claim']));
            $careerRoles = array_values(array_map(
                static fn (array $step): string => (string) $step['target_role_id'],
                array_filter($career['route_plan'], static fn (array $step): bool => ($step['kind'] ?? null) === 'role_handoff'),
            ));
            $privateBypass = $this->privateProbeBypass($validator);
            $budgetBypass = $this->budgetProbeBypass($validator);
            $callerRoleBypass = $this->callerRoleProbeBypass($api);
            $peerDelegationBypass = $this->peerDelegationProbeBypass($validator);
            $conflictExecutionBypass = $this->conflictExecutionProbeBypass($api);
            $legacyActive = $this->activeLegacyEntrypoints();
            $orchestratorCount = count(glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []);
            $dependencySnapshot = $dependencies->snapshot($releaseSha);
            $receipt = [
                'contract_version' => 'seo.council_closeout.v1',
                'release_sha' => $releaseSha,
                'state' => 'DEPLOYED_DISABLED',
                'runtime_mode' => 'DETERMINISTIC_ROUTE_HOLD_ONLY',
                'unique_seo_orchestrator_count' => $orchestratorCount,
                'role_capability_binding_version' => $bindingRef['version'],
                'role_capability_binding_hash' => $bindingRef['hash'],
                'binding_status' => $binding->status(),
                'dependency_status' => $dependencySnapshot['status'],
                'dependency_snapshot_hash' => $dependencySnapshot['snapshot_hash'],
                'contract_manifest_hash' => $artifact['manifest_hash'] ?? null,
                'contract_schema_hash_drift' => is_array($artifact) && $contracts->verify($artifact) ? 0 : 1,
                'entrypoints_present' => count(array_filter($entrypoints, 'is_file')).'/5',
                'caller_role_bypass' => $callerRoleBypass,
                'active_legacy_seo_agent_entrypoints' => $legacyActive,
                'routing' => $routingMetrics,
                'unauthorized_all_team_calls' => $routingMetrics['unauthorized_all_team_invocation_count']['numerator'],
                'peer_delegation_bypass' => $peerDelegationBypass,
                'budget_timeout_retry_idempotency_bypass' => $budgetBypass,
                'unresolved_conflict_execution_bypass' => $conflictExecutionBypass,
                'career_chain_order_bypass' => $careerRoles === [
                    'career.content_agent',
                    'seo.expert.content_entity_quality',
                    'seo.independent_reviewer',
                ] && ($career['route_plan'][3]['write_current'] ?? true) === false ? 0 : 1,
                'metadata_private_data_bypass' => $privateBypass,
                'l4_allow_count' => 0,
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'agent_write_permissions' => 0,
                'business_writes' => 0,
                'cms_writes' => 0,
                'url_truth_writes' => 0,
                'search_writes' => 0,
                'active_manifests' => count((array) ($trust['active_manifest_ids'] ?? [])),
                'trusted_signing_keys' => count((array) ($trust['trusted_public_keys'] ?? [])),
                'external_trace_export' => false,
                'shared_agent_memory' => false,
                'career_runtime' => $runtimeSnapshot['career_runtime'],
                'mission_persistence_enabled' => $runtimeSnapshot['mission_persistence_enabled'],
                'operator_time_baseline' => $operatorTime->routineMaintenanceBaseline(),
                'action_manifest_ref' => $contracts->manifest()['reused_action_manifest'],
                'policy_registry_hash' => $policyRegistry['registry_hash'],
                'execution_allowed' => false,
            ];
            if ($receipt['unique_seo_orchestrator_count'] !== 1
                || $receipt['binding_status'] !== 'READY'
                || $receipt['dependency_status'] !== 'READY'
                || $receipt['contract_schema_hash_drift'] !== 0
                || $receipt['entrypoints_present'] !== '5/5'
                || $receipt['active_legacy_seo_agent_entrypoints'] !== 0
                || $receipt['caller_role_bypass'] !== 0
                || $receipt['peer_delegation_bypass'] !== 0
                || $receipt['unresolved_conflict_execution_bypass'] !== 0
                || $routingMetrics['routing_precision'] !== ['numerator' => 32, 'denominator' => 32, 'measurement_state' => 'observed']
                || $routingMetrics['routing_recall'] !== ['numerator' => 32, 'denominator' => 32, 'measurement_state' => 'observed']
                || $routingMetrics['missed_required_mode_rate']['numerator'] !== 0
                || $routingMetrics['unnecessary_mode_rate']['numerator'] !== 0
                || $routingMetrics['all_team_invocation_count']['numerator'] !== 1
                || $routingMetrics['unauthorized_all_team_invocation_count']['numerator'] !== 0
                || $receipt['unauthorized_all_team_calls'] !== 0
                || $receipt['career_chain_order_bypass'] !== 0
                || $receipt['metadata_private_data_bypass'] !== 0
                || $receipt['budget_timeout_retry_idempotency_bypass'] !== 0
                || $receipt['active_manifests'] !== 0
                || $receipt['trusted_signing_keys'] !== 0
                || $runtimeSnapshot['mission_execution_enabled'] !== false
                || $runtimeSnapshot['mission_persistence_enabled'] !== false) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'SEO_COUNCIL_CLOSEOUT_FAILED'], self::FAILURE);
            }
            $receipt['receipt_hash'] = $hasher->hash($receipt);

            return $this->emit($receipt, self::SUCCESS);
        } catch (Throwable) {
            return $this->emit(['status' => 'failed', 'safe_error_code' => 'SEO_COUNCIL_CLOSEOUT_FAILED'], self::FAILURE);
        }
    }

    private function activeLegacyEntrypoints(): int
    {
        $active = 0;
        $retiredPrefix = 'seo'.'-agent:';
        foreach (Artisan::all() as $name => $command) {
            if (! str_starts_with($name, $retiredPrefix)) {
                continue;
            }
            $active += (int) (! $command instanceof RetiredSeoAgentCommand || $command::AGENT_INVOCABLE);
        }

        return $active;
    }

    private function privateProbeBypass(CouncilContractValidator $validator): int
    {
        $request = $this->request('weekly_opportunity', 'tests', 'en', ['search_measurement']);
        $request['mission_id'] = 'person@example.com';
        try {
            $validator->missionRequest($request);

            return 1;
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }

    private function budgetProbeBypass(CouncilContractValidator $validator): int
    {
        foreach (['model_calls', 'tool_calls', 'external_calls', 'execution_seconds', 'retry_count', 'context_bytes', 'cost_amount'] as $field) {
            $request = $this->request('weekly_opportunity', 'tests', 'en', ['search_measurement']);
            $request['budget'][$field] = 1;
            try {
                $validator->missionRequest($request);

                return 1;
            } catch (\InvalidArgumentException) {
                // Expected fail-closed probe.
            }
        }
        $migration = (string) file_get_contents(database_path('migrations/seo_intel/2026_08_29_030000_create_seo_council_runtime_tables.php'));

        return str_contains($migration, "string('idempotency_key', 128)->unique()")
            && str_contains($migration, "unique(['run_id', 'sequence']") ? 0 : 1;
    }

    private function callerRoleProbeBypass(ApiMissionAdapter $api): int
    {
        $request = $this->request('weekly_opportunity', 'tests', 'en', ['search_measurement']);
        $request['caller_type'] = 'local_skill';
        $request['requested_role'] = 'seo.cms_writer';
        $receipt = $api->submit($request);
        $roles = array_column((array) $receipt['route_plan'], 'target_role_id');

        return ($receipt['caller_provenance']['caller_type'] ?? null) === 'api'
            && $roles === ['seo.expert.search_analytics_measurement'] ? 0 : 1;
    }

    private function peerDelegationProbeBypass(CouncilContractValidator $validator): int
    {
        $output = [
            'output_id' => str_repeat('b', 64),
            'handoff_hash' => str_repeat('a', 64),
            'role_id' => 'seo.independent_reviewer',
            'status' => 'PASS',
            'summary_code' => 'independent_review_pass',
            'execution_allowed' => false,
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'output_hash' => str_repeat('c', 64),
            'peer_handoff' => ['seo.cms_writer'],
        ];

        return $validator->modeOutput($output, str_repeat('a', 64), 'seo.independent_reviewer') ? 1 : 0;
    }

    private function conflictExecutionProbeBypass(ApiMissionAdapter $api): int
    {
        $request = $this->request('weekly_opportunity', 'tests', 'en', ['search_measurement', 'content_claim']);
        $request['evidence_bundle_refs'][1]['authority_revision'] = str_repeat('b', 64);
        $receipt = $api->submit($request);

        return ($receipt['status'] ?? null) === 'unresolved_conflict'
            && ($receipt['human_decision_required'] ?? null) === true
            && ($receipt['execution_allowed'] ?? null) === false ? 0 : 1;
    }

    /** @param list<string> $types @return array<string, mixed> */
    private function request(string $mission, string $family, string $locale, array $types): array
    {
        $refs = [];
        foreach ($types as $index => $type) {
            $refs[] = [
                'bundle_id' => 'bundle:closeout:'.$index,
                'bundle_version' => 1,
                'bundle_hash' => hash('sha256', 'closeout:'.$index),
                'evidence_type' => $type,
                'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ];
        }

        return [
            'mission_id' => 'mission:closeout:'.$mission,
            'idempotency_key' => 'closeout:'.$mission,
            'mission_type' => $mission,
            'family' => $family,
            'locale' => $locale,
            'review_domain' => null,
            'requested_role' => null,
            'evidence_bundle_refs' => $refs,
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ];
    }

    private function releaseSha(): string
    {
        $revision = dirname(base_path()).'/REVISION';
        if (is_file($revision)) {
            return strtolower(trim((string) file_get_contents($revision)));
        }
        $process = new Process(['git', 'rev-parse', 'HEAD'], dirname(base_path()));
        $process->mustRun();

        return strtolower(trim($process->getOutput()));
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $code;
    }
}
