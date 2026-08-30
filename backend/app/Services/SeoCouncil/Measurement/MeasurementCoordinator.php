<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\MissionRequestData;

final class MeasurementCoordinator implements MeasurementRunner
{
    public function __construct(
        private readonly MeasurementEvidenceBundleLoader $bundles,
        private readonly MeasurementEvidenceContextBuilder $contexts,
        private readonly MeasurementContractValidator $contracts,
        private readonly SearchMeasurementMode $search,
        private readonly CommercialFunnelCROMode $cro,
        private readonly MeasurementActivityLedger $activity,
        private readonly MeasurementPrivacyScanner $privacy,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array
    {
        $this->activity->record('runner_calls');
        $modeId = match ($request->payload['review_domain'] ?? null) {
            'analytics' => 'search_measurement',
            'cro' => 'commercial_funnel_cro',
            default => null,
        };
        $expectedRole = match ($modeId) {
            'search_measurement' => 'seo.expert.search_analytics_measurement',
            'commercial_funnel_cro' => 'seo.expert.commercial_funnel_cro',
            default => null,
        };
        if ($modeId === null || ($handoff['target_role_id'] ?? null) !== $expectedRole) {
            return $this->modeOutput($handoff, 'HOLD', 'measurement_role_or_mode_hold');
        }

        $bundles = $this->bundles->loadForScope(
            $request->missionId(), $modeId, (string) $request->payload['family'],
            (string) $request->payload['locale'], $environment,
        );
        if ($bundles === []) {
            return $this->modeOutput($handoff, 'HOLD', 'measurement_evidence_unavailable');
        }
        $revisions = array_values(array_unique(array_column($bundles, 'authority_revision')));
        if (count($revisions) !== 1 || preg_match('/^[a-f0-9]{64}$/D', (string) $revisions[0]) !== 1) {
            return $this->modeOutput($handoff, 'HOLD', 'measurement_authority_conflict');
        }
        $measurementRequest = $this->contracts->sealRequest([
            'version' => 'seo.measurement_request.v2', 'mission_id' => $request->missionId(),
            'run_id' => (string) ($handoff['run_id'] ?? ''), 'role_id' => $expectedRole, 'mode_id' => $modeId,
            'page_family' => $request->payload['family'], 'locale' => $request->payload['locale'],
            'windows' => [7, 28, 90],
            'evidence_bundle_refs' => array_map(static fn (array $bundle): array => [
                'bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'],
                'bundle_hash' => $bundle['bundle_hash'], 'source_type' => $bundle['source_type'],
                'authority_type' => $bundle['authority_type'],
            ], $bundles),
            'authority_revision' => $revisions[0], 'execution_allowed' => false,
        ]);
        if (! $this->contracts->request($measurementRequest)
            || $this->privacy->request($measurementRequest)) {
            return $this->modeOutput($handoff, 'HOLD', 'measurement_request_contract_hold');
        }
        $context = $this->contexts->build($measurementRequest, $bundles);
        if (! $this->contracts->context($context) || ($context['status'] ?? null) !== 'READY') {
            return $this->modeOutput($handoff, 'HOLD', 'measurement_context_hold');
        }
        $output = $modeId === 'search_measurement'
            ? $this->search->review($context)
            : $this->cro->review($context);
        if (! $this->contracts->output($output)
            || $this->privacy->output($output)) {
            return $this->modeOutput($handoff, 'HOLD', 'measurement_output_contract_hold');
        }

        return $this->modeOutput(
            $handoff,
            ($output['status'] ?? null) === 'READY' ? 'PASS' : 'HOLD',
            ($output['status'] ?? null) === 'READY' ? 'measurement_ready' : (string) ($output['hold_reason'] ?? 'measurement_hold'),
        );
    }

    /** @param array<string, mixed> $handoff @return array<string, mixed> */
    private function modeOutput(array $handoff, string $status, string $summary): array
    {
        $activity = $this->activity->snapshot();
        $output = [
            'output_id' => $this->hasher->hash([$handoff['handoff_hash'] ?? null, $summary]),
            'handoff_hash' => $handoff['handoff_hash'] ?? null, 'role_id' => $handoff['target_role_id'] ?? null,
            'status' => $status, 'summary_code' => preg_replace('/[^a-z0-9._:-]+/', '_', strtolower($summary)) ?: 'measurement_hold',
            'execution_allowed' => false, 'model_calls' => $activity['model_calls'], 'tool_calls' => $activity['tool_calls'],
            'external_calls' => $activity['external_calls'],
            'write_count' => $activity['cms_writes'] + $activity['url_truth_writes']
                + $activity['search_writes'] + $activity['business_writes'],
        ];
        $output['output_hash'] = $this->hasher->hash($output);

        return $output;
    }
}
