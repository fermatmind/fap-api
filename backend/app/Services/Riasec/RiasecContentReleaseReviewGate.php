<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\ReviewAttestation;
use App\Services\Cms\PersonalityReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;

/**
 * Review-only gate for an exact RIASEC content/release snapshot.
 *
 * Review binding is deliberately separate from import, release, rollout,
 * publication, indexability, and search execution.
 *
 * @review-surface riasec_content_release_review
 */
final readonly class RiasecContentReleaseReviewGate
{
    public function __construct(
        private PersonalityReviewAttestationService $reviewAttestations,
    ) {}

    /**
     * @param  array<string,mixed>  $attestation
     * @return array<string,mixed>
     */
    public function preflight(
        array $attestation,
        string $snapshotId,
        string $snapshotSha256,
    ): array {
        $target = $this->target($snapshotId, $snapshotSha256);
        $preflight = $this->reviewAttestations->preflightApproved(
            $attestation,
            'riasec_content_release_review',
            [$target],
            $snapshotSha256,
        );

        return $preflight + [
            'review_only' => true,
            'import_authorized' => false,
            'release_authorized' => false,
            'rollout_authorized' => false,
            'production_execution' => false,
        ];
    }

    /** @param array<string,mixed>|null $attestation */
    public function bindApproved(
        ?array $attestation,
        string $snapshotId,
        string $snapshotSha256,
        int $actorAdminUserId,
    ): ReviewAttestation {
        return $this->reviewAttestations->bindOrCreateApproved(
            attestation: $attestation,
            surfaceId: 'riasec_content_release_review',
            scopeType: 'riasec_content_release_snapshot',
            scopeIdentity: $snapshotId,
            authoritativeTargets: [$this->target($snapshotId, $snapshotSha256)],
            actorAdminUserId: $actorAdminUserId,
            packageSha256: $snapshotSha256,
        );
    }

    public function assertApproved(string $snapshotId, string $snapshotSha256): void
    {
        $this->reviewAttestations->assertApprovedEvidence(
            'riasec_content_release_review',
            [$this->target($snapshotId, $snapshotSha256)],
        );
    }

    /** @return array{identity:string,sha256:string} */
    private function target(string $snapshotId, string $snapshotSha256): array
    {
        $snapshotId = trim($snapshotId);
        $snapshotSha256 = strtolower(trim($snapshotSha256));
        if ($snapshotId === '' || strlen($snapshotId) > 191) {
            throw new ReviewAttestationValidationException('RIASEC release snapshot identity is invalid.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $snapshotSha256) !== 1) {
            throw new ReviewAttestationValidationException('RIASEC release snapshot SHA-256 is invalid.');
        }

        return [
            'identity' => 'release_snapshot:'.$snapshotId,
            'sha256' => $snapshotSha256,
        ];
    }
}
