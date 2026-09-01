<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoCouncil\Measurement\CommercialFunnelCROMode;
use App\Services\SeoCouncil\Measurement\MeasurementContractValidator;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceContextBuilder;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceDiagnosticLoader;
use App\Services\SeoCouncil\Measurement\SearchMeasurementMode;

final class CompetitiveMeasurementReadiness
{
    public function __construct(
        private readonly MeasurementEvidenceDiagnosticLoader $loader,
        private readonly SeoEvidenceBundleVerifier $verifier,
        private readonly MeasurementContractValidator $contracts,
        private readonly MeasurementEvidenceContextBuilder $contexts,
        private readonly SearchMeasurementMode $search,
        private readonly CommercialFunnelCROMode $cro,
        private readonly SeoEvidenceCanonicalHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function assess(string $releaseSha, string $pageFamily, string $environment): array
    {
        $runtimeEnvironment = $environment === 'production' ? 'production_runtime' : 'staging_runtime';
        $modes = [];
        $bundles = [];
        foreach ([
            'search_measurement' => 'seo.expert.search_analytics_measurement',
            'commercial_funnel_cro' => 'seo.expert.commercial_funnel_cro',
        ] as $modeId => $roleId) {
            $missionId = 'competitive:'.$releaseSha.':'.$modeId;
            $result = $this->loader->diagnoseForScope($missionId, $modeId, $pageFamily, 'en', $runtimeEnvironment);
            $diagnostic = $result->diagnostic();
            $mode = [
                'source_state' => (string) ($diagnostic['source_state'] ?? 'unavailable'),
                'freshness_state' => (string) ($diagnostic['freshness_state'] ?? 'unknown'),
                'bundle_verification' => 'missing',
                'context_status' => 'HOLD',
                'hold_reason' => (string) ($diagnostic['hold_reason'] ?? 'MEASUREMENT_HOLD'),
                'bundle_hash' => hash('sha256', $modeId.'|missing'),
            ];
            if (! $result->ready() || count($result->bundles()) !== 1) {
                $modes[$modeId] = $mode;

                continue;
            }
            $bundle = $result->bundles()[0];
            $mode['bundle_hash'] = (string) ($bundle['bundle_hash'] ?? $mode['bundle_hash']);
            if (! $this->verifier->verify($bundle)['valid']) {
                $mode['bundle_verification'] = 'invalid';
                $mode['hold_reason'] = 'BUNDLE_VERIFICATION_HOLD';
                $modes[$modeId] = $mode;

                continue;
            }
            $mode['bundle_verification'] = 'valid';
            $request = $this->contracts->sealRequest([
                'version' => 'seo.measurement_request.v2',
                'mission_id' => $missionId,
                'run_id' => hash('sha256', $missionId.'|'.$runtimeEnvironment),
                'role_id' => $roleId,
                'mode_id' => $modeId,
                'page_family' => $bundle['page_family'],
                'locale' => $bundle['locale'],
                'windows' => [7, 28, 90],
                'evidence_bundle_refs' => [[
                    'bundle_id' => $bundle['bundle_id'],
                    'bundle_version' => $bundle['bundle_version'],
                    'bundle_hash' => $bundle['bundle_hash'],
                    'source_type' => $bundle['source_type'],
                    'authority_type' => $bundle['authority_type'],
                ]],
                'authority_revision' => $bundle['authority_revision'],
                'execution_allowed' => false,
            ]);
            $context = $this->contexts->build($request, [$bundle]);
            $output = $modeId === 'search_measurement' ? $this->search->review($context) : $this->cro->review($context);
            if (! $this->contracts->context($context) || ($context['status'] ?? null) !== 'READY'
                || ! $this->contracts->output($output) || ($output['status'] ?? null) !== 'READY') {
                $mode['hold_reason'] = 'MEASUREMENT_CONTEXT_HOLD';
                $modes[$modeId] = $mode;

                continue;
            }
            $mode['context_status'] = 'READY';
            $mode['hold_reason'] = 'NONE';
            $modes[$modeId] = $mode;
            $bundles[$modeId] = $bundle;
        }
        $search = $modes['search_measurement'];
        $cro = $modes['commercial_funnel_cro'];
        $ready = $this->readyMode($search) && $this->readyMode($cro);
        $hashes = array_values(array_map(static fn (array $mode): string => (string) $mode['bundle_hash'], $modes));
        sort($hashes, SORT_STRING);

        return [
            'status' => $ready ? 'READY' : 'HOLD',
            'hold_reason' => $ready ? 'NONE' : $this->firstHold($search, $cro),
            'search_measurement' => $search,
            'cro_measurement' => $cro,
            'measurement_bundle_set_hash' => $this->hasher->hash($hashes),
            'bundles' => $bundles,
        ];
    }

    /** @param array<string, mixed> $mode */
    private function readyMode(array $mode): bool
    {
        return ($mode['source_state'] ?? null) === 'available'
            && ($mode['freshness_state'] ?? null) === 'fresh'
            && ($mode['bundle_verification'] ?? null) === 'valid'
            && ($mode['context_status'] ?? null) === 'READY'
            && ($mode['hold_reason'] ?? null) === 'NONE';
    }

    /** @param array<string, mixed> $search @param array<string, mixed> $cro */
    private function firstHold(array $search, array $cro): string
    {
        foreach ([$search, $cro] as $mode) {
            $reason = (string) ($mode['hold_reason'] ?? 'MEASUREMENT_HOLD');
            if ($reason !== 'NONE') {
                return preg_match('/^[A-Z0-9_]{3,64}$/D', $reason) === 1 ? $reason : 'MEASUREMENT_HOLD';
            }
        }

        return 'MEASUREMENT_HOLD';
    }
}
