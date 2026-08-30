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
use Carbon\CarbonImmutable;

final class TechnicalDiagnosisCloseoutBuilder
{
    public function __construct(
        private readonly TechnicalDiagnosisContractRegistry $contracts,
        private readonly TechnicalDiagnosisContractValidator $validator,
        private readonly TechnicalDiagnosisModeRegistry $mode,
        private readonly TechnicalDiagnosisDependencySnapshotBuilder $dependencies,
        private readonly TechnicalDiagnosisFixtureEvaluator $fixtures,
        private readonly TechnicalPrivateNegativeSetEvaluator $privateNegativeSet,
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly PolicyGatewayRegistry $policy,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly SeoDetectorRegistry $detectors,
        private readonly PageFamilyPolicyRegistry $pageFamilies,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $sourceSha): array
    {
        $fixture = $this->fixtures->evaluate();
        $metrics = (array) $fixture['metrics'];
        $private = $this->privateNegativeSet->evaluate();
        $registry = $this->roles->registry();
        $policy = $this->policy->registry();
        $binding = $this->binding->reference();
        $mode = $this->mode->references();
        $runtime = $this->mode->capabilitySnapshot();
        $now = CarbonImmutable::now('UTC');
        $urlTruthRevision = 'offline-eval:url-truth.v1';
        $urlTruthHash = hash('sha256', $urlTruthRevision.'|'.$sourceSha);
        $dependency = $this->dependencies->build($sourceSha, [
            'production_closeout_sha' => $sourceSha,
            'url_truth_revision' => $urlTruthRevision,
            'url_truth_projection_hash' => $urlTruthHash,
            'runtime_evidence_revision' => (string) $fixture['fixture_set_id'],
            'runtime_evidence_hash' => (string) $fixture['fixture_set_hash'],
            'deployment_revision' => $sourceSha,
            'evidence_deployment_revision' => $sourceSha,
            'authority_revision' => 'offline-eval:authority.v1',
            'evidence_authority_revision' => 'offline-eval:authority.v1',
            'page_family' => 'tests',
            'locale' => 'en',
            'evidence_captured_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'evidence_expires_at' => $now->addHour()->format('Y-m-d\TH:i:s\Z'),
            'source_capability_state' => 'available',
            'evidence_freshness_state' => 'fresh',
        ]);
        $routing = $this->routingMetrics($runtime);
        $authorityMetrics = [
            'authority_invention_count' => $metrics['authority_invention_count'],
            'policy_bypass_count' => $metrics['policy_bypass_count'],
            'dependency_hold_count' => (int) (($dependency['status'] ?? null) !== 'READY'),
            'historical_authority_drift_count' => (int) (($dependency['historical_authority_immutable'] ?? null) !== true),
            'contract_hash_drift_count' => $this->generatedManifestValid() ? 0 : 1,
        ];
        $severityMetrics = [
            'unsupported_p0_p1_count' => $metrics['unsupported_p0_p1_count'],
            'evidence_state_misclassification_count' => $metrics['evidence_state_misclassification_count'],
            'hypothesis_fact_confusion_count' => $metrics['hypothesis_fact_confusion_count'],
        ];
        $zeroFixtureFields = [
            'false_positive', 'false_negative', 'unsupported_p0_p1_count', 'authority_invention_count',
            'private_url_leak_count', 'policy_bypass_count', 'requested_role_expansion_bypass_count',
            'write_attempt_count', 'shared_root_misclassification_count',
            'evidence_state_misclassification_count', 'hypothesis_fact_confusion_count',
        ];
        $ready = preg_match('/^[a-f0-9]{40}$/D', $sourceSha) === 1
            && ($dependency['status'] ?? null) === 'READY'
            && $this->generatedManifestValid()
            && ($routing['routing_bypass_count'] ?? 1) === 0
            && ($routing['five_entrypoint_probe_passed'] ?? 0) === 5
            && ($runtime['production_execution_enabled'] ?? true) === false;
        foreach ($zeroFixtureFields as $field) {
            $ready = $ready && ($metrics[$field] ?? null) === 0;
        }
        foreach ($private as $field => $value) {
            if ($field !== 'probe_total') {
                $ready = $ready && $value === 0;
            }
        }
        $receipt = [
            'receipt_id' => $this->hasher->hash([$sourceSha, $this->contracts->manifest()['manifest_hash'], $fixture['fixture_set_hash']]),
            'receipt_version' => 'seo.technical_diagnosis_closeout.v1',
            'source_sha' => $sourceSha,
            'production_sha' => $sourceSha,
            'registry_version' => $registry['registry_version'],
            'registry_hash' => $registry['registry_hash'],
            'binding_version' => $binding['version'],
            'binding_hash' => $binding['hash'],
            'policy_version' => $policy['registry_version'],
            'policy_hash' => $policy['registry_hash'],
            'technical_mode_version' => $mode['technical_diagnosis_mode_version'],
            'technical_mode_hash' => $mode['technical_diagnosis_mode_hash'],
            'prompt_version' => $mode['technical_diagnosis_prompt_version'],
            'prompt_hash' => $mode['technical_diagnosis_prompt_hash'],
            'technical_policy_version' => $mode['technical_diagnosis_policy_version'],
            'technical_policy_hash' => $mode['technical_diagnosis_policy_hash'],
            'output_schema_version' => $mode['output_schema_version'],
            'output_schema_hash' => $mode['output_schema_hash'],
            'detector_registry_version' => SeoDetectorRegistry::VERSION,
            'detector_registry_hash' => $this->detectors->registryHash(),
            'page_family_policy_version' => PageFamilyPolicyRegistry::VERSION,
            'page_family_policy_hash' => $this->pageFamilies->policyHash(),
            'url_truth_revision' => $urlTruthRevision,
            'url_truth_projection_hash' => $urlTruthHash,
            'runtime_evidence_revision' => $fixture['fixture_set_id'],
            'fixture_metrics' => $metrics,
            'private_negative_set_metrics' => $private,
            'severity_metrics' => $severityMetrics,
            'authority_metrics' => $authorityMetrics,
            'routing_metrics' => $routing,
            'private_url_leak_count' => $metrics['private_url_leak_count'] + $private['private_data_leak_count'],
            'unsupported_p0_p1_count' => $metrics['unsupported_p0_p1_count'],
            'authority_invention_count' => $metrics['authority_invention_count'],
            'policy_bypass_count' => $metrics['policy_bypass_count'],
            'write_attempt_count' => $metrics['write_attempt_count'],
            'shared_root_misclassification_count' => $metrics['shared_root_misclassification_count'],
            'model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0,
            'business_writes' => 0, 'cms_writes' => 0, 'url_truth_writes' => 0,
            'canonical_writes' => 0, 'robots_writes' => 0, 'feed_writes' => 0, 'search_writes' => 0,
            'active_manifest_count' => 0, 'trusted_key_count' => 0, 'l4_allow_count' => 0,
            'production_permissions' => 0, 'execution_allowed' => false,
            'SEO-PLATFORM-11E' => $ready ? 'CLOSED' : 'HOLD',
            'ready_for_11F' => $ready,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @param array<string, mixed> $receipt */
    public function verify(array $receipt, string $expectedSha): bool
    {
        if (! $this->validator->receipt($receipt) || ($receipt['source_sha'] ?? null) !== $expectedSha) {
            return false;
        }
        $expected = $this->build($expectedSha);

        return hash_equals((string) $expected['receipt_hash'], (string) $receipt['receipt_hash']);
    }

    /** @param array<string, mixed> $runtime @return array<string, int|string> */
    private function routingMetrics(array $runtime): array
    {
        $mission = $this->binding->mission('bounded_review');
        $variant = $this->binding->selectorVariant($mission, 'technical');
        $entrypoints = [
            LocalSkillMissionAdapter::class => 'local_skill',
            CliMissionAdapter::class => 'cli',
            ScheduledMissionAdapter::class => 'scheduler',
            ApiMissionAdapter::class => 'api',
            SeoOperationsUiMissionAdapter::class => 'seo_operations_ui',
        ];
        $receipts = [];
        foreach ($entrypoints as $entrypoint => $caller) {
            $receipt = app($entrypoint)->submit($this->technicalMission());
            $receipts[] = $receipt;
            if (($receipt['caller_provenance']['caller_type'] ?? null) !== $caller) {
                $receipts[array_key_last($receipts)]['entrypoint_probe_failed'] = true;
            }
        }
        $requestHashes = array_unique(array_map(static fn (array $receipt): string => (string) ($receipt['request_hash'] ?? ''), $receipts));
        $passed = count(array_filter($receipts, static fn (array $receipt): bool => ($receipt['status'] ?? null) === 'POLICY_HOLD'
            && ($receipt['stop_reason'] ?? null) === 'ROLE_CAPABILITY_BINDING_UNAVAILABLE'
            && ($receipt['route_plan'] ?? null) === []
            && ($receipt['steps'][3]['status'] ?? null) === 'HOLD'
            && ($receipt['steps'][4]['status'] ?? null) === 'NOT_RUN'
            && ($receipt['execution_allowed'] ?? null) === false
            && ! isset($receipt['entrypoint_probe_failed'])
        ));
        $validRoute = is_array($variant)
            && ($mission['max_modes'] ?? null) === 1
            && ($variant['eligible_roles'] ?? null) === ['seo.expert.technical_search_authority']
            && ($mission['allow_delegation'] ?? null) === false
            && ($runtime['mode_state'] ?? null) === 'OFFLINE_EVAL_READY'
            && ($runtime['production_execution_enabled'] ?? null) === false;

        return [
            'technical_route_probe_total' => 1,
            'technical_route_probe_passed' => (int) $validRoute,
            'five_entrypoint_probe_total' => count($receipts),
            'five_entrypoint_probe_passed' => $passed,
            'unique_orchestrator_count' => count(glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []),
            'routing_bypass_count' => (int) (! $validRoute || count($requestHashes) !== 1 || $passed !== 5 || count(glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []) !== 1),
            'mode_state' => (string) ($runtime['mode_state'] ?? 'INVALID'),
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
                'bundle_hash' => hash('sha256', 'bundle:11e:closeout'),
                'evidence_type' => 'runtime_health', 'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ]],
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'resume_from' => null,
        ];
    }

    private function generatedManifestValid(): bool
    {
        $path = base_path('docs/seo/generated/seo-technical-diagnosis-contract-manifest.v1.json');
        if (! is_file($path)) {
            return false;
        }
        $artifact = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($artifact) && $this->contracts->verify($artifact);
    }
}
