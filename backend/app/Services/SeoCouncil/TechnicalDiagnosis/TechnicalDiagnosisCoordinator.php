<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use Carbon\CarbonImmutable;

final class TechnicalDiagnosisCoordinator implements TechnicalDiagnosisRunner
{
    public function __construct(
        private readonly TechnicalDiagnosisDependencyBindingSource $evidence,
        private readonly TechnicalDiagnosisEvidenceBundleLoader $bundles,
        private readonly TechnicalDiagnosisDependencySnapshotBuilder $dependencies,
        private readonly TechnicalDiagnosisEvidenceContextBuilder $contexts,
        private readonly TechnicalDiagnosisContractValidator $contracts,
        private readonly TechnicalDiagnosisEngine $engine,
        private readonly TechnicalDiagnosisActivityLedger $activity,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array
    {
        $this->activity->record('runner_calls');
        $bundles = $this->bundles->load($request);
        if ($bundles === []) {
            return $this->modeOutput($handoff, 'HOLD', 'evidence_bundle_body_unavailable');
        }
        $binding = $this->evidence->technicalDiagnosisBinding($releaseSha);
        $dependency = $this->dependencies->build($releaseSha, $environment, [
            'dependency_mode' => $environment === 'ci_candidate' ? 'OFFLINE_FIXTURE' : 'RUNTIME_READ_ONLY',
            'observed_active_sha' => $environment === 'ci_candidate' ? null : $releaseSha,
            ...$binding,
            'page_family' => $request->payload['family'],
            'locale' => $request->payload['locale'],
        ]);
        $technicalRequest = $this->request($request, $handoff, $bundles, $dependency);
        $context = $this->contexts->build($technicalRequest, $bundles, $dependency);
        $output = $this->engine->diagnose($technicalRequest, $context);
        if (! $this->contracts->output($output)) {
            return $this->modeOutput($handoff, 'HOLD', 'technical_output_contract_hold');
        }

        return $this->modeOutput(
            $handoff,
            ($output['status'] ?? null) === 'READY' ? 'PASS' : 'HOLD',
            strtolower((string) (($output['hold_reason'] ?? null) ?: 'technical_diagnosis_ready')),
        );
    }

    /** @param list<array<string, mixed>> $bundles @param array<string, mixed> $dependency @return array<string, mixed> */
    private function request(MissionRequestData $mission, array $handoff, array $bundles, array $dependency): array
    {
        $refs = array_map(static fn (array $bundle): array => [
            'bundle_id' => $bundle['bundle_id'],
            'bundle_version' => $bundle['bundle_version'],
            'bundle_hash' => $bundle['bundle_hash'],
            'source_type' => $bundle['source_type'],
            'authority_type' => $bundle['authority_type'],
        ], $bundles);
        $request = [
            'diagnosis_id' => 'diagnosis:'.$mission->missionId(),
            'diagnosis_version' => 2,
            'mission_id' => $mission->missionId(),
            'run_id' => (string) ($handoff['run_id'] ?? ''),
            'role_id' => (string) ($handoff['target_role_id'] ?? ''),
            'mode_id' => 'technical_search_diagnosis',
            'page_family' => $mission->payload['family'],
            'locale' => $mission->payload['locale'],
            'evidence_bundle_refs' => $refs,
            'dependency_snapshot_ref' => $this->dependencies->requestReference($dependency),
            'detector_registry_ref' => $dependency['detector_registry_ref'],
            'url_truth_revision' => $dependency['url_truth_revision'],
            'runtime_revision' => $dependency['runtime_evidence_revision'],
            'deployment_revision' => $dependency['deployment_revision'],
            'authority_revision' => $dependency['authority_revision'],
            'requested_scope' => [
                'sanitized_public_refs' => [], 'max_urls' => 32,
                'page_family' => $mission->payload['family'], 'locale' => $mission->payload['locale'],
            ],
            'requested_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'execution_allowed' => false,
            'allow_delegation' => false,
        ];

        return $this->contracts->sealRequest($request);
    }

    /** @param array<string, mixed> $handoff @return array<string, mixed> */
    private function modeOutput(array $handoff, string $status, string $summary): array
    {
        $activity = $this->activity->snapshot();
        $writeCount = array_sum(array_intersect_key($activity, array_flip([
            'business_writes', 'cms_writes', 'url_truth_writes', 'canonical_writes',
            'robots_writes', 'feed_writes', 'search_writes',
        ])));
        $output = [
            'output_id' => $this->hasher->hash([$handoff['handoff_hash'] ?? null, $summary]),
            'handoff_hash' => $handoff['handoff_hash'] ?? null,
            'role_id' => $handoff['target_role_id'] ?? null,
            'status' => $status,
            'summary_code' => preg_replace('/[^a-z0-9._:-]+/', '_', $summary) ?: 'technical_diagnosis_hold',
            'execution_allowed' => false,
            'model_calls' => $activity['model_calls'],
            'tool_calls' => $activity['tool_calls'],
            'external_calls' => $activity['external_calls'],
            'write_count' => $writeCount,
        ];
        $output['output_hash'] = $this->hasher->hash($output);

        return $output;
    }
}
