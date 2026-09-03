<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayCallerGuard;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Contracts\CouncilContractRegistry;
use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\CliMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\ScheduledMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\SeoOperationsUiMissionAdapter;
use App\Services\SeoCouncil\Governance\CouncilDependencySnapshotBuilder;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Measurement\MeasurementCloseoutBuilder;
use App\Services\SeoCouncil\Memory\OperatorTimeService;
use App\Services\SeoCouncil\Platform11\Platform11HCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11ICloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11JCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11KCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11LCloseoutBuilder;
use App\Services\SeoCouncil\Policy\CouncilAdmissionRequestFactory;
use App\Services\SeoCouncil\Routing\DeterministicMissionRouter;
use App\Services\SeoCouncil\Routing\GoldenRoutingEvaluator;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisCloseoutBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

final class SeoCouncilCloseoutCommand extends Command
{
    protected $signature = 'seo:council-closeout {--expected-sha=} {--closeout-environment=ci_candidate} {--json}';

    protected $description = 'Verify SEO-PLATFORM-11D deterministic orchestration for one exact SHA';

    public function handle(
        CouncilContractRegistry $contracts,
        RoleCapabilityBindingRegistry $binding,
        CouncilDependencySnapshotBuilder $dependencies,
        RuntimeCapabilitySnapshotBuilder $runtime,
        PolicyGatewayRegistry $policy,
        PolicyGatewayCallerGuard $policyGateway,
        CouncilAdmissionRequestFactory $admissionRequests,
        SeoRoleCapabilityRegistry $roles,
        GoldenRoutingEvaluator $routing,
        DeterministicMissionRouter $router,
        CouncilContractValidator $validator,
        LocalSkillMissionAdapter $localSkill,
        CliMissionAdapter $cli,
        ScheduledMissionAdapter $scheduler,
        ApiMissionAdapter $api,
        SeoOperationsUiMissionAdapter $ui,
        OperatorTimeService $operatorTime,
        TechnicalDiagnosisCloseoutBuilder $technicalDiagnosis,
        MeasurementCloseoutBuilder $measurement,
        Platform11HCloseoutBuilder $platform11H,
        Platform11ICloseoutBuilder $platform11I,
        Platform11JCloseoutBuilder $platform11J,
        Platform11KCloseoutBuilder $platform11K,
        Platform11LCloseoutBuilder $platform11L,
        SeoRegistryHasher $hasher,
    ): int {
        try {
            $sourceSha = $this->releaseSha();
            $expectedSha = strtolower(trim((string) $this->option('expected-sha')));
            if (preg_match('/^[a-f0-9]{40}$/D', $expectedSha) !== 1 || ! hash_equals($expectedSha, $sourceSha)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'RELEASE_SHA_MISMATCH'], self::FAILURE);
            }
            $closeoutEnvironment = (string) $this->option('closeout-environment');

            $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-council-contract-manifest.v3.json')), true, 512, JSON_THROW_ON_ERROR);
            $bindingReport = $binding->validationReport();
            $bindingRef = $binding->reference();
            $registry = $roles->registry();
            $runtimeSnapshot = $runtime->snapshot();
            $trust = $policy->trustRegistry();
            $policyRegistry = $policy->registry();
            $dependency = $dependencies->snapshot($sourceSha);
            $routingMetrics = $routing->evaluate();

            $input = $this->request('weekly_opportunity', 'tests', 'en', ['search_measurement']);
            $runtimeReadOnly = $closeoutEnvironment !== 'ci_candidate';
            $entrypointReceipts = $runtimeReadOnly ? [] : [
                $localSkill->submit($input), $cli->submit($input), $scheduler->submit($input),
                $api->submit($input), $ui->submit($input),
            ];
            $entrypointProbeTotal = 5;
            $entrypointPassed = $runtimeReadOnly
                ? (int) ($policy->dependencyStatus() === 'READY') * count(array_filter([
                    LocalSkillMissionAdapter::class, CliMissionAdapter::class, ScheduledMissionAdapter::class,
                    ApiMissionAdapter::class, SeoOperationsUiMissionAdapter::class,
                ], 'class_exists'))
                : count(array_filter($entrypointReceipts, static fn (array $receipt): bool => ($receipt['status'] ?? null) === 'POLICY_HOLD'
                    && ($receipt['stop_reason'] ?? null) === 'ROLE_CAPABILITY_BINDING_UNAVAILABLE'
                    && ($receipt['route_plan'] ?? null) === []
                    && ($receipt['steps'][3]['status'] ?? null) === 'HOLD'
                    && ($receipt['steps'][4]['status'] ?? null) === 'NOT_RUN'
                    && ($receipt['execution_allowed'] ?? null) === false
                ));
            $holdBypass = $entrypointProbeTotal - $entrypointPassed;
            $policyReasonOverwrite = count(array_filter($entrypointReceipts, static fn (array $receipt): bool => ($receipt['stop_reason'] ?? null) !== 'ROLE_CAPABILITY_BINDING_UNAVAILABLE'
            ));
            $unauthorizedRouteExecution = count(array_filter($entrypointReceipts, static fn (array $receipt): bool => ($receipt['route_plan'] ?? []) !== [] || ($receipt['execution_allowed'] ?? null) !== false
            ));

            $denyDecision = $policyGateway->admission('unregistered_caller', []);
            $denyBypass = (int) (($denyDecision['decision'] ?? null) !== 'DENY' || ($denyDecision['execution_allowed'] ?? null) !== false);
            $l4Mission = MissionRequestData::fromInput($input, 'api', $validator, $hasher);
            $l4Request = $admissionRequests->make($l4Mission);
            $l4Request['autonomy'] = 'L4';
            $l4Decision = $policyGateway->admission('api', $l4Request);
            $l4AllowCount = (int) (($l4Decision['decision'] ?? null) === 'ALLOW');
            $requestedRoleExpansionBypass = $this->requestedRoleExpansionBypass($binding, $validator, $hasher);
            $csrf = $this->csrfProbe();
            $careerBypass = $this->careerChainBypass($router, $validator, $hasher, $bindingRef);
            $activity = $this->activityFromReceipts($entrypointReceipts);
            $productionPermissions = $this->productionPermissionCount($registry, $runtimeSnapshot);
            $technicalReceipt = $technicalDiagnosis->build($sourceSha, $closeoutEnvironment);
            $measurementReceipt = $measurement->build(
                $sourceSha,
                $closeoutEnvironment,
                $closeoutEnvironment === 'ci_candidate' ? $this->parentSha() : $sourceSha,
            );
            $platform11Receipt = $platform11H->build($sourceSha, $closeoutEnvironment);
            $platform11EditorialReceipt = $platform11I->build($sourceSha, $closeoutEnvironment, $platform11Receipt);
            $platform11RuntimeQaReceipt = $platform11J->build($sourceSha, $closeoutEnvironment, $platform11EditorialReceipt);
            $platform11IndependentReviewReceipt = $platform11K->build($sourceSha, $closeoutEnvironment, $platform11RuntimeQaReceipt);
            $platform11LifecycleReceipt = $platform11L->build(
                $sourceSha,
                $closeoutEnvironment,
                $platform11Receipt,
                $platform11EditorialReceipt,
                $platform11RuntimeQaReceipt,
                $platform11IndependentReviewReceipt,
            );

            $receipt = [
                'contract_version' => 'seo.council_closeout.v2',
                'source_sha' => $sourceSha,
                'release_sha' => $sourceSha,
                'state' => $runtimeSnapshot['orchestrator_state'],
                'runtime_mode' => $runtimeSnapshot['runtime_mode'],
                'registry_version' => $registry['registry_version'],
                'registry_hash' => $registry['registry_hash'],
                'binding_version' => $bindingRef['version'],
                'binding_hash' => $bindingRef['hash'],
                'role_capability_binding_version' => $bindingRef['version'],
                'role_capability_binding_hash' => $bindingRef['hash'],
                ...array_diff_key($bindingReport, ['valid' => true]),
                'binding_hash_drift_count' => $bindingReport['binding_hash_drift_count'],
                'unbound_mission_count' => $bindingReport['unbound_mission_count'],
                'unknown_role_count' => $bindingReport['unknown_role_count'],
                'unknown_capability_count' => $bindingReport['unknown_capability_count'],
                'unknown_tool_count' => $bindingReport['unknown_tool_count'],
                'contract_manifest_hash' => $artifact['manifest_hash'] ?? null,
                'contract_schema_hash_drift_count' => $contracts->verify($artifact) ? 0 : 1,
                'contract_schema_hash_drift' => $contracts->verify($artifact) ? 0 : 1,
                'dependency_status' => $dependency['status'],
                'unique_orchestrator_probe_total' => count(glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []),
                'unique_seo_orchestrator_count' => count(glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []),
                'binding_status' => $binding->status(),
                'admission_deny_probe_total' => 1,
                'admission_deny_bypass' => $denyBypass,
                'admission_hold_probe_total' => $entrypointProbeTotal,
                'admission_hold_bypass' => $holdBypass,
                'requested_role_expansion_bypass' => $requestedRoleExpansionBypass,
                'five_entrypoint_probe_total' => $entrypointProbeTotal,
                'five_entrypoint_probe_passed' => $entrypointPassed,
                'entrypoints_present' => $entrypointPassed.'/5',
                'csrf_negative_probe_total' => $csrf['total'],
                'csrf_bypass' => $csrf['bypass'],
                'career_chain_probe_total' => 1,
                'career_chain_bypass' => $careerBypass,
                'policy_reason_overwrite_count' => $policyReasonOverwrite,
                'unauthorized_route_execution_count' => $unauthorizedRouteExecution,
                'caller_role_bypass' => $requestedRoleExpansionBypass,
                'unauthorized_all_team_calls' => $routingMetrics['unauthorized_all_team_invocation_count']['numerator'],
                'peer_delegation_bypass' => $this->peerDelegationBypass($validator),
                'budget_timeout_retry_idempotency_bypass' => $this->budgetExpansionBypass($validator),
                'unresolved_conflict_execution_bypass' => $unauthorizedRouteExecution,
                'career_chain_order_bypass' => $careerBypass,
                'metadata_private_data_bypass' => $this->privateDataBypass($validator),
                ...$activity,
                'agent_write_permissions' => $productionPermissions,
                'active_manifest_count' => count((array) ($trust['active_manifest_ids'] ?? [])),
                'trusted_key_count' => count((array) ($trust['trusted_public_keys'] ?? [])),
                'active_manifests' => count((array) ($trust['active_manifest_ids'] ?? [])),
                'trusted_signing_keys' => count((array) ($trust['trusted_public_keys'] ?? [])),
                'l4_allow_count' => $l4AllowCount,
                'l4_probe_reason_codes' => $l4Decision['reason_codes'] ?? [],
                'production_permissions' => $productionPermissions,
                'active_legacy_seo_agent_entrypoints' => $this->activeLegacyEntrypoints(),
                'routing' => $routingMetrics,
                'career_runtime' => $runtimeSnapshot['career_runtime'],
                'mission_persistence_enabled' => $runtimeSnapshot['mission_persistence_enabled'],
                'operator_time_baseline' => $operatorTime->routineMaintenanceBaseline(),
                'action_manifest_ref' => $contracts->manifest()['reused_action_manifest'],
                'policy_registry_hash' => $policyRegistry['registry_hash'],
                'execution_allowed' => false,
                'external_trace_export' => (bool) ($registry['architecture_decisions']['external_trace_export'] ?? true),
                'shared_agent_memory' => (bool) ($registry['architecture_decisions']['shared_agent_memory'] ?? true),
                'technical_diagnosis' => $technicalReceipt,
                'measurement_review' => $measurementReceipt,
                'platform11' => $platform11Receipt,
                'platform11_editorial' => $platform11EditorialReceipt,
                'platform11_runtime_qa' => $platform11RuntimeQaReceipt,
                'platform11_independent_review' => $platform11IndependentReviewReceipt,
                'platform11_lifecycle' => $platform11LifecycleReceipt,
            ];
            $receiptProjection = $this->receiptProjectionProbe($receipt);
            $receipt['receipt_projection_probe_total'] = $receiptProjection['total'];
            $receipt['receipt_projection_bypass'] = $receiptProjection['bypass'];

            $zeroFields = [
                'binding_hash_drift_count', 'unbound_mission_count', 'unknown_role_count',
                'unknown_capability_count', 'unknown_tool_count', 'contract_schema_hash_drift_count',
                'admission_deny_bypass', 'admission_hold_bypass', 'requested_role_expansion_bypass',
                'csrf_bypass', 'career_chain_bypass', 'policy_reason_overwrite_count',
                'unauthorized_route_execution_count', 'model_calls', 'tool_calls', 'external_calls',
                'business_writes', 'cms_writes', 'url_truth_writes', 'search_writes',
                'active_manifest_count', 'trusted_key_count', 'l4_allow_count', 'production_permissions',
                'active_legacy_seo_agent_entrypoints', 'receipt_projection_bypass',
            ];
            $ready = $bindingReport['valid'] === true
                && $dependency['status'] === 'READY'
                && $receipt['unique_orchestrator_probe_total'] === 1
                && $entrypointPassed === 5
                && $routingMetrics['routing_precision'] === ['numerator' => 32, 'denominator' => 32, 'measurement_state' => 'observed']
                && $routingMetrics['routing_recall'] === ['numerator' => 32, 'denominator' => 32, 'measurement_state' => 'observed']
                && $routingMetrics['missed_required_mode_rate']['numerator'] === 0
                && $routingMetrics['unnecessary_mode_rate']['numerator'] === 0
                && $routingMetrics['unauthorized_all_team_invocation_count']['numerator'] === 0
                && $runtimeSnapshot['mission_execution_enabled'] === false
                && $runtimeSnapshot['mission_persistence_enabled'] === false;
            foreach ($zeroFields as $field) {
                $ready = $ready && ($receipt[$field] ?? null) === 0;
            }
            $receipt['SEO-PLATFORM-11D'] = $ready ? 'CLOSED' : 'HOLD';
            $receipt['ready_for_11E'] = $ready;
            $receipt['SEO-PLATFORM-11E'] = $technicalReceipt['SEO-PLATFORM-11E'];
            $receipt['ready_for_11F'] = $technicalReceipt['ready_for_11F'];
            $receipt['SEO-PLATFORM-11F'] = $measurementReceipt['SEO-PLATFORM-11F'];
            $receipt['ready_for_11G'] = $measurementReceipt['ready_for_11G'];
            $receipt['receipt_hash'] = $hasher->hash($receipt);

            $expectedTechnicalState = match ($closeoutEnvironment) {
                'production_runtime' => 'CLOSED',
                'staging_runtime' => 'STAGING_READY',
                default => 'CANDIDATE_READY',
            };
            $expectedMeasurementState = match ($closeoutEnvironment) {
                'production_runtime' => 'CLOSED',
                'staging_runtime' => 'STAGING_READY',
                default => 'OFFLINE_EVAL_READY',
            };
            $expectedPlatform11State = match ($closeoutEnvironment) {
                'production_runtime' => 'CLOSED',
                'staging_runtime' => 'STAGING_READY',
                default => 'OFFLINE_EVAL_READY',
            };

            return $this->emit(
                $receipt,
                $ready && ($technicalReceipt['closeout_state'] ?? null) === $expectedTechnicalState
                    && ($measurementReceipt['closeout_state'] ?? null) === $expectedMeasurementState
                    && ($platform11Receipt['closeout_state'] ?? null) === $expectedPlatform11State
                    && ($platform11EditorialReceipt['closeout_state'] ?? null) === $expectedPlatform11State
                    && ($platform11RuntimeQaReceipt['closeout_state'] ?? null) === $expectedPlatform11State
                    && ($platform11IndependentReviewReceipt['closeout_state'] ?? null) === $expectedPlatform11State
                    && ($platform11LifecycleReceipt['closeout_state'] ?? null) === $expectedPlatform11State
                    ? self::SUCCESS
                    : self::FAILURE,
            );
        } catch (Throwable) {
            return $this->emit(['status' => 'failed', 'safe_error_code' => 'SEO_COUNCIL_CLOSEOUT_FAILED'], self::FAILURE);
        }
    }

    private function parentSha(): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD^'], dirname(base_path()));
        $process->mustRun();

        return strtolower(trim($process->getOutput()));
    }

    private function requestedRoleExpansionBypass(
        RoleCapabilityBindingRegistry $binding,
        CouncilContractValidator $validator,
        SeoRegistryHasher $hasher,
    ): int {
        $request = $this->request('bounded_review', 'tests', 'en', ['search_measurement'], 'analytics');
        $request['requested_role'] = 'seo.expert.technical_search_authority';
        try {
            $binding->validateRequestScope(MissionRequestData::fromInput($request, 'api', $validator, $hasher));

            return 1;
        } catch (InvalidArgumentException $exception) {
            return (int) ($exception->getMessage() !== 'REQUESTED_ROLE_EXPANSION_DENIED');
        }
    }

    private function privateDataBypass(CouncilContractValidator $validator): int
    {
        $request = $this->request('weekly_opportunity', 'tests', 'en', ['search_measurement']);
        $request['mission_id'] = 'owner@example.test';
        try {
            $validator->missionRequest($request);

            return 1;
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /** @param array<string, mixed> $receipt @return array{total:int,bypass:int} */
    private function receiptProjectionProbe(array $receipt): array
    {
        $required = [
            'source_sha', 'registry_version', 'registry_hash', 'binding_version', 'binding_hash',
            'binding_schema_probe_total', 'binding_schema_probe_passed', 'binding_schema_probe_failed',
            'binding_hash_drift_count', 'unbound_mission_count', 'unknown_role_count',
            'unknown_capability_count', 'unknown_tool_count', 'admission_deny_probe_total',
            'admission_deny_bypass', 'admission_hold_probe_total', 'admission_hold_bypass',
            'requested_role_expansion_bypass', 'five_entrypoint_probe_total',
            'five_entrypoint_probe_passed', 'csrf_negative_probe_total', 'csrf_bypass',
            'career_chain_probe_total', 'career_chain_bypass', 'policy_reason_overwrite_count',
            'unauthorized_route_execution_count', 'model_calls', 'tool_calls', 'external_calls',
            'business_writes', 'cms_writes', 'url_truth_writes', 'search_writes',
            'active_manifest_count', 'trusted_key_count', 'l4_allow_count', 'production_permissions',
        ];
        $passed = ($receipt['contract_version'] ?? null) === 'seo.council_closeout.v2'
            && preg_match('/^[a-f0-9]{40}$/D', (string) ($receipt['source_sha'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['binding_hash'] ?? '')) === 1
            && array_diff($required, array_keys($receipt)) === [];

        return ['total' => 1, 'bypass' => (int) (! $passed)];
    }

    private function budgetExpansionBypass(CouncilContractValidator $validator): int
    {
        foreach (['model_calls', 'tool_calls', 'external_calls', 'execution_seconds', 'retry_count', 'context_bytes', 'cost_amount'] as $field) {
            $request = $this->request('weekly_opportunity', 'tests', 'en', ['search_measurement']);
            $request['budget'][$field] = 1;
            try {
                $validator->missionRequest($request);

                return 1;
            } catch (InvalidArgumentException) {
                // Expected fail-closed result.
            }
        }

        return 0;
    }

    private function peerDelegationBypass(CouncilContractValidator $validator): int
    {
        $output = [
            'output_id' => str_repeat('b', 64),
            'handoff_hash' => str_repeat('a', 64),
            'role_id' => 'seo.independent_reviewer',
            'status' => 'PASS',
            'summary_code' => 'probe_pass',
            'execution_allowed' => false,
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'output_hash' => str_repeat('c', 64),
            'peer_handoff' => ['seo.cms_writer'],
        ];

        return (int) $validator->modeOutput($output, str_repeat('a', 64), 'seo.independent_reviewer');
    }

    /** @return array{total:int,bypass:int} */
    private function csrfProbe(): array
    {
        $ui = Route::getRoutes()->getByName('ops.seo_intel.council.ui_missions.store');
        $api = Route::getRoutes()->getByName('api.v0_5.ops.seo_intel.council.missions.store');
        $blade = (string) file_get_contents(resource_path('views/filament/ops/components/ops-agent-council-workspace.blade.php'));
        $probes = [
            $ui !== null && in_array('web', $ui->gatherMiddleware(), true),
            str_contains($blade, '@csrf'),
            $api !== null && ! in_array('web', $api->gatherMiddleware(), true),
        ];

        return ['total' => count($probes), 'bypass' => count(array_filter($probes, static fn (bool $passed): bool => ! $passed))];
    }

    /** @param array{id:string,version:string,hash:string} $bindingRef */
    private function careerChainBypass(DeterministicMissionRouter $router, CouncilContractValidator $validator, SeoRegistryHasher $hasher, array $bindingRef): int
    {
        $request = MissionRequestData::fromInput(
            $this->request('career_candidate_generation', 'career', 'zh-CN', ['career_candidate', 'content_claim', 'career_manifest_validation']),
            'cli',
            $validator,
            $hasher,
        );
        $route = $router->route($request);

        return (int) ($route['roles'] !== ['career.content_agent', 'seo.expert.content_entity_quality', 'seo.independent_reviewer']
            || $route['binding_ref'] !== $bindingRef
            || $route['max_modes'] !== 3
            || $route['all_team'] !== false);
    }

    /** @param list<array<string, mixed>> $receipts @return array<string, int> */
    private function activityFromReceipts(array $receipts): array
    {
        $mapping = [
            'model_calls' => 'model_calls',
            'tool_calls' => 'tool_calls',
            'external_calls' => 'external_calls',
            'business_writes' => 'business_writes',
            'cms_writes' => 'cms_writes',
            'url_truth_writes' => 'url_truth_writes',
            'search_writes' => 'search_submissions',
        ];
        $activity = [];
        foreach ($mapping as $output => $source) {
            $activity[$output] = array_sum(array_map(
                static fn (array $receipt): int => (int) ($receipt['negative_guarantees'][$source] ?? -1),
                $receipts,
            ));
        }

        return $activity;
    }

    /** @param array<string, mixed> $registry @param array<string, mixed> $runtime */
    private function productionPermissionCount(array $registry, array $runtime): int
    {
        $roleWrites = array_sum(array_map(static fn (array $role): int => count((array) ($role['write_permissions'] ?? [])), (array) $registry['roles']));

        return $roleWrites
            + (int) ($runtime['agent_write_permissions'] ?? 1)
            + (int) (($runtime['mission_execution_enabled'] ?? true) === true)
            + (int) (($registry['global_guards']['model_invocation_enabled'] ?? true) === true)
            + (int) (($registry['global_guards']['runtime_model_invocation_enabled'] ?? true) === true)
            + (int) (($registry['global_guards']['search_submission_allowed'] ?? true) === true);
    }

    private function activeLegacyEntrypoints(): int
    {
        $active = 0;
        foreach (Artisan::all() as $name => $command) {
            if (str_starts_with($name, 'seo'.'-agent:')) {
                $active += (int) (! $command instanceof RetiredSeoAgentCommand || $command::AGENT_INVOCABLE);
            }
        }

        return $active;
    }

    /** @param list<string> $types @return array<string, mixed> */
    private function request(string $mission, string $family, string $locale, array $types, ?string $reviewDomain = null): array
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
            'review_domain' => $reviewDomain,
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
