<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\CliMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\ScheduledMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\SeoOperationsUiMissionAdapter;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoIntel\Detector\SeoDetectorRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;

final class TechnicalDiagnosisCloseoutBuilder
{
    private const ENVIRONMENTS = ['ci_candidate', 'staging_runtime', 'production_runtime'];

    public function __construct(
        private readonly TechnicalDiagnosisContractRegistry $contracts,
        private readonly TechnicalDiagnosisContractValidator $validator,
        private readonly TechnicalDiagnosisModeRegistry $mode,
        private readonly TechnicalDiagnosisDependencySnapshotBuilder $dependencies,
        private readonly TechnicalDiagnosisEvidenceContextBuilder $contexts,
        private readonly TechnicalDiagnosisFixtureEvaluator $fixtures,
        private readonly TechnicalPrivateNegativeSetEvaluator $privateNegativeSet,
        private readonly TechnicalDiagnosisActivityLedger $activity,
        private readonly TechnicalDiagnosisDependencyBindingSource $dependencyEvidence,
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly PolicyGatewayRegistry $policy,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly SeoDetectorRegistry $detectors,
        private readonly PageFamilyPolicyRegistry $pageFamilies,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $candidateSha, string $environment = 'ci_candidate'): array
    {
        if (! in_array($environment, self::ENVIRONMENTS, true)) {
            $environment = 'ci_candidate';
        }
        $fixture = $this->fixtures->evaluate();
        $fixtureMetrics = (array) $fixture['metrics'];
        $private = $this->privateNegativeSet->evaluate();
        $registry = $this->roles->registry();
        $policy = $this->policy->registry();
        $binding = $this->binding->reference();
        $mode = $this->mode->references();
        $runtime = $this->mode->capabilitySnapshot();
        $state = $this->dependencyState($candidateSha, $environment, $fixture);
        $dependency = $this->dependencies->build($candidateSha, $environment, $state);
        $dependencyProbes = $this->dependencies->negativeProbeMetrics($dependency, $candidateSha, $environment);
        $ownershipProbes = $this->contexts->ownershipProbeMetrics();
        $routing = $this->routingMetrics($runtime, $environment === 'ci_candidate');
        $activity = $this->activity->snapshot();
        $negativeFields = [
            'model_calls', 'tool_calls', 'external_calls', 'business_writes', 'cms_writes',
            'url_truth_writes', 'canonical_writes', 'robots_writes', 'feed_writes', 'search_writes',
            'active_manifest_count', 'trusted_key_count', 'l4_allow_count', 'production_permissions',
        ];
        $hardcodedGuaranteeCount = count(array_filter(
            $negativeFields,
            static fn (string $field): bool => ! is_int($activity[$field] ?? null),
        ));
        $severity = [
            'unsupported_p0_p1_count' => $fixtureMetrics['unsupported_p0_p1_count'],
            'evidence_state_misclassification_count' => $fixtureMetrics['evidence_state_misclassification_count'],
            'hypothesis_fact_confusion_count' => $fixtureMetrics['hypothesis_fact_confusion_count'],
        ];
        $authority = [
            'authority_invention_count' => $fixtureMetrics['authority_invention_count'],
            'policy_bypass_count' => $fixtureMetrics['policy_bypass_count'],
            'dependency_hold_count' => (int) (($dependency['status'] ?? null) !== 'READY'),
            'historical_authority_drift_count' => (int) (($dependency['historical_authority_immutable'] ?? null) !== true),
            'contract_hash_drift_count' => (int) (! $this->generatedManifestValid()),
        ];
        $metrics = [
            'real_dependency_binding_bypass' => $this->realDependencyBindingBypass($environment, $state, $dependency),
            ...$dependencyProbes,
            ...$ownershipProbes,
            'unsupported_p0_p1_count' => (int) $fixtureMetrics['unsupported_p0_p1_count'],
            'authority_invention_count' => (int) $fixtureMetrics['authority_invention_count'],
            'hardcoded_negative_guarantee_count' => $hardcodedGuaranteeCount,
            'orchestrator_runner_bypass' => (int) ($activity['runner_calls'] ?? 1),
            'private_url_leak_count' => (int) $fixtureMetrics['private_url_leak_count'] + (int) $private['private_data_leak_count'],
            'policy_bypass_count' => (int) $fixtureMetrics['policy_bypass_count'],
            'write_attempt_count' => (int) $fixtureMetrics['write_attempt_count'],
            'shared_root_misclassification_count' => (int) $fixtureMetrics['shared_root_misclassification_count'],
        ];
        foreach ($negativeFields as $field) {
            $metrics[$field] = (int) ($activity[$field] ?? 1);
        }
        $fixtureZero = [
            'false_positive', 'false_negative', 'requested_role_expansion_bypass_count',
            'evidence_state_misclassification_count', 'hypothesis_fact_confusion_count',
        ];
        $zeroMetrics = array_intersect_key($metrics, array_flip(TechnicalDiagnosisContractValidator::zeroMetricFields()));
        $ready = preg_match('/^[a-f0-9]{40}$/D', $candidateSha) === 1
            && ($dependency['status'] ?? null) === 'READY'
            && $this->generatedManifestValid()
            && ($routing['routing_bypass_count'] ?? 1) === 0
            && ($routing['five_entrypoint_probe_passed'] ?? 0) === 5
            && ($runtime['production_execution_enabled'] ?? true) === false
            && array_sum($zeroMetrics) === 0;
        foreach ($fixtureZero as $field) {
            $ready = $ready && ($fixtureMetrics[$field] ?? null) === 0;
        }
        foreach ($private as $field => $value) {
            $ready = $ready && ($field === 'probe_total' || $value === 0);
        }
        $closeoutState = match ($environment) {
            'production_runtime' => $ready ? 'CLOSED' : 'DEPENDENCY_HOLD',
            'staging_runtime' => $ready ? 'STAGING_READY' : 'DEPENDENCY_HOLD',
            default => $ready ? 'CANDIDATE_READY' : 'DEPENDENCY_HOLD',
        };
        $closed = $environment === 'production_runtime' && $closeoutState === 'CLOSED';
        $receipt = [
            'receipt_id' => $this->hasher->hash([$candidateSha, $environment, $dependency['snapshot_hash'], $fixture['fixture_set_hash']]),
            'receipt_version' => 'seo.technical_diagnosis_closeout.v2',
            'environment' => $environment, 'dependency_mode' => $dependency['dependency_mode'],
            'closeout_state' => $closeoutState, 'candidate_sha' => $candidateSha,
            'observed_active_sha' => $dependency['observed_active_sha'],
            'dependency_snapshot_id' => $dependency['snapshot_id'],
            'dependency_snapshot_version' => $dependency['snapshot_version'],
            'dependency_snapshot_hash' => $dependency['snapshot_hash'],
            'registry_version' => $registry['registry_version'], 'registry_hash' => $registry['registry_hash'],
            'binding_version' => $binding['version'], 'binding_hash' => $binding['hash'],
            'policy_version' => $policy['registry_version'], 'policy_hash' => $policy['registry_hash'],
            'technical_mode_version' => $mode['technical_diagnosis_mode_version'],
            'technical_mode_hash' => $mode['technical_diagnosis_mode_hash'],
            'prompt_version' => $mode['technical_diagnosis_prompt_version'], 'prompt_hash' => $mode['technical_diagnosis_prompt_hash'],
            'technical_policy_version' => $mode['technical_diagnosis_policy_version'],
            'technical_policy_hash' => $mode['technical_diagnosis_policy_hash'],
            'output_schema_version' => $mode['output_schema_version'], 'output_schema_hash' => $mode['output_schema_hash'],
            'detector_registry_version' => $dependency['detector_registry_ref']['registry_version'],
            'detector_registry_hash' => $dependency['detector_registry_ref']['registry_hash'],
            'page_family_policy_version' => $dependency['page_family_policy_ref']['version'],
            'page_family_policy_hash' => $dependency['page_family_policy_ref']['hash'],
            'source_field_ownership_version' => $mode['source_field_ownership_version'],
            'source_field_ownership_hash' => $mode['source_field_ownership_hash'],
            'context_schema_version' => $mode['context_schema_version'], 'context_schema_hash' => $mode['context_schema_hash'],
            'url_truth_revision' => $dependency['url_truth_revision'], 'url_truth_projection_hash' => $dependency['url_truth_projection_hash'],
            'runtime_evidence_revision' => $dependency['runtime_evidence_revision'], 'runtime_evidence_hash' => $dependency['runtime_evidence_hash'],
            'authority_revision' => $dependency['authority_revision'], 'deployment_revision' => $dependency['deployment_revision'],
            'fixture_metrics' => $fixtureMetrics, 'private_negative_set_metrics' => $private,
            'severity_metrics' => $severity, 'authority_metrics' => $authority, 'routing_metrics' => $routing,
            ...$metrics,
            'execution_allowed' => false, 'SEO-PLATFORM-11E' => $closed ? 'CLOSED' : 'HOLD',
            'ready_for_11F' => $closed,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @param array<string, mixed> $receipt */
    public function verify(array $receipt, string $expectedSha, ?string $expectedEnvironment = null): bool
    {
        if (! $this->validator->receipt($receipt)
            || ($receipt['candidate_sha'] ?? null) !== $expectedSha
            || ($expectedEnvironment !== null && ($receipt['environment'] ?? null) !== $expectedEnvironment)) {
            return false;
        }
        $registry = $this->roles->registry();
        $policy = $this->policy->registry();
        $binding = $this->binding->reference();
        $mode = $this->mode->references();
        $current = [
            'registry_version' => $registry['registry_version'], 'registry_hash' => $registry['registry_hash'],
            'binding_version' => $binding['version'], 'binding_hash' => $binding['hash'],
            'policy_version' => $policy['registry_version'], 'policy_hash' => $policy['registry_hash'],
            'technical_mode_version' => $mode['technical_diagnosis_mode_version'], 'technical_mode_hash' => $mode['technical_diagnosis_mode_hash'],
            'technical_policy_version' => $mode['technical_diagnosis_policy_version'], 'technical_policy_hash' => $mode['technical_diagnosis_policy_hash'],
            'detector_registry_version' => SeoDetectorRegistry::VERSION, 'detector_registry_hash' => $this->detectors->registryHash(),
            'page_family_policy_version' => PageFamilyPolicyRegistry::VERSION, 'page_family_policy_hash' => $this->pageFamilies->policyHash(),
            'source_field_ownership_version' => $mode['source_field_ownership_version'], 'source_field_ownership_hash' => $mode['source_field_ownership_hash'],
            'context_schema_version' => $mode['context_schema_version'], 'context_schema_hash' => $mode['context_schema_hash'],
        ];
        foreach ($current as $field => $value) {
            if (($receipt[$field] ?? null) !== $value) {
                return false;
            }
        }
        if (($receipt['environment'] ?? null) === 'production_runtime') {
            foreach (['url_truth_revision', 'runtime_evidence_revision', 'authority_revision'] as $field) {
                if (str_contains((string) ($receipt[$field] ?? ''), 'fixture')
                    || str_contains((string) ($receipt[$field] ?? ''), 'offline-eval')) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function dependencyState(string $sha, string $environment, array $fixture): array
    {
        if ($environment === 'ci_candidate') {
            $fixtureHash = (string) $fixture['fixture_set_hash'];

            return [
                'dependency_mode' => 'OFFLINE_FIXTURE', 'observed_active_sha' => null,
                'url_truth_revision' => 'fixture:url-truth:'.substr($fixtureHash, 0, 32),
                'url_truth_projection_hash' => hash('sha256', 'fixture:url-truth:'.$fixtureHash),
                'runtime_evidence_revision' => (string) $fixture['fixture_set_id'], 'runtime_evidence_hash' => $fixtureHash,
                'deployment_revision' => $sha, 'authority_revision' => 'fixture:authority:'.substr($fixtureHash, 32, 32),
                'page_family' => 'tests', 'locale' => 'en', 'source_capability_state' => 'available',
            ];
        }
        $binding = $this->dependencyEvidence->technicalDiagnosisBinding($sha);

        return [
            'dependency_mode' => 'RUNTIME_READ_ONLY', 'observed_active_sha' => $sha,
            'url_truth_revision' => $binding['url_truth_revision'], 'url_truth_projection_hash' => $binding['url_truth_projection_hash'],
            'runtime_evidence_revision' => $binding['runtime_evidence_revision'], 'runtime_evidence_hash' => $binding['runtime_evidence_hash'],
            'deployment_revision' => $binding['deployment_revision'], 'authority_revision' => $binding['authority_revision'],
            'page_family' => 'tests', 'locale' => 'en', 'source_capability_state' => $binding['source_capability_state'],
        ];
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $dependency */
    private function realDependencyBindingBypass(string $environment, array $state, array $dependency): int
    {
        $modeValid = $environment === 'ci_candidate'
            ? ($state['dependency_mode'] ?? null) === 'OFFLINE_FIXTURE' && ($state['observed_active_sha'] ?? null) === null
            : ($state['dependency_mode'] ?? null) === 'RUNTIME_READ_ONLY'
                && ($state['observed_active_sha'] ?? null) === ($dependency['production_sha'] ?? null);
        $runtimePretendsOffline = $environment !== 'ci_candidate'
            && (str_contains((string) ($state['url_truth_revision'] ?? ''), 'fixture')
                || str_contains((string) ($state['authority_revision'] ?? ''), 'offline-eval'));

        return (int) (! $modeValid || $runtimePretendsOffline);
    }

    /** @param array<string, mixed> $runtime @return array<string, int|string> */
    private function routingMetrics(array $runtime, bool $submitCandidateMissions): array
    {
        $mission = $this->binding->mission('bounded_review');
        $variant = $this->binding->selectorVariant($mission, 'technical');
        $entrypoints = [
            LocalSkillMissionAdapter::class => 'local_skill', CliMissionAdapter::class => 'cli',
            ScheduledMissionAdapter::class => 'scheduler', ApiMissionAdapter::class => 'api',
            SeoOperationsUiMissionAdapter::class => 'seo_operations_ui',
        ];
        $receipts = [];
        if ($submitCandidateMissions) {
            foreach ($entrypoints as $entrypoint => $caller) {
                $input = $this->technicalMission();
                $input['idempotency_key'] .= ':'.$caller;
                $receipt = app($entrypoint)->submit($input);
                $receipts[] = $receipt + ['entrypoint_probe_failed' => ($receipt['caller_provenance']['caller_type'] ?? null) !== $caller];
            }
            $evidenceHashes = array_unique(array_map(static fn (array $receipt): string => (string) ($receipt['evidence_hash'] ?? ''), $receipts));
            $passed = count(array_filter($receipts, static fn (array $receipt): bool => ($receipt['status'] ?? null) === 'POLICY_HOLD'
                && ($receipt['stop_reason'] ?? null) === 'ROLE_CAPABILITY_BINDING_UNAVAILABLE'
                && ($receipt['route_plan'] ?? null) === [] && ($receipt['steps'][3]['status'] ?? null) === 'HOLD'
                && ($receipt['steps'][4]['status'] ?? null) === 'NOT_RUN' && ($receipt['execution_allowed'] ?? null) === false
                && ($receipt['entrypoint_probe_failed'] ?? true) === false));
        } else {
            $entrypointsPresent = count(array_filter(array_keys($entrypoints), 'class_exists'));
            $guards = $this->policy->guards();
            $denyOnly = $this->policy->dependencyStatus() === 'READY'
                && ($guards['model_invocation_enabled'] ?? null) === false
                && ($guards['tool_invocation_enabled'] ?? null) === false
                && ($guards['external_egress_enabled'] ?? null) === false
                && ($guards['global_write_gate'] ?? null) === false;
            $passed = $denyOnly ? $entrypointsPresent : 0;
            $evidenceHashes = ['runtime_read_only_structure_probe'];
        }
        $orchestratorCount = count(glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []);
        $validRoute = is_array($variant) && ($mission['max_modes'] ?? null) === 1
            && ($variant['eligible_roles'] ?? null) === ['seo.expert.technical_search_authority']
            && ($mission['allow_delegation'] ?? null) === false && ($runtime['mode_state'] ?? null) === 'OFFLINE_EVAL_READY'
            && ($runtime['production_execution_enabled'] ?? null) === false;

        return [
            'technical_route_probe_total' => 1, 'technical_route_probe_passed' => (int) $validRoute,
            'five_entrypoint_probe_total' => count($entrypoints), 'five_entrypoint_probe_passed' => $passed,
            'unique_orchestrator_count' => $orchestratorCount,
            'routing_bypass_count' => (int) (! $validRoute || count($evidenceHashes) !== 1 || $passed !== 5 || $orchestratorCount !== 1),
            'mode_state' => (string) ($runtime['mode_state'] ?? 'INVALID'),
            'probe_mode' => $submitCandidateMissions ? 'ci_candidate_mission_probe' : 'runtime_read_only_structure_probe',
        ];
    }

    /** @return array<string, mixed> */
    private function technicalMission(): array
    {
        return [
            'mission_id' => 'mission:11e:closeout', 'idempotency_key' => 'idempotency:11e:closeout',
            'mission_type' => 'bounded_review', 'family' => 'tests', 'locale' => 'en',
            'review_domain' => 'technical', 'requested_role' => null,
            'evidence_bundle_refs' => [[
                'bundle_id' => 'bundle:11e:closeout', 'bundle_version' => 1,
                'bundle_hash' => hash('sha256', 'bundle:11e:closeout'), 'evidence_type' => 'runtime_health',
                'status' => 'READY', 'authority_revision' => str_repeat('a', 64),
            ]],
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'resume_from' => null,
        ];
    }

    private function generatedManifestValid(): bool
    {
        $path = base_path('docs/seo/generated/seo-technical-diagnosis-contract-manifest.v2.json');
        if (! is_file($path)) {
            return false;
        }
        $artifact = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($artifact) && $this->contracts->verify($artifact);
    }
}
