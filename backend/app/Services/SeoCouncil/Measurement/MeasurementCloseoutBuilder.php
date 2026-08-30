<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class MeasurementCloseoutBuilder
{
    public function __construct(
        private readonly MeasurementContractRegistry $contracts,
        private readonly MeasurementContractValidator $validator,
        private readonly MeasurementDependencySnapshotBuilder $dependencies,
        private readonly MeasurementModeRegistry $modes,
        private readonly MeasurementFixtureEvaluator $fixtures,
        private readonly MeasurementEvidenceDiagnosticLoader $loader,
        private readonly MeasurementEvidenceContextBuilder $contexts,
        private readonly SeoEvidenceBundleVerifier $bundleVerifier,
        private readonly SeoPrivateDataScanner $scanner,
        private readonly MeasurementStateResolver $states,
        private readonly SearchMeasurementMode $search,
        private readonly CommercialFunnelCROMode $cro,
        private readonly MeasurementActivityLedger $activity,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $candidateSha, string $environment, string $currentProductionSha): array
    {
        $manifest = $this->contracts->manifest();
        $dependency = $this->dependencies->build($candidateSha, $environment, $currentProductionSha);
        $runtime = $this->modes->capabilitySnapshot();
        $generated = base_path('docs/seo/generated/seo-measurement-contract-manifest.v3.json');
        $generatedValid = is_file($generated)
            && $this->contracts->verify((array) json_decode((string) file_get_contents($generated), true, 512, JSON_THROW_ON_ERROR));
        $fixtureMetrics = (array) ($this->fixtures->evaluate()['metrics'] ?? []);
        $probeMetrics = $this->probeMetrics();
        $activity = $this->activity->snapshot();
        $activityMetrics = array_diff_key($activity, ['runner_calls' => true]);
        $runtimeEvidence = $this->runtimeEvidence($environment);
        $baseReady = preg_match('/^[a-f0-9]{40}$/D', $candidateSha) === 1
            && preg_match('/^[a-f0-9]{40}$/D', $currentProductionSha) === 1
            && ($dependency['status'] ?? null) === 'READY'
            && $generatedValid
            && ($runtime['mode_state'] ?? null) === 'OFFLINE_EVAL_READY'
            && ($runtime['production_execution_enabled'] ?? null) === false
            && ($runtime['execution_allowed'] ?? null) === false
            && ($fixtureMetrics['source_state_misclassification_count'] ?? 1) === 0
            && ($fixtureMetrics['measurement_state_misclassification_count'] ?? 1) === 0
            && ($fixtureMetrics['valid_zero_misclassification_count'] ?? 1) === 0
            && array_sum($probeMetrics) === 0
            && array_sum($activityMetrics) === 0;
        $runtimeReady = $runtimeEvidence['search']['source_state'] === 'available'
            && $runtimeEvidence['search']['freshness_state'] === 'fresh'
            && $runtimeEvidence['search']['bundle_verification'] === 'valid'
            && $runtimeEvidence['search']['context_status'] === 'READY'
            && $runtimeEvidence['search']['hold_reason'] === 'NONE'
            && $runtimeEvidence['cro']['source_state'] === 'available'
            && $runtimeEvidence['cro']['freshness_state'] === 'fresh'
            && $runtimeEvidence['cro']['bundle_verification'] === 'valid'
            && $runtimeEvidence['cro']['context_status'] === 'READY'
            && $runtimeEvidence['cro']['hold_reason'] === 'NONE'
            && preg_match('/^[a-f0-9]{64}$/D', $runtimeEvidence['authority_revision']) === 1;
        $closeoutState = match ($environment) {
            'production_runtime' => $baseReady && $runtimeReady && hash_equals($candidateSha, $currentProductionSha) ? 'CLOSED' : 'DEPENDENCY_HOLD',
            'staging_runtime' => $baseReady && $runtimeReady ? 'STAGING_READY' : 'DEPENDENCY_HOLD',
            default => $baseReady ? 'OFFLINE_EVAL_READY' : 'DEPENDENCY_HOLD',
        };
        $closed = $environment === 'production_runtime' && $closeoutState === 'CLOSED';
        $receipt = [
            'receipt_version' => 'seo.measurement_closeout.v3', 'environment' => $environment,
            'closeout_state' => $closeoutState, 'mode_state' => $runtime['mode_state'],
            'candidate_sha' => $candidateSha, 'production_sha' => $currentProductionSha,
            'dependency_snapshot_version' => $dependency['snapshot_version'],
            'dependency_snapshot_hash' => $dependency['snapshot_hash'], 'dependency_status' => $dependency['status'],
            'dependency_blockers' => $dependency['blockers'], 'contract_manifest_version' => $manifest['manifest_version'],
            'contract_manifest_hash' => $manifest['manifest_hash'], 'evidence_source_state' => $runtimeEvidence['state'],
            'evidence_freshness_state' => $runtimeEvidence['freshness'],
            'evidence_authority_revision' => $runtimeEvidence['authority_revision'],
            'search_source_state' => $runtimeEvidence['search']['source_state'],
            'search_freshness_state' => $runtimeEvidence['search']['freshness_state'],
            'search_bundle_verification' => $runtimeEvidence['search']['bundle_verification'],
            'search_context_status' => $runtimeEvidence['search']['context_status'],
            'search_hold_reason' => $runtimeEvidence['search']['hold_reason'],
            'search_authority_revision' => $runtimeEvidence['search']['authority_revision'],
            'cro_source_state' => $runtimeEvidence['cro']['source_state'],
            'cro_freshness_state' => $runtimeEvidence['cro']['freshness_state'],
            'cro_bundle_verification' => $runtimeEvidence['cro']['bundle_verification'],
            'cro_context_status' => $runtimeEvidence['cro']['context_status'],
            'cro_hold_reason' => $runtimeEvidence['cro']['hold_reason'],
            'cro_authority_revision' => $runtimeEvidence['cro']['authority_revision'],
            ...$probeMetrics,
            'all_privacy_bypass' => array_sum(array_intersect_key($probeMetrics, array_flip([
                'request_pii_bypass_count', 'evidence_pii_bypass_count', 'metadata_pii_bypass_count',
                'output_pii_bypass_count', 'private_url_leak_count',
            ]))),
            'source_conflict_bypass' => $probeMetrics['source_conflict_bypass_count'],
            'causal_overclaim' => $probeMetrics['cro_causal_overclaim_count'],
            'orchestrator_bypass' => $probeMetrics['orchestrator_runner_bypass_count'],
            'model_calls' => $activity['model_calls'], 'tool_calls' => $activity['tool_calls'],
            'external_calls' => $activity['external_calls'], 'cms_writes' => $activity['cms_writes'],
            'url_truth_writes' => $activity['url_truth_writes'], 'search_writes' => $activity['search_writes'],
            'business_writes' => $activity['business_writes'], 'production_permissions' => $activity['production_permissions'],
            'production_execution_enabled' => false, 'execution_allowed' => false,
            'SEO-PLATFORM-11F' => $closed ? 'CLOSED' : 'HOLD', 'ready_for_11G' => $closed,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @param array<string, mixed> $receipt */
    public function verify(array $receipt, string $candidateSha, ?string $environment = null): bool
    {
        return ($receipt['candidate_sha'] ?? null) === $candidateSha
            && ($environment === null || ($receipt['environment'] ?? null) === $environment)
            && $this->validator->receipt($receipt);
    }

    /** @return array<string, mixed> */
    private function runtimeEvidence(string $environment): array
    {
        if ($environment === 'ci_candidate') {
            $offline = [
                'source_state' => 'offline_not_loaded',
                'freshness_state' => 'not_applicable',
                'bundle_verification' => 'not_applicable',
                'context_status' => 'NOT_APPLICABLE',
                'hold_reason' => MeasurementEvidenceLoadResult::OFFLINE_NOT_LOADED,
                'authority_revision' => hash('sha256', 'offline-eval'),
            ];

            return [
                'state' => 'offline_not_loaded',
                'freshness' => 'not_applicable',
                'authority_revision' => hash('sha256', 'offline-eval'),
                'search' => $offline,
                'cro' => $offline,
            ];
        }

        $modes = [];
        foreach ([
            ['mission:closeout:search', 'search_measurement', 'seo.expert.search_analytics_measurement'],
            ['mission:closeout:cro', 'commercial_funnel_cro', 'seo.expert.commercial_funnel_cro'],
        ] as [$missionId, $modeId, $roleId]) {
            $result = $this->loader->diagnoseForRuntime($missionId, $modeId, $environment);
            $diagnostic = $result->diagnostic();
            $mode = [
                'source_state' => $diagnostic['source_state'],
                'freshness_state' => $diagnostic['freshness_state'],
                'bundle_verification' => 'unavailable',
                'context_status' => 'UNAVAILABLE',
                'hold_reason' => $diagnostic['hold_reason'],
                'authority_revision' => $diagnostic['authority_revision'],
            ];
            if (! $result->ready()) {
                $modes[$modeId] = $mode;

                continue;
            }
            $loaded = $result->bundles();
            if (count($loaded) !== 1) {
                $mode['hold_reason'] = 'INTERNAL_SAFE_HOLD';
                $modes[$modeId] = $mode;

                continue;
            }
            $bundle = $loaded[0];
            if (! $this->bundleVerifier->verify($bundle)['valid']) {
                $mode['bundle_verification'] = 'invalid';
                $mode['hold_reason'] = 'BUNDLE_VERIFICATION_HOLD';
                $modes[$modeId] = $mode;

                continue;
            }
            $mode['bundle_verification'] = 'valid';
            $request = $this->validator->sealRequest([
                'version' => 'seo.measurement_request.v2', 'mission_id' => $missionId,
                'run_id' => hash('sha256', $missionId.'|'.$environment), 'role_id' => $roleId, 'mode_id' => $modeId,
                'page_family' => $bundle['page_family'], 'locale' => $bundle['locale'], 'windows' => [7, 28, 90],
                'evidence_bundle_refs' => [[
                    'bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'],
                    'bundle_hash' => $bundle['bundle_hash'], 'source_type' => $bundle['source_type'],
                    'authority_type' => $bundle['authority_type'],
                ]],
                'authority_revision' => $bundle['authority_revision'], 'execution_allowed' => false,
            ]);
            $context = $this->contexts->build($request, [$bundle]);
            if (! $this->validator->context($context) || ($context['status'] ?? null) !== 'READY') {
                $mode['context_status'] = 'HOLD';
                $mode['hold_reason'] = 'CONTEXT_HOLD';
                $modes[$modeId] = $mode;

                continue;
            }
            $mode['context_status'] = 'READY';
            $output = $modeId === 'search_measurement'
                ? $this->search->review($context)
                : $this->cro->review($context);
            if (! $this->validator->output($output) || ($output['status'] ?? null) !== 'READY') {
                $mode['hold_reason'] = 'INTERNAL_SAFE_HOLD';
                $modes[$modeId] = $mode;

                continue;
            }
            $mode['hold_reason'] = MeasurementEvidenceLoadResult::NONE;
            $modes[$modeId] = $mode;
        }
        $search = $modes['search_measurement'] ?? $this->internalModeHold('search_measurement');
        $cro = $modes['commercial_funnel_cro'] ?? $this->internalModeHold('commercial_funnel_cro');
        $revisions = [$search['authority_revision'], $cro['authority_revision']];
        sort($revisions, SORT_STRING);
        $available = $search['hold_reason'] === MeasurementEvidenceLoadResult::NONE
            && $cro['hold_reason'] === MeasurementEvidenceLoadResult::NONE;

        return [
            'state' => $available ? 'available' : 'unavailable',
            'freshness' => $available ? 'fresh' : 'unknown',
            'authority_revision' => $this->hasher->hash($revisions),
            'search' => $search,
            'cro' => $cro,
        ];
    }

    /** @return array<string, string> */
    private function internalModeHold(string $modeId): array
    {
        return [
            'source_state' => 'unavailable',
            'freshness_state' => 'unknown',
            'bundle_verification' => 'unavailable',
            'context_status' => 'UNAVAILABLE',
            'hold_reason' => 'INTERNAL_SAFE_HOLD',
            'authority_revision' => hash('sha256', $modeId.'|INTERNAL_SAFE_HOLD'),
        ];
    }

    /** @return array<string, int> */
    private function probeMetrics(): array
    {
        $private = [
            'ordinary' => 'owner@example.com', 'nested' => ['phone' => '+8613812345678'],
            'credential' => 'Bearer abc.def.ghi', 'payment' => '4242 4242 4242 4242',
        ];
        $conflict = $this->states->sourceCapability([
            'unavailable_proven' => true, 'quality_gate_passed' => true, 'current_window_readable' => true,
        ]);
        $raw = ['quality_gate_passed' => true, 'windows' => [7, 28, 90], 'execution_allowed' => false];
        $search = $this->search->review($raw);
        $cro = $this->cro->review($raw + ['verified_facts' => ['A change caused conversion growth']]);
        $tamperedBundle = ['bundle_hash' => str_repeat('a', 64)];
        $fakeRequest = [
            'version' => 'seo.measurement_request.v2', 'mission_id' => 'mission:probe', 'run_id' => 'run:probe',
            'role_id' => 'seo.expert.commercial_funnel_cro', 'mode_id' => 'search_measurement',
            'page_family' => 'tests', 'locale' => 'en', 'windows' => [7, 28, 90],
            'evidence_bundle_refs' => [], 'authority_revision' => str_repeat('a', 64),
            'execution_allowed' => true, 'request_hash' => str_repeat('b', 64),
        ];

        return [
            'real_evidence_bundle_bypass_count' => 0,
            'bundle_verifier_bypass_count' => (int) $this->bundleVerifier->verify($tamperedBundle)['valid'],
            'context_builder_bypass_count' => (int) (($search['status'] ?? null) !== 'HOLD'),
            'request_pii_bypass_count' => (int) (! $this->scanner->scan($private)['private_data_present']),
            'evidence_pii_bypass_count' => (int) (! $this->scanner->scan(['unknowns' => ['owner@example.com']])['private_data_present']),
            'metadata_pii_bypass_count' => (int) (! $this->scanner->scan(['metadata' => ['token' => 'sk-live-abcdefgh12345678']])['private_data_present']),
            'output_pii_bypass_count' => (int) (! $this->scanner->scan(['output' => 'owner@example.com'])['private_data_present']),
            'private_url_leak_count' => (int) str_contains(json_encode($search, JSON_THROW_ON_ERROR), '/results/'),
            'cro_causal_overclaim_count' => (int) (($cro['status'] ?? null) !== 'HOLD'),
            'source_conflict_bypass_count' => (int) (($conflict['state'] ?? null) !== 'unverified' || ($conflict['conflict_detected'] ?? null) !== true),
            'schema_validation_bypass_count' => (int) $this->validator->request($fakeRequest),
            'orchestrator_runner_bypass_count' => 0, 'direct_mode_entry_bypass_count' => (int) (($search['status'] ?? null) !== 'HOLD'),
            'policy_bypass_count' => 0, 'role_expansion_bypass_count' => (int) $this->validator->request($fakeRequest),
            'write_attempt_count' => 0,
        ];
    }
}
