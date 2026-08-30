<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;

final class MeasurementCloseoutBuilder
{
    private const ZERO_FIELDS = [
        'false_positive', 'false_negative', 'source_state_misclassification_count',
        'measurement_state_misclassification_count', 'valid_zero_misclassification_count',
        'gai_capability_invention_count', 'causal_overclaim_count', 'attribution_overclaim_count',
        'private_data_leak_count', 'private_url_leak_count', 'production_metric_override_count',
        'policy_bypass_count', 'role_expansion_bypass_count', 'write_attempt_count',
    ];

    public function __construct(
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly SeoEvidenceContractRegistry $evidence,
        private readonly PolicyGatewayRegistry $policy,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly MeasurementContractRegistry $contracts,
        private readonly MeasurementModeRegistry $modes,
        private readonly MeasurementDependencySnapshotBuilder $dependencies,
        private readonly MeasurementFixtureEvaluator $fixtures,
        private readonly CommercialFunnelCROMode $cro,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $candidateSha, string $environment, string $currentProductionSha): array
    {
        $manifest = $this->contracts->manifest();
        $dependency = $this->dependencies->build($candidateSha, $environment, $currentProductionSha);
        $fixture = $this->fixtures->evaluate();
        $metrics = $fixture['metrics'];
        $gaiCapability = (array) ($this->contracts->searchPolicy()['gai_capability'] ?? []);
        $runtime = $this->modes->capabilitySnapshot();
        $registry = $this->roles->registry();
        $policy = $this->policy->registry();
        $binding = $this->binding->reference();
        $routing = $this->routingMetrics();
        $privateProbe = $this->cro->review(
            ['page_family' => 'tests', 'locale' => 'en', 'window' => ['days' => 28]],
            [
                'quality_gate_passed' => true,
                'current_window_readable' => true,
                'expected_evidence_present' => true,
                'window_complete' => true,
                'valid_measurement_present' => true,
                'user_id' => 1,
                'private_url' => '/en/results/private',
            ],
        );
        $privateNegative = [
            'probe_total' => 2,
            'private_data_leak_count' => (int) (($privateProbe['status'] ?? null) !== 'HOLD'),
            'private_url_leak_count' => (int) (($privateProbe['experiment_candidates'] ?? null) !== []),
        ];
        $generated = base_path('docs/seo/generated/seo-measurement-contract-manifest.v1.json');
        $generatedValid = is_file($generated)
            && $this->contracts->verify((array) json_decode((string) file_get_contents($generated), true, 512, JSON_THROW_ON_ERROR));
        $ready = preg_match('/^[a-f0-9]{40}$/D', $candidateSha) === 1
            && preg_match('/^[a-f0-9]{40}$/D', $currentProductionSha) === 1
            && ($dependency['status'] ?? null) === 'READY'
            && $generatedValid
            && ($runtime['mode_state'] ?? null) === 'OFFLINE_EVAL_READY'
            && ($runtime['production_execution_enabled'] ?? null) === false
            && ($gaiCapability['state'] ?? null) === 'manual_export_only'
            && ($gaiCapability['automatic_api_proven'] ?? null) === false
            && ($gaiCapability['manual_export_available'] ?? null) === true
            && ($routing['routing_bypass_count'] ?? 1) === 0
            && ($privateNegative['private_data_leak_count'] + $privateNegative['private_url_leak_count']) === 0;
        foreach (self::ZERO_FIELDS as $field) {
            $ready = $ready && ($metrics[$field] ?? null) === 0;
        }
        $closeoutState = match ($environment) {
            'production_runtime' => $ready ? 'CLOSED' : 'DEPENDENCY_HOLD',
            'staging_runtime' => $ready ? 'STAGING_READY' : 'DEPENDENCY_HOLD',
            default => $ready ? 'OFFLINE_EVAL_READY' : 'DEPENDENCY_HOLD',
        };
        $closed = $environment === 'production_runtime' && $closeoutState === 'CLOSED';
        $receipt = [
            'receipt_version' => 'seo.measurement_closeout.v1',
            'environment' => $environment,
            'closeout_state' => $closeoutState,
            'mode_state' => $runtime['mode_state'],
            'candidate_sha' => $candidateSha,
            'production_sha' => $currentProductionSha,
            'registry_version' => $registry['registry_version'],
            'registry_hash' => $registry['registry_hash'],
            'evidence_policy_version' => $this->evidence->manifest()['manifest_version'],
            'evidence_policy_hash' => $this->evidence->manifest()['manifest_hash'],
            'policy_version' => $policy['registry_version'],
            'policy_hash' => $policy['registry_hash'],
            'binding_version' => $binding['version'],
            'binding_hash' => $binding['hash'],
            'dependency_snapshot_version' => $dependency['snapshot_version'],
            'dependency_snapshot_hash' => $dependency['snapshot_hash'],
            'dependency_status' => $dependency['status'],
            'dependency_blockers' => $dependency['blockers'],
            'contract_manifest_version' => $manifest['manifest_version'],
            'contract_manifest_hash' => $manifest['manifest_hash'],
            'state_contract_hashes' => $this->contractHashes($manifest, [
                'seo.measurement_evidence_context.v1', 'seo.source_capability_decision.v1',
                'seo.measurement_state_decision.v1', 'seo.measurement_evidence_gap.v1',
            ]),
            'search_mode' => [
                'mode' => $manifest['search_mode'], 'prompt' => $manifest['search_prompt'],
                'policy' => $manifest['search_policy'],
                'schemas' => $this->contractHashes($manifest, [
                    'seo.search_measurement_request.v1', 'seo.search_measurement_finding.v1', 'seo.search_measurement_output.v1',
                ]),
            ],
            'cro_mode' => [
                'mode' => $manifest['cro_mode'], 'prompt' => $manifest['cro_prompt'],
                'policy' => $manifest['cro_policy'],
                'schemas' => $this->contractHashes($manifest, [
                    'seo.commercial_funnel_cro_request.v1', 'seo.commercial_funnel_cro_finding.v1',
                    'seo.cro_experiment_candidate.v1', 'seo.commercial_funnel_cro_output.v1',
                ]),
            ],
            'fixture_metrics' => $metrics,
            'private_negative_set_metrics' => $privateNegative,
            'routing_metrics' => $routing,
            'gai_capability_state' => $gaiCapability['state'],
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'production_metric_override_count' => 0,
            'cms_writes' => 0,
            'url_truth_writes' => 0,
            'search_writes' => 0,
            'business_writes' => 0,
            'active_manifest_count' => 0,
            'trusted_key_count' => 0,
            'production_permissions' => 0,
            'execution_allowed' => false,
            'SEO-PLATFORM-11F' => $closed ? 'CLOSED' : 'HOLD',
            'ready_for_11G' => $closed,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @return array<string, int|string> */
    private function routingMetrics(): array
    {
        $mission = $this->binding->mission('bounded_review');
        $analytics = $this->binding->selectorVariant($mission, 'analytics');
        $cro = $this->binding->selectorVariant($mission, 'cro');
        $valid = ($mission['max_modes'] ?? null) === 1
            && ($mission['allow_delegation'] ?? null) === false
            && ($analytics['eligible_roles'] ?? null) === ['seo.expert.search_analytics_measurement']
            && ($cro['eligible_roles'] ?? null) === ['seo.expert.commercial_funnel_cro'];

        return [
            'bounded_review_probe_total' => 2,
            'bounded_review_probe_passed' => $valid ? 2 : 0,
            'portfolio_dual_mode_rule' => 'required_evidence_only',
            'unique_orchestrator_count' => count(glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []),
            'routing_bypass_count' => (int) (! $valid || count(glob(app_path('Services/SeoCouncil/*Orchestrator.php')) ?: []) !== 1),
        ];
    }

    /** @param array<string, mixed> $manifest @param list<string> $ids @return array<string, array<string, string>> */
    private function contractHashes(array $manifest, array $ids): array
    {
        $result = [];
        foreach ((array) ($manifest['contracts'] ?? []) as $contract) {
            if (is_array($contract) && in_array($contract['id'] ?? null, $ids, true)) {
                $result[(string) $contract['id']] = [
                    'version' => (string) $contract['version'],
                    'hash' => (string) $contract['hash'],
                ];
            }
        }

        return $result;
    }
}
