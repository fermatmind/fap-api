<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleStore;
use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceDiagnosticLoader;
use Throwable;

final class CompetitiveEvidenceIngestionService
{
    public function __construct(
        private readonly CompetitiveGatewayReader $gateway,
        private readonly CompetitiveSourceRegistry $registry,
        private readonly CompetitiveEvidenceAnalyzer $analyzer,
        private readonly MeasurementEvidenceDiagnosticLoader $measurement,
        private readonly SeoEvidenceBundleFactory $bundles,
        private readonly SeoEvidenceBundleVerifier $verifier,
        private readonly SeoEvidenceBundleStore $store,
        private readonly SeoEvidenceCanonicalHasher $hasher,
    ) {}

    /**
     * @param  array<string, mixed>  $cohort
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    public function ingest(array $cohort, array $sources, string $environment, string $releaseSha, bool $write): array
    {
        $competitorCount = count(array_filter(
            $sources,
            static fn (array $source): bool => ($source['source_class'] ?? null) === 'competitor_public',
        ));
        if (($cohort['collection_state'] ?? null) !== 'approved'
            || $competitorCount < (int) ($cohort['minimum_competitor_sources'] ?? 2)) {
            return $this->hold('SOURCE_POLICY_HOLD', 0);
        }

        $semantic = $this->registry->semanticRegistry();
        $projections = [];
        $sourcePolicies = [];
        $externalReads = 0;
        foreach ($sources as $source) {
            $sourceId = (string) ($source['source_id'] ?? '');
            $result = $this->gateway->fetch($sourceId, (string) ($source['url'] ?? ''), [
                'cohort_id' => (string) $cohort['cohort_id'],
                'source_class' => (string) $source['source_class'],
                'page_family' => (string) $source['page_family'],
                'locale' => (string) $source['locale'],
            ], $semantic);
            $externalReads += max(0, (int) data_get($result, 'dependency_ingestion.external_reads', 0));
            if (($result['status'] ?? null) !== 'ready' || ! is_array($result['projection'] ?? null)) {
                return $this->hold((string) ($result['safe_error_code'] ?? 'SOURCE_POLICY_HOLD'), $externalReads);
            }
            $projections[] = $result['projection'];
            $sourcePolicies[] = [
                'source_id' => $sourceId,
                'freshness_state' => 'fresh',
            ];
        }

        try {
            $runtimeEnvironment = $environment === 'production' ? 'production_runtime' : 'staging_runtime';
            $measurement = $this->measurement->diagnoseForScope(
                'competitive:'.$releaseSha,
                'search_measurement',
                (string) $cohort['page_family'],
                'en',
                $runtimeEnvironment,
            );
            if (! $measurement->ready() || count($measurement->bundles()) !== 1) {
                return $this->hold((string) $measurement->diagnostic()['hold_reason'], $externalReads);
            }
            $measurementBundle = $measurement->bundles()[0];
            $authority = $this->authoritySnapshot($projections);
            $measurementInput = $this->measurementInput($measurementBundle);
            $output = $this->analyzer->analyze([
                'page_family' => (string) $cohort['page_family'],
                'locale' => 'en',
                'projections' => $projections,
                'source_policies' => $sourcePolicies,
                'authority' => $authority,
                'measurement' => $measurementInput,
                'dependency_ingestion' => ['external_reads' => $externalReads],
            ]);
            if (($output['status'] ?? null) !== 'READY') {
                return $this->hold((string) ($output['hold_reason'] ?? 'COMPETITIVE_EVIDENCE_HOLD'), $externalReads);
            }

            $bundle = $this->bundles->create([
                'bundle_id' => 'competitive:'.$environment.':'.$releaseSha,
                'bundle_version' => 1,
                'mission_id' => 'competitive:ingestion:'.$releaseSha,
                'source_type' => 'external_gateway',
                'source_ref' => $this->hasher->hash([$environment, $releaseSha, $cohort['cohort_id']]),
                'authority_type' => 'competitive_structural_projection',
                'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'evidence_state' => 'verified',
                'freshness_state' => 'fresh',
                'source_capability_state' => 'available',
                'retention_class' => 'external_structured_fact',
                'page_family' => (string) $cohort['page_family'],
                'locale' => 'en',
                'authority_revision' => (string) $authority['source_hash'],
                'source_license_class' => 'public_fact_permitted',
                'data_usage_purpose' => 'competitive_evidence',
                'egress_decision' => 'allowed_by_gateway',
                'lineage_refs' => [(string) $measurementBundle['bundle_hash']],
                'payload' => [
                    'environment' => $environment,
                    'release_sha' => $releaseSha,
                    'cohort_id' => (string) $cohort['cohort_id'],
                    'projections' => $projections,
                    'competitive_output' => $output,
                    '11i_handoff' => $output['11i_handoff'],
                    'dependency_ingestion' => ['external_reads' => $externalReads],
                ],
            ]);
            if (! $this->verifier->verify($bundle)['valid']) {
                return $this->hold('BUNDLE_VERIFICATION_HOLD', $externalReads);
            }
            if ($write) {
                $this->store->create($bundle);
            }

            return [
                'status' => 'READY',
                'hold_reason' => 'NONE',
                'bundle_verification' => 'valid',
                'bundle' => $bundle,
                'competitive_output' => $output,
                'write_performed' => $write,
                'dependency_ingestion' => ['external_reads' => $externalReads],
            ];
        } catch (Throwable) {
            return $this->hold('COMPETITIVE_INGESTION_INTERNAL_HOLD', $externalReads);
        }
    }

    /** @param list<array<string, mixed>> $projections @return array<string, mixed> */
    private function authoritySnapshot(array $projections): array
    {
        $firstParty = array_values(array_filter(
            $projections,
            static fn (array $projection): bool => ($projection['source_class'] ?? null) === 'fermatmind_public',
        ));
        $modules = [];
        $relations = [];
        foreach ($firstParty as $projection) {
            foreach ((array) data_get($projection, 'structure.modules', []) as $module) {
                if (is_array($module) && is_string($module['module_type'] ?? null)) {
                    $modules[] = $module['module_type'];
                }
            }
            foreach ((array) data_get($projection, 'structure.entity_relations', []) as $relation) {
                if (is_array($relation)) {
                    $relations[] = $relation;
                }
            }
        }
        $manifest = base_path('content_assets/personality_public/current/manifest.json');
        $sourceHash = is_file($manifest) ? hash_file('sha256', $manifest) : false;

        return [
            'modules' => array_values(array_unique($modules)),
            'entity_relations' => $relations,
            'information_ids' => [],
            'owner_gap_confirmed' => false,
            'source_hash' => is_string($sourceHash) ? $sourceHash : hash('sha256', 'personality-current-unavailable'),
            'freshness_state' => is_string($sourceHash) ? 'fresh' : 'unknown',
            'conflict' => false,
        ];
    }

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    private function measurementInput(array $bundle): array
    {
        $demand = [];
        foreach ((array) data_get($bundle, 'payload.windows', []) as $window) {
            $metrics = is_array($window) ? (array) ($window['metrics'] ?? []) : [];
            $demand[] = array_sum(array_map('intval', $metrics)) > 0;
        }

        return [
            'source_hash' => (string) ($bundle['bundle_hash'] ?? ''),
            'freshness_state' => (string) ($bundle['freshness_state'] ?? 'unknown'),
            'demand_windows' => $demand,
            'conflict' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function hold(string $reason, int $externalReads): array
    {
        return [
            'status' => 'HOLD',
            'hold_reason' => preg_match('/^[A-Z0-9_]{3,64}$/D', $reason) === 1 ? $reason : 'COMPETITIVE_EVIDENCE_HOLD',
            'bundle_verification' => 'missing',
            'write_performed' => false,
            'dependency_ingestion' => ['external_reads' => max(0, min(64, $externalReads))],
        ];
    }
}
