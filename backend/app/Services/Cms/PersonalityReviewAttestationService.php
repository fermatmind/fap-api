<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\ReviewAttestation;
use App\Models\ReviewAttestationTargetEvidence;
use App\Services\ReviewGovernance\ReviewAttestationFactory;
use App\Services\ReviewGovernance\ReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;

/**
 * Private adapter for exact-target Personality review evidence.
 *
 * Callers remain responsible for deriving targets from their authoritative
 * package, revision, batch, or release artifact. This adapter never publishes,
 * promotes, imports, changes discoverability, or writes public reviewer data.
 *
 * @review-surface personality_public_content_asset
 * @review-surface personality_public_content_asset_revision_review
 * @review-surface big_five_v2_editorial_revision
 * @review-surface mbti_approval_batch
 * @review-surface mbti_cross_type_comparison_authority
 * @review-surface enneagram_review_binder
 * @review-surface riasec_content_release_review
 */
final readonly class PersonalityReviewAttestationService
{
    private const SURFACES = [
        'personality_public_content_asset',
        'personality_public_content_asset_revision_review',
        'big_five_v2_editorial_revision',
        'mbti_approval_batch',
        'mbti_cross_type_comparison_authority',
        'enneagram_review_binder',
        'riasec_content_release_review',
    ];

    public function __construct(
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
            throw new ReviewAttestationValidationException('Personality review targets must not be empty.');
        }

        $targets = [];
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
                    'Personality review target at index '.$index.' has an invalid identity or SHA-256.'
                );
            }
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
    public function preflightApproved(
        array $attestation,
        string $surfaceId,
        array $authoritativeTargets,
        ?string $expectedPackageSha256 = null,
    ): array {
        if (($attestation['decision'] ?? null) !== 'approved_all') {
            throw new ReviewAttestationValidationException(
                'Personality approval requires approved_all; rejected or exception batches fail closed.'
            );
        }

        return $this->attestations->preflight(
            $attestation,
            $this->targets($surfaceId, $authoritativeTargets),
            $expectedPackageSha256,
        );
    }

    /**
     * @param  array<string,mixed>|null  $attestation
     * @param  list<array{identity:string,sha256:string}>  $authoritativeTargets
     */
    public function bindOrCreateApproved(
        ?array $attestation,
        string $surfaceId,
        string $scopeType,
        string $scopeIdentity,
        array $authoritativeTargets,
        int $actorAdminUserId,
        ?string $packageSha256 = null,
    ): ReviewAttestation {
        $this->assertConfiguredSoloOwner($actorAdminUserId);
        $targets = $this->targets($surfaceId, $authoritativeTargets);
        $attestation ??= $this->factory->make(
            scopeType: $scopeType,
            scopeIdentity: $scopeIdentity,
            decision: 'approved_all',
            targets: $targets,
            packageSha256: $packageSha256,
            adminUserId: $actorAdminUserId,
        );
        $this->preflightApproved($attestation, $surfaceId, $authoritativeTargets, $packageSha256);

        return $this->attestations->bind($attestation, $targets, $packageSha256);
    }

    /**
     * @param  array<string,mixed>  $attestation
     * @param  list<array{identity:string,sha256:string}>  $authoritativeTargets
     */
    public function bindApproved(
        array $attestation,
        string $surfaceId,
        array $authoritativeTargets,
        int $actorAdminUserId,
        ?string $expectedPackageSha256 = null,
    ): ReviewAttestation {
        $this->assertConfiguredSoloOwner($actorAdminUserId);
        $this->preflightApproved($attestation, $surfaceId, $authoritativeTargets, $expectedPackageSha256);

        return $this->attestations->bind(
            $attestation,
            $this->targets($surfaceId, $authoritativeTargets),
            $expectedPackageSha256,
        );
    }

    /** @param list<array{identity:string,sha256:string}> $authoritativeTargets */
    public function hasApprovedEvidence(string $surfaceId, array $authoritativeTargets): bool
    {
        foreach ($this->targets($surfaceId, $authoritativeTargets) as $target) {
            if (! $this->hasApprovedTarget($target)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{identity:string,sha256:string}> $authoritativeTargets */
    public function assertApprovedEvidence(string $surfaceId, array $authoritativeTargets): void
    {
        if (! $this->hasApprovedEvidence($surfaceId, $authoritativeTargets)) {
            throw new ReviewAttestationValidationException(
                'Personality review evidence is missing or stale for one or more exact targets.'
            );
        }
    }

    /** @param array{target_identity:string,target_sha256:string} $target */
    private function hasApprovedTarget(array $target): bool
    {
        return ReviewAttestationTargetEvidence::query()
            ->where('target_identity', $target['target_identity'])
            ->where('target_sha256', $target['target_sha256'])
            ->where('target_decision', 'approved')
            ->whereHas('attestation', static function ($query): void {
                $query
                    ->where('review_mode', 'solo_owner')
                    ->where('review_source', (string) config('review_governance.attestation.review_source'))
                    ->where('decision', 'approved_all');
            })
            ->exists();
    }

    public function assertConfiguredSoloOwner(int $actorAdminUserId): void
    {
        if (! $this->isConfiguredSoloOwner($actorAdminUserId)) {
            throw new ReviewAttestationValidationException(
                'Personality solo-owner approval requires the authenticated configured owner.'
            );
        }
    }

    private function assertSurface(string $surfaceId): void
    {
        if (! in_array($surfaceId, self::SURFACES, true)) {
            throw new ReviewAttestationValidationException('Personality review surface is not supported: '.$surfaceId.'.');
        }
    }
}
