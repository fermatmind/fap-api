<?php

declare(strict_types=1);

namespace App\Services\ReviewGovernance;

use App\DTO\ReviewGovernance\ReviewTargetSet;
use App\Models\ReviewAttestation;

/**
 * Private exact-target adapter for Career and SEO human-review evidence.
 *
 * Callers must derive targets from the authoritative resource, artifact, batch,
 * revision, or queue snapshot. Binding evidence never publishes, imports,
 * indexes, submits search URLs, or changes discoverability/runtime state.
 *
 * @review-surface career_trust_manifest
 * @review-surface career_occupation_truth_metric_review
 * @review-surface career_editorial_patch
 * @review-surface career_occupation_directory_review
 * @review-surface career_salary_asset_review
 * @review-surface career_ai_impact_asset_review
 * @review-surface career_import_publish_readiness
 * @review-surface seo_agent_draft_review
 * @review-surface seo_canary_approval
 * @review-surface search_submission_queue_approval
 * @review-surface seo_claim_risk_review
 * @review-surface content_package_approval
 */
final readonly class CareerSeoReviewAttestationService
{
    private const SURFACES = [
        'career_trust_manifest',
        'career_occupation_truth_metric_review',
        'career_editorial_patch',
        'career_occupation_directory_review',
        'career_salary_asset_review',
        'career_ai_impact_asset_review',
        'career_import_publish_readiness',
        'seo_agent_draft_review',
        'seo_canary_approval',
        'search_submission_queue_approval',
        'seo_claim_risk_review',
        'content_package_approval',
    ];

    private const PACKAGE_SCOPED_SURFACES = [
        'career_import_publish_readiness',
        'seo_agent_draft_review',
        'seo_canary_approval',
        'content_package_approval',
    ];

    public function __construct(
        private ReviewAttestationCanonicalizer $canonicalizer,
        private ReviewAttestationFactory $factory,
        private ReviewAttestationService $attestations,
    ) {}

    public function usesSoloOwnerMode(): bool
    {
        return (string) config('review_governance.mode') === 'solo_owner';
    }

    public function isConfiguredSoloOwner(int $adminUserId): bool
    {
        return $this->usesSoloOwnerMode()
            && $adminUserId > 0
            && $adminUserId === (int) config('review_governance.solo_owner_admin_user_id');
    }

    /**
     * @param  list<array{identity:string,sha256:string}>  $authoritativeTargets
     * @return list<array{target_identity:string,target_sha256:string}>
     */
    public function targets(string $surfaceId, array $authoritativeTargets): array
    {
        $this->assertSurface($surfaceId);
        if ($authoritativeTargets === []) {
            throw new ReviewAttestationValidationException('Career/SEO review targets must not be empty.');
        }

        $targets = [];
        $seen = [];
        foreach ($authoritativeTargets as $index => $target) {
            $missing = array_diff(['identity', 'sha256'], array_keys($target));
            $extra = array_diff(array_keys($target), ['identity', 'sha256']);
            if ($missing !== []
                || $extra !== []
                || ! is_string($target['identity'] ?? null)
                || ! is_string($target['sha256'] ?? null)
                || trim($target['identity']) === ''
                || trim($target['identity']) !== $target['identity']
                || preg_match('/^[0-9a-f]{64}$/', $target['sha256']) !== 1) {
                throw new ReviewAttestationValidationException(
                    'Career/SEO review target at index '.$index.' has an invalid identity or SHA-256.'
                );
            }
            if (isset($seen[$target['identity']])) {
                throw new ReviewAttestationValidationException(
                    'Career/SEO review target set contains a duplicate identity: '.$target['identity'].'.'
                );
            }
            $seen[$target['identity']] = true;
            $targets[] = [
                'target_identity' => $surfaceId.':'.$target['identity'],
                'target_sha256' => $target['sha256'],
            ];
        }

        return $targets;
    }

    /**
     * @param  array<string,mixed>  $attestation
     * @param  list<array{identity:string,sha256:string}>  $authoritativeTargets
     * @return array<string,mixed>
     */
    public function preflight(
        array $attestation,
        string $surfaceId,
        array $authoritativeTargets,
        ?string $expectedPackageSha256 = null,
    ): array {
        $this->assertExpectedPackageSha256($surfaceId, $expectedPackageSha256);

        return $this->attestations->preflight(
            $attestation,
            $this->targets($surfaceId, $authoritativeTargets),
            $expectedPackageSha256,
        );
    }

    /**
     * Bind review evidence only. This method intentionally performs no domain
     * workflow transition and accepts approved, exception, or rejected review.
     *
     * @param  array<string,mixed>  $attestation
     * @param  list<array{identity:string,sha256:string}>  $authoritativeTargets
     */
    public function bindReview(
        array $attestation,
        string $surfaceId,
        array $authoritativeTargets,
        int $actorAdminUserId,
        ?string $expectedPackageSha256 = null,
    ): ReviewAttestation {
        $this->assertConfiguredSoloOwner($actorAdminUserId);
        $this->assertExpectedPackageSha256($surfaceId, $expectedPackageSha256);

        return $this->attestations->bind(
            $attestation,
            $this->targets($surfaceId, $authoritativeTargets),
            $expectedPackageSha256,
        );
    }

    /**
     * @param  list<array{identity:string,sha256:string}>  $authoritativeTargets
     * @param  list<array{target_identity:string,reason:string}>  $exceptions
     */
    public function createAndBindReview(
        string $surfaceId,
        string $scopeType,
        string $scopeIdentity,
        string $decision,
        array $authoritativeTargets,
        int $actorAdminUserId,
        ?string $packageSha256 = null,
        array $exceptions = [],
    ): ReviewAttestation {
        $this->assertConfiguredSoloOwner($actorAdminUserId);
        $this->assertExpectedPackageSha256($surfaceId, $packageSha256);
        $targets = $this->targets($surfaceId, $authoritativeTargets);
        $attestation = $this->factory->make(
            scopeType: $scopeType,
            scopeIdentity: $scopeIdentity,
            decision: $decision,
            targets: $targets,
            packageSha256: $packageSha256,
            exceptions: $exceptions,
            adminUserId: $actorAdminUserId,
        );

        return $this->attestations->bind($attestation, $targets, $packageSha256);
    }

    /**
     * Requires one immutable attestation over the exact current target set.
     * Separate single-target attestations cannot be combined into batch proof.
     *
     * @param  list<array{identity:string,sha256:string}>  $authoritativeTargets
     */
    public function hasApprovedAllEvidence(
        string $surfaceId,
        array $authoritativeTargets,
        ?string $expectedPackageSha256 = null,
    ): bool {
        return $this->approvedAllEvidence(
            $surfaceId,
            $authoritativeTargets,
            $expectedPackageSha256,
        ) instanceof ReviewAttestation;
    }

    /**
     * Return the newest immutable approval for the exact current target set.
     * Reviewer identity and private evidence fields must never be projected
     * from the returned model onto a public response.
     *
     * @param  list<array{identity:string,sha256:string}>  $authoritativeTargets
     */
    public function approvedAllEvidence(
        string $surfaceId,
        array $authoritativeTargets,
        ?string $expectedPackageSha256 = null,
        ?string $scopeType = null,
        ?string $scopeIdentity = null,
    ): ?ReviewAttestation {
        $currentOwnerAdminUserId = (int) config('review_governance.solo_owner_admin_user_id');
        if (! $this->usesSoloOwnerMode()
            || $currentOwnerAdminUserId <= 0
            || ($expectedPackageSha256 !== null && preg_match('/^[0-9a-f]{64}$/', $expectedPackageSha256) !== 1)
            || (in_array($surfaceId, self::PACKAGE_SCOPED_SURFACES, true) && $expectedPackageSha256 === null)) {
            return null;
        }

        $targetSet = ReviewTargetSet::fromArray(
            $this->targets($surfaceId, $authoritativeTargets),
            $this->canonicalizer,
        );

        $query = ReviewAttestation::query()
            ->where('schema_version', (string) config('review_governance.attestation.schema_version'))
            ->where('review_mode', 'solo_owner')
            ->where('review_source', (string) config('review_governance.attestation.review_source'))
            ->where('statement_version', (string) config('review_governance.attestation.statement_version'))
            ->where('attested_by_admin_user_id', $currentOwnerAdminUserId)
            ->where('decision', 'approved_all')
            ->where('target_count', $targetSet->count())
            ->where('target_set_sha256', $targetSet->sha256)
            ->whereHas('targetEvidences', static function ($query) use ($targetSet): void {
                $query->where('target_decision', 'approved')
                    ->where(static function ($query) use ($targetSet): void {
                        foreach ($targetSet->targets as $target) {
                            $query->orWhere(static function ($query) use ($target): void {
                                $query->where('target_identity', $target->identity)
                                    ->where('target_sha256', $target->sha256);
                            });
                        }
                    });
            }, '=', $targetSet->count())
            ->has('targetEvidences', '=', $targetSet->count());

        $expectedPackageSha256 === null
            ? $query->whereNull('package_sha256')
            : $query->where('package_sha256', $expectedPackageSha256);
        if ($scopeType !== null) {
            $query->where('scope_type', $scopeType);
        }
        if ($scopeIdentity !== null) {
            $query->where('scope_identity', $scopeIdentity);
        }

        return $query->orderByDesc('attested_at')->orderByDesc('id')->first();
    }

    /** @param list<array{identity:string,sha256:string}> $authoritativeTargets */
    public function assertApprovedAllEvidence(
        string $surfaceId,
        array $authoritativeTargets,
        ?string $expectedPackageSha256 = null,
    ): void {
        if (! $this->hasApprovedAllEvidence($surfaceId, $authoritativeTargets, $expectedPackageSha256)) {
            throw new ReviewAttestationValidationException(
                'Career/SEO approved review evidence is missing or stale for the exact target set.'
            );
        }
    }

    /** @return array<string,bool> */
    public function safetyBoundaries(): array
    {
        return [
            'publishes' => false,
            'imports' => false,
            'changes_indexability' => false,
            'submits_search_urls' => false,
            'changes_discoverability' => false,
            'writes_public_reviewer_identity' => false,
        ];
    }

    private function assertConfiguredSoloOwner(int $actorAdminUserId): void
    {
        if (! $this->isConfiguredSoloOwner($actorAdminUserId)) {
            throw new ReviewAttestationValidationException(
                'Career/SEO solo-owner review requires the authenticated configured owner.'
            );
        }
    }

    private function assertExpectedPackageSha256(string $surfaceId, ?string $expectedPackageSha256): void
    {
        $this->assertSurface($surfaceId);
        if ($expectedPackageSha256 !== null && preg_match('/^[0-9a-f]{64}$/', $expectedPackageSha256) !== 1) {
            throw new ReviewAttestationValidationException(
                'Career/SEO expected package SHA-256 must be an exact lowercase digest.'
            );
        }
        if (in_array($surfaceId, self::PACKAGE_SCOPED_SURFACES, true) && $expectedPackageSha256 === null) {
            throw new ReviewAttestationValidationException(
                'Career/SEO package-scoped review requires the exact current package SHA-256.'
            );
        }
    }

    private function assertSurface(string $surfaceId): void
    {
        if (! in_array($surfaceId, self::SURFACES, true)) {
            throw new ReviewAttestationValidationException(
                'Career/SEO review surface is not supported: '.$surfaceId.'.'
            );
        }
    }
}
