<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Sources;

use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisDependencyBindingSource;
use App\Services\SeoIntel\Decision\SeoDecisionCardReadService;
use App\Services\SeoIntel\Decision\SeoWeeklyDecisionCloseoutService;
use App\Services\SeoIntel\Ledger\SeoLedgerProductionCloseoutService;
use App\Services\SeoIntel\OpsDashboard\ContentLifecycleReadService;
use App\Services\SeoIntel\OpsDashboard\GscProductionCloseoutReadService;
use App\Services\SeoIntel\OpsDashboard\SeoTechnicalHealthReadService;
use App\Services\SeoIntel\Runtime\ProductionCalibrationCloseoutService;
use App\Services\SeoIntel\Runtime\ScheduledRuntimeProbeReceiptService;
use App\Services\SeoIntel\Sources\ProductionDetectorFoundationEvidenceSource;
use Carbon\CarbonImmutable;
use Throwable;

/** Converts existing production closeouts into metadata-only dependency states. */
final class SeoPlatformDependencyEvidenceAdapter implements TechnicalDiagnosisDependencyBindingSource
{
    public function __construct(
        private readonly GscProductionCloseoutReadService $gsc,
        private readonly ScheduledRuntimeProbeReceiptService $runtimeReceipts,
        private readonly ProductionCalibrationCloseoutService $runtimeCloseout,
        private readonly SeoLedgerProductionCloseoutService $ledgerCloseout,
        private readonly SeoWeeklyDecisionCloseoutService $decisionCloseout,
        private readonly SeoDecisionCardReadService $decisionRead,
        private readonly ContentLifecycleReadService $lifecycleRead,
        private readonly ProductionDetectorFoundationEvidenceSource $detectorFoundation,
        private readonly SeoTechnicalHealthReadService $technicalHealth,
    ) {}

    /** @return list<array<string, mixed>> */
    public function snapshot(string $releaseSha): array
    {
        return [
            $this->safely('seo-platform-06', fn (): array => $this->gscEvidence()),
            $this->safely('seo-platform-07', fn (): array => $this->runtimeEvidence($releaseSha)),
            $this->safely('seo-platform-08', fn (): array => $this->ledgerEvidence($releaseSha)),
            $this->safely('seo-platform-09', fn (): array => $this->decisionEvidence($releaseSha)),
            $this->safely('seo-platform-10', fn (): array => $this->lifecycleEvidence()),
            [
                'dependency_id' => 'seo-platform-11a',
                'status' => 'verified',
                'source_state' => 'available',
                'private_boundary_proven' => true,
                'evidence_code' => 'FROZEN_AUTHORITY_VERIFIED',
            ],
        ];
    }

    /** @return array{url_truth_revision:string,url_truth_projection_hash:string} */
    public function urlTruthBinding(): array
    {
        try {
            $snapshot = $this->detectorFoundation->snapshot(CarbonImmutable::now('UTC'));
            $revision = (string) data_get($snapshot, 'jobs.0.evidence.url_truth_revision', '');
            $count = data_get($snapshot, 'metadata.url_truth.current_public_count');
            if (preg_match('/^url-truth-set-v1:[a-f0-9]{32}$/', $revision) !== 1 || ! is_int($count)) {
                throw new \RuntimeException('URL_TRUTH_UNAVAILABLE');
            }

            return [
                'url_truth_revision' => $revision,
                'url_truth_projection_hash' => hash('sha256', $revision.'|'.$count),
            ];
        } catch (Throwable) {
            return [
                'url_truth_revision' => 'unavailable',
                'url_truth_projection_hash' => 'unavailable',
            ];
        }
    }

    /** @return array<string, mixed> */
    public function technicalDiagnosisBinding(string $releaseSha): array
    {
        try {
            $foundation = $this->detectorFoundation->snapshot(CarbonImmutable::now('UTC'));
            $urlTruthRevision = (string) data_get($foundation, 'jobs.0.evidence.url_truth_revision', '');
            $authorityRevision = (string) data_get($foundation, 'jobs.0.evidence.authority_revision', '');
            $publicCount = data_get($foundation, 'metadata.url_truth.current_public_count');
            $runtime = $this->technicalHealth->read();
            $runtimeHash = hash('sha256', json_encode($this->canonicalize($runtime), JSON_THROW_ON_ERROR));
            $runtimeVersion = (string) ($runtime['schema_version'] ?? '');
            $valid = preg_match('/^[a-f0-9]{40}$/D', $releaseSha) === 1
                && preg_match('/^url-truth-set-v1:[a-f0-9]{32}$/D', $urlTruthRevision) === 1
                && preg_match('/^authority-set-v1:[a-f0-9]{32}$/D', $authorityRevision) === 1
                && is_int($publicCount)
                && $runtimeVersion === 'seo-platform-07-technical-health.v1'
                && data_get($runtime, 'boundaries.read_only') === true
                && data_get($runtime, 'boundaries.write_authorization_granted') === false;

            return [
                'url_truth_revision' => $valid ? $urlTruthRevision : 'unavailable',
                'url_truth_projection_hash' => $valid ? hash('sha256', $urlTruthRevision.'|'.$publicCount) : 'unavailable',
                'runtime_evidence_revision' => $valid ? $runtimeVersion.':'.substr($runtimeHash, 0, 32) : 'unavailable',
                'runtime_evidence_hash' => $valid ? $runtimeHash : 'unavailable',
                'authority_revision' => $valid ? $authorityRevision : 'unavailable',
                'deployment_revision' => $releaseSha,
                'source_capability_state' => $valid ? 'available' : 'unavailable',
            ];
        } catch (Throwable) {
            return [
                'url_truth_revision' => 'unavailable',
                'url_truth_projection_hash' => 'unavailable',
                'runtime_evidence_revision' => 'unavailable',
                'runtime_evidence_hash' => 'unavailable',
                'authority_revision' => 'unavailable',
                'deployment_revision' => $releaseSha,
                'source_capability_state' => 'unavailable',
            ];
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map($this->canonicalize(...), $value);
    }

    /** @return array<string, mixed> */
    private function gscEvidence(): array
    {
        $evidence = $this->gsc->read();
        $verified = ($evidence['state'] ?? null) === 'verified'
            && data_get($evidence, 'boundaries.read_only') === true
            && data_get($evidence, 'boundaries.search_submission_allowed') === false;

        return $this->state($verified, 'LIVE_GSC_AGGREGATE_VERIFIED', 'LIVE_GSC_AGGREGATE_HELD');
    }

    /** @return array<string, mixed> */
    private function runtimeEvidence(string $releaseSha): array
    {
        $window = $this->runtimeReceipts->readWindow();
        $evidence = $this->runtimeCloseout->evaluate($window);
        $receipts = array_values(array_filter((array) ($window['receipts'] ?? []), 'is_array'));
        $exactSha = count($receipts) === 3;
        foreach ($receipts as $receipt) {
            $exactSha = $exactSha && hash_equals($releaseSha, (string) data_get($receipt, 'production_calibration.deploy_revision', ''));
        }
        $verified = ($evidence['state'] ?? null) === 'production_proven'
            && $exactSha
            && data_get($evidence, 'boundaries.read_only') === true
            && data_get($evidence, 'boundaries.production_write_authorization_granted') === false;

        return $this->state($verified, 'NATURAL_RUNTIME_WINDOW_VERIFIED', 'NATURAL_RUNTIME_WINDOW_HELD');
    }

    /** @return array<string, mixed> */
    private function ledgerEvidence(string $releaseSha): array
    {
        // The protected-route 401 must come from an external production probe. This
        // read-only closeout deliberately supplies no synthetic success status.
        $evidence = $this->ledgerCloseout->evaluate($releaseSha, 0);
        $verified = ($evidence['state'] ?? null) === 'production_proven'
            && ($evidence['permission_negative_status'] ?? null) === 401
            && ($evidence['l3_enabled'] ?? null) === false
            && ($evidence['l4_enabled'] ?? null) === false
            && data_get($evidence, 'boundaries.production_database_write') === false;

        return $this->state($verified, 'LEDGER_READ_BOUNDARY_VERIFIED', 'LEDGER_401_PROBE_HELD');
    }

    /** @return array<string, mixed> */
    private function decisionEvidence(string $releaseSha): array
    {
        $closeout = $this->decisionCloseout->evaluate($releaseSha);
        $read = $this->decisionRead->snapshot();
        $verified = ($closeout['state'] ?? null) === 'production_proven'
            && ($closeout['natural_scheduler_proven'] ?? null) === true
            && ($closeout['manual_receipts_excluded'] ?? null) === true
            && ($closeout['l3_enabled'] ?? null) === false
            && ($closeout['l4_enabled'] ?? null) === false
            && ($read['read_only'] ?? null) === true
            && in_array($read['state'] ?? null, ['available', 'verified_zero'], true);

        return $this->state($verified, 'NATURAL_DECISION_RECEIPT_VERIFIED', 'NATURAL_DECISION_RECEIPT_HELD');
    }

    /** @return array<string, mixed> */
    private function lifecycleEvidence(): array
    {
        $evidence = $this->lifecycleRead->read(1, 1);
        $verified = ($evidence['state'] ?? null) === 'production_proven'
            && ($evidence['source_state'] ?? null) === 'available'
            && data_get($evidence, 'boundaries.read_only') === true
            && data_get($evidence, 'boundaries.search_submission_exposed') === false
            && data_get($evidence, 'boundaries.automatic_publish') === false
            && data_get($evidence, 'boundaries.automatic_delete') === false;

        return $this->state($verified, 'MATERIAL_PROJECTION_VERIFIED', 'MATERIAL_PROJECTION_HELD');
    }

    /** @return array<string, mixed> */
    private function safely(string $dependencyId, callable $reader): array
    {
        try {
            return ['dependency_id' => $dependencyId, ...$reader()];
        } catch (Throwable) {
            return [
                'dependency_id' => $dependencyId,
                ...$this->state(false, 'UNUSED', 'SOURCE_UNAVAILABLE'),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function state(bool $verified, string $pass, string $hold): array
    {
        return [
            'status' => $verified ? 'verified' : 'held',
            'source_state' => $verified ? 'available' : 'source_unavailable',
            'private_boundary_proven' => $verified,
            'evidence_code' => $verified ? $pass : $hold,
        ];
    }
}
