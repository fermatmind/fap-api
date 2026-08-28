<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Lifecycle;

use App\Services\SeoIntel\Detector\SearchContentLinkDetectorEvaluator;
use App\Services\SeoIntel\GscDataQualityGate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ContentLifecycleCandidateEvaluator
{
    public const VERSION = 'seo.content_lifecycle_candidate.v1';

    public const ACTIONS = ['refresh', 'merge', 'retire'];

    public function __construct(
        private readonly ContentLifecycleReviewPolicy $policy = new ContentLifecycleReviewPolicy,
        private readonly SearchContentLinkDetectorEvaluator $detectors = new SearchContentLinkDetectorEvaluator,
        private readonly GscDataQualityGate $gscQuality = new GscDataQualityGate,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $calculatedAt = $this->date($input, 'calculated_at');
        $lastReviewedAt = $this->optionalDate($input, 'last_reviewed_at');
        $authorityRevision = $this->requiredString($input, 'authority_revision');
        $evidenceRevision = $this->requiredString($input, 'evidence_revision');
        $materialFingerprint = $this->sha256($input, 'material_fingerprint');
        $canonicalUrlHash = $this->sha256($input, 'canonical_url_hash');
        $pageFamily = $this->requiredString($input, 'page_family');
        $locale = $this->requiredString($input, 'locale');
        $claimRisk = $this->requiredString($input, 'claim_risk');
        $policy = $this->policy->resolve($pageFamily, $locale, $claimRisk);
        $runtimeIncident = ($input['runtime_incident_active'] ?? false) === true;
        $daysSinceReview = $lastReviewedAt === null
            ? null
            : (int) floor($lastReviewedAt->diffInDays($calculatedAt, false));

        if ($daysSinceReview !== null && $daysSinceReview < 0) {
            throw new InvalidArgumentException('last_reviewed_at cannot be later than calculated_at.');
        }

        $candidates = [];
        if ($lastReviewedAt === null || $daysSinceReview > $policy['review_cycle_days']) {
            $reviewHoldReason = match (true) {
                $runtimeIncident => 'runtime_incident_active',
                $lastReviewedAt === null => 'review_source_unavailable',
                default => null,
            };
            $candidates[] = $this->candidate(
                type: 'review_overdue',
                action: 'refresh',
                status: $reviewHoldReason === null ? 'candidate' : 'hold',
                holdReason: $reviewHoldReason,
                policy: $policy,
                calculatedAt: $calculatedAt,
                authorityRevision: $authorityRevision,
                evidenceRevision: $evidenceRevision,
                materialFingerprint: $materialFingerprint,
                canonicalUrlHash: $canonicalUrlHash,
                evidence: [
                    'last_reviewed_at' => $lastReviewedAt?->toIso8601String(),
                    'days_since_review' => $daysSinceReview,
                    'review_cycle_days' => $policy['review_cycle_days'],
                ],
            );
        }

        if (is_array($input['decay_evidence'] ?? null)) {
            $decayCandidate = $this->decayCandidate(
                $input,
                $policy,
                $calculatedAt,
                $authorityRevision,
                $evidenceRevision,
                $materialFingerprint,
                $canonicalUrlHash,
                $runtimeIncident,
            );
            if ($decayCandidate !== null) {
                $candidates[] = $decayCandidate;
            }
        }

        return [
            'schema_version' => self::VERSION,
            'calculated_at' => $calculatedAt->toIso8601String(),
            'policy' => $policy,
            'candidate_count' => count(array_filter($candidates, static fn (array $row): bool => $row['status'] === 'candidate')),
            'hold_count' => count(array_filter($candidates, static fn (array $row): bool => $row['status'] === 'hold')),
            'candidates' => $candidates,
            'capabilities' => [
                'read_only' => true,
                'automatic_publish' => false,
                'automatic_noindex' => false,
                'automatic_delete' => false,
                'automatic_merge' => false,
                'authority_mutation' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $policy @return array<string, mixed>|null */
    private function decayCandidate(
        array $input,
        array $policy,
        CarbonImmutable $calculatedAt,
        string $authorityRevision,
        string $evidenceRevision,
        string $materialFingerprint,
        string $canonicalUrlHash,
        bool $runtimeIncident,
    ): ?array {
        $evidence = $input['decay_evidence'];
        $action = (string) ($evidence['recommended_action'] ?? 'refresh');
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Lifecycle candidates are limited to refresh, merge, or retire.');
        }

        $gscRows = is_array($evidence['gsc_rows'] ?? null) ? array_values($evidence['gsc_rows']) : [];
        $quality = $this->gscQuality->evaluate($gscRows, $calculatedAt);
        $sampleSufficient = ($evidence['sample_sufficient'] ?? false) === true;
        $detectorEvidence = is_array($evidence['detector_evidence'] ?? null) ? $evidence['detector_evidence'] : [];
        $detectorEvidence = array_merge($detectorEvidence, [
            'page_family' => $policy['page_family'],
            'locale' => $policy['locale'],
            'canonical_url_hash' => $canonicalUrlHash,
            'authority_revision' => $authorityRevision,
            'policy_version' => $policy['schema_version'],
            'gsc_quality_gate_pass' => $quality['status'] === 'pass' && $sampleSufficient,
        ]);
        $detector = $this->detectors->evaluate('content_decay_candidate', $detectorEvidence);

        $holdReason = match (true) {
            $runtimeIncident => 'runtime_incident_active',
            in_array('stale_gsc_report_date', $quality['reasons'], true) => 'gsc_evidence_expired',
            $quality['status'] !== 'pass' => 'gsc_quality_gate_failed',
            ! $sampleSufficient => 'gsc_sample_insufficient',
            $detector['outcome'] === 'measurement_hold' => (string) $detector['root_cause_or_error_code'],
            default => null,
        };
        $status = $holdReason !== null ? 'hold' : ($detector['outcome'] === 'opportunity' ? 'candidate' : 'not_observed');

        if ($status === 'not_observed') {
            return null;
        }

        return $this->candidate(
            type: 'content_decay',
            action: $action,
            status: $status,
            holdReason: $holdReason,
            policy: $policy,
            calculatedAt: $calculatedAt,
            authorityRevision: $authorityRevision,
            evidenceRevision: $evidenceRevision,
            materialFingerprint: $materialFingerprint,
            canonicalUrlHash: $canonicalUrlHash,
            evidence: [
                'gsc_quality' => $quality,
                'sample_sufficient' => $sampleSufficient,
                'detector_result' => $detector,
            ],
        );
    }

    /** @param array<string, mixed> $policy @param array<string, mixed> $evidence @return array<string, mixed> */
    private function candidate(
        string $type,
        string $action,
        string $status,
        ?string $holdReason,
        array $policy,
        CarbonImmutable $calculatedAt,
        string $authorityRevision,
        string $evidenceRevision,
        string $materialFingerprint,
        string $canonicalUrlHash,
        array $evidence,
    ): array {
        $identity = [
            'schema_version' => self::VERSION,
            'candidate_type' => $type,
            'recommended_action' => $action,
            'page_family' => $policy['page_family'],
            'locale' => $policy['locale'],
            'claim_risk' => $policy['claim_risk'],
            'canonical_url_hash' => $canonicalUrlHash,
            'authority_revision' => $authorityRevision,
            'evidence_revision' => $evidenceRevision,
            'material_fingerprint' => $materialFingerprint,
            'policy_hash' => $policy['page_family_policy_hash'],
        ];

        return [
            ...$identity,
            'candidate_key' => hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'status' => $status,
            'hold_reason' => $holdReason,
            'calculated_at' => $calculatedAt->toIso8601String(),
            'evidence' => $evidence,
            'execution_authorized' => false,
        ];
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $key): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("{$key} is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function sha256(array $input, string $key): string
    {
        $value = strtolower($this->requiredString($input, $key));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$key} must be a SHA-256 hash.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function date(array $input, string $key): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->requiredString($input, $key))->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException("{$key} must be a valid timestamp.");
        }
    }

    /** @param array<string, mixed> $input */
    private function optionalDate(array $input, string $key): ?CarbonImmutable
    {
        if (! is_string($input[$key] ?? null) || trim((string) $input[$key]) === '') {
            return null;
        }

        return $this->date($input, $key);
    }
}
