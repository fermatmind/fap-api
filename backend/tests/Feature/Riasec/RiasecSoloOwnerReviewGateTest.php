<?php

declare(strict_types=1);

namespace Tests\Feature\Riasec;

use App\Models\ReviewAttestation;
use App\Models\ReviewAttestationTargetEvidence;
use App\Services\Cms\PersonalityReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationFactory;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;
use App\Services\Riasec\RiasecContentReleaseReviewGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RiasecSoloOwnerReviewGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_snapshot_review_binds_without_authorizing_import_release_or_rollout(): void
    {
        $snapshotId = 'riasec-60-140-content-release-v1';
        $snapshotSha = hash('sha256', 'exact-riasec-content-release');
        $attestation = $this->attestation($snapshotId, $snapshotSha);
        $gate = app(RiasecContentReleaseReviewGate::class);

        $preflight = $gate->preflight($attestation, $snapshotId, $snapshotSha);
        $bound = $gate->bindApproved(
            $attestation,
            $snapshotId,
            $snapshotSha,
            (int) config('review_governance.solo_owner_admin_user_id'),
        );
        $gate->assertApproved($snapshotId, $snapshotSha);

        $this->assertSame('PASS_SOLO_OWNER_ATTESTATION_PREFLIGHT', $preflight['status']);
        $this->assertTrue($preflight['review_only']);
        $this->assertFalse($preflight['import_authorized']);
        $this->assertFalse($preflight['release_authorized']);
        $this->assertFalse($preflight['rollout_authorized']);
        $this->assertFalse($preflight['production_execution']);
        $this->assertTrue($bound->wasRecentlyCreated);
        $this->assertSame(1, ReviewAttestation::query()->count());
        $this->assertSame(1, ReviewAttestationTargetEvidence::query()->count());
    }

    public function test_non_owner_rejected_and_hash_drift_inputs_write_nothing(): void
    {
        $snapshotId = 'riasec-review-fail-closed';
        $snapshotSha = hash('sha256', 'riasec-review-fail-closed');
        $gate = app(RiasecContentReleaseReviewGate::class);
        $attestation = $this->attestation($snapshotId, $snapshotSha);

        foreach ([
            'non_owner' => [...$attestation, 'attested_by_admin_user_id' => 999],
            'rejected' => app(ReviewAttestationFactory::class)->make(
                scopeType: 'riasec_content_release_snapshot',
                scopeIdentity: $snapshotId,
                decision: 'rejected',
                targets: app(PersonalityReviewAttestationService::class)->targets(
                    'riasec_content_release_review',
                    [['identity' => 'release_snapshot:'.$snapshotId, 'sha256' => $snapshotSha]],
                ),
                packageSha256: $snapshotSha,
            ),
            'hash_drift' => [...$attestation, 'package_sha256' => str_repeat('0', 64)],
        ] as $label => $invalid) {
            try {
                $gate->bindApproved(
                    $invalid,
                    $snapshotId,
                    $snapshotSha,
                    (int) config('review_governance.solo_owner_admin_user_id'),
                );
                $this->fail('Expected fail-closed RIASEC review for '.$label.'.');
            } catch (ReviewAttestationValidationException) {
                $this->assertSame(0, ReviewAttestation::query()->count(), $label);
                $this->assertSame(0, ReviewAttestationTargetEvidence::query()->count(), $label);
            }
        }
    }

    public function test_team_separated_mode_rejects_compact_riasec_evidence_without_writes(): void
    {
        $snapshotId = 'riasec-team-separated';
        $snapshotSha = hash('sha256', $snapshotId);
        $attestation = $this->attestation($snapshotId, $snapshotSha);
        config()->set('review_governance.mode', 'team_separated');

        try {
            app(RiasecContentReleaseReviewGate::class)->bindApproved(
                $attestation,
                $snapshotId,
                $snapshotSha,
                (int) config('review_governance.solo_owner_admin_user_id'),
            );
            $this->fail('Expected team-separated RIASEC review to reject compact owner evidence.');
        } catch (ReviewAttestationValidationException) {
            $this->assertSame(0, ReviewAttestation::query()->count());
            $this->assertSame(0, ReviewAttestationTargetEvidence::query()->count());
        }
    }

    public function test_bound_evidence_is_rejected_after_solo_owner_rotation(): void
    {
        $snapshotId = 'riasec-owner-rotation';
        $snapshotSha = hash('sha256', $snapshotId);
        $gate = app(RiasecContentReleaseReviewGate::class);
        $ownerAdminUserId = (int) config('review_governance.solo_owner_admin_user_id');
        $gate->bindApproved($this->attestation($snapshotId, $snapshotSha), $snapshotId, $snapshotSha, $ownerAdminUserId);

        config()->set('review_governance.solo_owner_admin_user_id', $ownerAdminUserId + 1);

        try {
            $gate->assertApproved($snapshotId, $snapshotSha);
            $this->fail('Expected evidence from the previous configured owner to fail closed.');
        } catch (ReviewAttestationValidationException) {
            $this->assertSame(1, ReviewAttestation::query()->count());
            $this->assertSame(1, ReviewAttestationTargetEvidence::query()->count());
        }
    }

    public function test_bound_solo_owner_evidence_is_rejected_in_team_separated_mode(): void
    {
        $snapshotId = 'riasec-mode-change';
        $snapshotSha = hash('sha256', $snapshotId);
        $gate = app(RiasecContentReleaseReviewGate::class);
        $gate->bindApproved(
            $this->attestation($snapshotId, $snapshotSha),
            $snapshotId,
            $snapshotSha,
            (int) config('review_governance.solo_owner_admin_user_id'),
        );

        config()->set('review_governance.mode', 'team_separated');

        try {
            $gate->assertApproved($snapshotId, $snapshotSha);
            $this->fail('Expected solo-owner evidence reuse to fail closed in team-separated mode.');
        } catch (ReviewAttestationValidationException) {
            $this->assertSame(1, ReviewAttestation::query()->count());
            $this->assertSame(1, ReviewAttestationTargetEvidence::query()->count());
        }
    }

    public function test_separate_single_target_attestations_cannot_be_combined_as_batch_evidence(): void
    {
        $service = app(PersonalityReviewAttestationService::class);
        $ownerAdminUserId = (int) config('review_governance.solo_owner_admin_user_id');
        $targets = [
            ['identity' => 'release_snapshot:riasec-60', 'sha256' => hash('sha256', 'riasec-60')],
            ['identity' => 'release_snapshot:riasec-140', 'sha256' => hash('sha256', 'riasec-140')],
        ];

        foreach ($targets as $target) {
            $service->bindOrCreateApproved(
                attestation: null,
                surfaceId: 'riasec_content_release_review',
                scopeType: 'riasec_content_release_snapshot',
                scopeIdentity: $target['identity'],
                authoritativeTargets: [$target],
                actorAdminUserId: $ownerAdminUserId,
            );
        }

        $this->assertFalse($service->hasApprovedEvidence('riasec_content_release_review', $targets));

        $service->bindOrCreateApproved(
            attestation: null,
            surfaceId: 'riasec_content_release_review',
            scopeType: 'riasec_content_release_snapshot_batch',
            scopeIdentity: 'riasec-60-140',
            authoritativeTargets: $targets,
            actorAdminUserId: $ownerAdminUserId,
        );

        $this->assertTrue($service->hasApprovedEvidence('riasec_content_release_review', $targets));
        $this->assertSame(3, ReviewAttestation::query()->count());
        $this->assertSame(4, ReviewAttestationTargetEvidence::query()->count());
    }

    /** @return array<string,mixed> */
    private function attestation(string $snapshotId, string $snapshotSha): array
    {
        $targets = app(PersonalityReviewAttestationService::class)->targets(
            'riasec_content_release_review',
            [['identity' => 'release_snapshot:'.$snapshotId, 'sha256' => $snapshotSha]],
        );

        return app(ReviewAttestationFactory::class)->make(
            scopeType: 'riasec_content_release_snapshot',
            scopeIdentity: $snapshotId,
            decision: 'approved_all',
            targets: $targets,
            packageSha256: $snapshotSha,
        );
    }
}
