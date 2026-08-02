<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Models\ReviewAttestation;
use App\Models\ReviewAttestationTargetEvidence;
use App\Services\Cms\PersonalityReviewAttestationService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Binds private, configured-owner review evidence to already imported exact
 * personality revisions. It deliberately cannot import, publish, index, or
 * otherwise mutate public runtime state.
 *
 * @review-surface personality_public_content_asset_revision_review
 */
final readonly class PersonalityCmsPromotionReviewBinder
{
    private const SURFACE_ID = 'personality_public_content_asset_revision_review';

    public function __construct(private PersonalityReviewAttestationService $attestations) {}

    /**
     * @param  array{framework:string,targets:list<array<string,mixed>>,package_sha256:string}  $package
     * @param  array<string,mixed>  $attestation
     * @return array<string,mixed>
     */
    public function preflight(PromotionContext $context, array $package, array $attestation): array
    {
        $plan = $this->plan($context, $package, false);
        $validated = $this->attestations->preflightApproved(
            $attestation,
            self::SURFACE_ID,
            $plan['attestation_targets'],
            $context->packageSha256,
        );

        return [
            'status' => 'PASS_PERSONALITY_PROMOTION_REVIEW_PREFLIGHT',
            'package_sha256' => $context->packageSha256,
            'target_count' => count($plan['targets']),
            'target_set_sha256' => $validated['target_set_sha256'],
            'review_evidence_bound' => false,
            ...$this->safetyBoundaries(),
        ];
    }

    /**
     * @param  array{framework:string,targets:list<array<string,mixed>>,package_sha256:string}  $package
     * @param  array<string,mixed>  $attestation
     * @return array<string,mixed>
     */
    public function bind(
        PromotionContext $context,
        array $package,
        array $attestation,
        int $actorAdminUserId,
    ): array {
        return DB::transaction(function () use ($context, $package, $attestation, $actorAdminUserId): array {
            $plan = $this->plan($context, $package, true);
            $bound = $this->attestations->bindApproved(
                $attestation,
                self::SURFACE_ID,
                $plan['attestation_targets'],
                $actorAdminUserId,
                $context->packageSha256,
            );
            $targetEvidence = $bound->targetEvidences
                ->keyBy('target_identity');
            $created = 0;
            foreach ($plan['targets'] as $target) {
                $evidence = $targetEvidence->get($target['attestation_target_identity']);
                if (! $evidence instanceof ReviewAttestationTargetEvidence) {
                    throw new DomainException('personality_promotion_review_target_evidence_missing');
                }
                $existing = PersonalityPublicContentAssetRevisionReview::query()
                    ->lockForUpdate()
                    ->where('revision_id', $target['revision']->id)
                    ->first();
                if ($existing instanceof PersonalityPublicContentAssetRevisionReview) {
                    $this->assertRevisionReview($existing, $target, $bound, $evidence);

                    continue;
                }

                PersonalityPublicContentAssetRevisionReview::query()->create([
                    'revision_id' => $target['revision']->id,
                    'asset_id' => $target['asset']->id,
                    'authority_asset_key' => $target['asset_key'],
                    'source_package' => $target['revision']->source_package,
                    'asset_sha256' => $target['revision']->source_hash,
                    'authority_package_sha256' => $context->packageSha256,
                    'review_register_sha256' => $bound->evidence_sha256,
                    'reviewer_name' => 'configured_solo_owner',
                    'reviewed_at' => $bound->attested_at,
                    'decision' => PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED,
                    'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
                    'evidence_sha256' => $evidence->evidence_sha256,
                    'bound_by_admin_user_id' => $actorAdminUserId,
                ]);
                $created++;
            }
            $this->assertApproved($context, $plan['targets']);

            return [
                'status' => 'PASS_PERSONALITY_PROMOTION_REVIEW_BOUND',
                'package_sha256' => $context->packageSha256,
                'target_count' => count($plan['targets']),
                'review_evidence_created_count' => $created,
                'review_evidence_bound' => true,
                ...$this->safetyBoundaries(),
            ];
        }, 3);
    }

    /**
     * @param  list<array{asset:PersonalityPublicContentAsset,revision:PersonalityPublicContentAssetRevision,asset_key:string}>  $targets
     */
    public function assertApproved(PromotionContext $context, array $targets): void
    {
        $attestationTargets = $this->attestationTargets($context, $targets);
        $attestation = $this->attestations->approvedAllEvidence(
            self::SURFACE_ID,
            $attestationTargets,
            $context->packageSha256,
        );
        if (! $attestation instanceof ReviewAttestation) {
            throw new DomainException('personality_promotion_review_evidence_invalid');
        }
        $evidence = $attestation->loadMissing('targetEvidences')->targetEvidences->keyBy('target_identity');
        foreach ($targets as $target) {
            $identity = $this->attestationTargetIdentity($context, $target['asset_key']);
            $targetEvidence = $evidence->get($identity);
            $review = PersonalityPublicContentAssetRevisionReview::query()
                ->where('revision_id', $target['revision']->id)
                ->first();
            if (! $targetEvidence instanceof ReviewAttestationTargetEvidence
                || ! $review instanceof PersonalityPublicContentAssetRevisionReview) {
                throw new DomainException('personality_promotion_review_evidence_invalid');
            }
            $this->assertRevisionReview($review, $target, $attestation, $targetEvidence);
        }
    }

    /** @return array<string,bool> */
    public function safetyBoundaries(): array
    {
        return [
            'imports' => false,
            'publishes' => false,
            'changes_indexability' => false,
            'changes_discoverability' => false,
            'submits_search_urls' => false,
            'deploys' => false,
            'writes_public_reviewer_identity' => false,
        ];
    }

    /**
     * @param  array{framework:string,targets:list<array<string,mixed>>,package_sha256:string}  $package
     * @return array{targets:list<array{asset:PersonalityPublicContentAsset,revision:PersonalityPublicContentAssetRevision,asset_key:string,attestation_target_identity:string}>,attestation_targets:list<array{identity:string,sha256:string}>}
     */
    private function plan(PromotionContext $context, array $package, bool $lock): array
    {
        $targets = [];
        foreach ($package['targets'] as $candidate) {
            $assetKey = (string) ($candidate['asset_key'] ?? '');
            $sourceHash = (string) ($candidate['source_hash'] ?? '');
            $candidateAsset = $candidate['asset'] ?? null;
            if ($assetKey === '' || ! $candidateAsset instanceof PersonalityPublicContentAsset
                || preg_match('/^[0-9a-f]{64}$/', $sourceHash) !== 1) {
                throw new DomainException('personality_promotion_review_target_invalid');
            }
            $assetQuery = PersonalityPublicContentAsset::query()->withoutGlobalScopes();
            if ($lock) {
                $assetQuery->lockForUpdate();
            }
            $asset = $assetQuery->find($candidateAsset->id);
            $revisionQuery = PersonalityPublicContentAssetRevision::query()
                ->where('authority_package_sha256', $context->packageSha256)
                ->where('authority_asset_key', $assetKey);
            if ($lock) {
                $revisionQuery->lockForUpdate();
            }
            $revision = $revisionQuery->first();
            if (! $asset instanceof PersonalityPublicContentAsset
                || ! $revision instanceof PersonalityPublicContentAssetRevision
                || (int) $revision->asset_id !== (int) $asset->id
                || (int) $asset->working_revision_id !== (int) $revision->id
                || (string) $revision->workflow_state !== PersonalityPublicContentAssetRevision::STATE_DRAFT
                || ! hash_equals($sourceHash, (string) $revision->source_hash)
                || ! hash_equals(
                    'content-promotion/'.$context->lane.'/'.$context->subscope,
                    (string) $revision->source_package,
                )) {
                throw new DomainException('personality_promotion_review_target_invalid');
            }
            $targets[] = [
                'asset' => $asset,
                'revision' => $revision,
                'asset_key' => $assetKey,
                'attestation_target_identity' => $this->attestationTargetIdentity($context, $assetKey),
            ];
        }
        usort($targets, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);

        return [
            'targets' => $targets,
            'attestation_targets' => $this->attestationTargets($context, $targets),
        ];
    }

    /**
     * @param  list<array{asset:PersonalityPublicContentAsset,revision:PersonalityPublicContentAssetRevision,asset_key:string}>  $targets
     * @return list<array{identity:string,sha256:string}>
     */
    private function attestationTargets(PromotionContext $context, array $targets): array
    {
        $result = [];
        foreach ($targets as $target) {
            $sourceHash = (string) $target['revision']->source_hash;
            if (preg_match('/^[0-9a-f]{64}$/', $sourceHash) !== 1) {
                throw new DomainException('personality_promotion_review_target_invalid');
            }
            $result[] = [
                'identity' => substr($this->attestationTargetIdentity($context, $target['asset_key']), strlen(self::SURFACE_ID) + 1),
                'sha256' => $sourceHash,
            ];
        }

        return $result;
    }

    private function attestationTargetIdentity(PromotionContext $context, string $assetKey): string
    {
        return self::SURFACE_ID.':content-promotion:'.$context->lane.'/'.$context->subscope.':'.$assetKey;
    }

    /**
     * @param  array{asset:PersonalityPublicContentAsset,revision:PersonalityPublicContentAssetRevision,asset_key:string}  $target
     */
    private function assertRevisionReview(
        PersonalityPublicContentAssetRevisionReview $review,
        array $target,
        ReviewAttestation $attestation,
        ReviewAttestationTargetEvidence $targetEvidence,
    ): void {
        if ((int) $review->asset_id !== (int) $target['asset']->id
            || (int) $review->revision_id !== (int) $target['revision']->id
            || ! hash_equals((string) $review->authority_asset_key, $target['asset_key'])
            || ! hash_equals((string) $review->source_package, (string) $target['revision']->source_package)
            || ! hash_equals((string) $review->asset_sha256, (string) $target['revision']->source_hash)
            || ! hash_equals((string) $review->authority_package_sha256, (string) $target['revision']->authority_package_sha256)
            || ! hash_equals((string) $review->review_register_sha256, (string) $attestation->evidence_sha256)
            || ! hash_equals((string) $review->evidence_sha256, (string) $targetEvidence->evidence_sha256)
            || (int) $review->bound_by_admin_user_id !== (int) $attestation->attested_by_admin_user_id
            || (string) $review->reviewer_name !== 'configured_solo_owner'
            || $review->reviewed_at === null
            || ! $review->reviewed_at->equalTo($attestation->attested_at)
            || (string) $review->decision !== PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED
            || (string) $review->review_source !== PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN
            || (string) $targetEvidence->target_decision !== 'approved'
            || ! hash_equals((string) $targetEvidence->target_sha256, (string) $target['revision']->source_hash)) {
            throw new DomainException('personality_promotion_review_evidence_invalid');
        }
    }
}
