<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisCloseoutBuilder;
use App\Services\SeoIntel\Detector\SeoDetectorRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Throwable;

final class MeasurementDependencySnapshotBuilder
{
    public const SNAPSHOT_VERSION = 'seo.measurement_dependency_snapshot.v1';

    private const WINDOWS = [7, 28, 90];

    public function __construct(
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly SeoEvidenceContractRegistry $evidence,
        private readonly PolicyGatewayRegistry $policy,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly TechnicalDiagnosisCloseoutBuilder $technicalDiagnosis,
        private readonly SeoDetectorRegistry $detectors,
        private readonly PageFamilyPolicyRegistry $pageFamilies,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function build(
        string $candidateSha,
        string $environment,
        string $currentProductionSha,
        string $pageFamily = 'tests',
        string $locale = 'en',
    ): array {
        $blockers = [];
        $registry = $this->roles->registry();
        $evidence = $this->evidence->manifest();
        $policy = $this->policy->registry();
        $binding = $this->binding->reference();
        $technical = $this->technicalDiagnosis->build($candidateSha, $environment);
        $expectedTechnicalState = match ($environment) {
            'production_runtime' => 'CLOSED',
            'staging_runtime' => 'STAGING_READY',
            default => 'CANDIDATE_READY',
        };
        if (($technical['closeout_state'] ?? null) !== $expectedTechnicalState) {
            $blockers[] = '11e_closeout_unavailable';
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $candidateSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $currentProductionSha) !== 1) {
            $blockers[] = 'production_revision_invalid';
        }
        if (! in_array($environment, ['ci_candidate', 'staging_runtime', 'production_runtime'], true)) {
            $blockers[] = 'environment_invalid';
        }
        if (! in_array($pageFamily, PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, true)) {
            $blockers[] = 'page_family_invalid';
        }
        if (! in_array($locale, ['en', 'zh-CN'], true)) {
            $blockers[] = 'locale_invalid';
        }
        if ($this->policy->dependencyStatus() !== 'READY' || $this->binding->status() !== 'READY') {
            $blockers[] = 'frozen_dependency_hold';
        }

        $refs = [
            '11a_registry' => ['version' => $registry['registry_version'], 'hash' => $registry['registry_hash']],
            '11b_evidence_privacy_retention' => ['version' => $evidence['manifest_version'], 'hash' => $evidence['manifest_hash']],
            '11c_policy_gateway' => ['version' => $policy['registry_version'], 'hash' => $policy['registry_hash']],
            '11d_binding_v2' => ['version' => $binding['version'], 'hash' => $binding['hash']],
            '11e_closeout' => ['version' => $technical['receipt_version'] ?? 'unavailable', 'hash' => $technical['receipt_hash'] ?? 'unavailable'],
            'gsc_data_quality_gate' => $this->fileRef('app/Services/SeoIntel/GscDataQualityGate.php', 'gsc-data-quality-gate.v1'),
            'gsc_readmodel' => $this->fileRef('docs/seo/generated/gsc-live-readmodel-consumption-contract.v1.json', 'gsc-live-readmodel-consumption.v1'),
            'search_to_result_funnel' => $this->fileRef('app/Services/SeoIntel/SearchToResultFunnelReadModel.php', 'seo-search-to-result-funnel.v1'),
            'funnel_taxonomy_mapping' => $this->fileRef('docs/seo/generated/analytics-funnel-event-taxonomy-01.v1.json', 'analytics-funnel-event-taxonomy-01.v1'),
            'funnel_aggregate_readmodel' => $this->fileRef('app/Services/SeoIntel/OpsDashboard/SeoConversionFunnelReadService.php', 'seo-conversion-funnel-readmodel.v1'),
        ];
        foreach ($refs as $id => $ref) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($ref['hash'] ?? '')) !== 1) {
                $blockers[] = $id.'_missing_or_drifted';
            }
        }
        try {
            $this->detectors->assertValid();
            $refs['detector_registry'] = ['version' => SeoDetectorRegistry::VERSION, 'hash' => $this->detectors->registryHash()];
        } catch (Throwable) {
            $refs['detector_registry'] = ['version' => SeoDetectorRegistry::VERSION, 'hash' => 'unavailable'];
            $blockers[] = 'detector_registry_missing_or_drifted';
        }
        try {
            $this->pageFamilies->assertValid();
            $refs['page_family_policy'] = ['version' => PageFamilyPolicyRegistry::VERSION, 'hash' => $this->pageFamilies->policyHash()];
        } catch (Throwable) {
            $refs['page_family_policy'] = ['version' => PageFamilyPolicyRegistry::VERSION, 'hash' => 'unavailable'];
            $blockers[] = 'page_family_policy_missing_or_drifted';
        }
        $urlTruth = $this->fileRef('app/Services/SeoIntel/QueryOwnerUrlTruthReadModel.php', 'seo-url-truth-readmodel.v1');
        $refs['url_truth'] = $urlTruth;
        if (preg_match('/^[a-f0-9]{64}$/D', $urlTruth['hash']) !== 1) {
            $blockers[] = 'url_truth_missing_or_drifted';
        }

        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);
        $snapshot = [
            'snapshot_id' => $this->hasher->hash([$candidateSha, $environment, $currentProductionSha, $refs]),
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'candidate_sha' => $candidateSha,
            'current_production_sha' => $currentProductionSha,
            'environment' => $environment,
            'dependencies' => $refs,
            'page_family' => $pageFamily,
            'locale' => $locale,
            'analysis_windows_days' => self::WINDOWS,
            'comparison_window_rule' => 'immediately_preceding_equal_length_window',
            'status' => $blockers === [] ? 'READY' : 'DEPENDENCY_HOLD',
            'blockers' => $blockers,
            'execution_allowed' => false,
        ];
        $snapshot['snapshot_hash'] = $this->hasher->hash($snapshot);

        return $snapshot;
    }

    /** @return array{version:string,hash:string} */
    private function fileRef(string $path, string $version): array
    {
        $hash = hash_file('sha256', base_path($path));

        return ['version' => $version, 'hash' => is_string($hash) ? $hash : 'unavailable'];
    }
}
