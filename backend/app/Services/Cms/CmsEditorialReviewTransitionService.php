<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\CmsTranslationRevision;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keeps compact review evidence and row-backed CMS state transitions atomic.
 *
 * @review-surface cms_translation_revision
 * @review-surface content_page
 * @review-surface support_article
 * @review-surface interpretation_guide
 */
final readonly class CmsEditorialReviewTransitionService
{
    public function __construct(
        private RowBackedRevisionWorkspace $workspace,
        private CmsEditorialReviewAttestationService $reviewAttestations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $recordAttributes
     * @param  array<string, mixed>|null  $attestation
     */
    public function saveRevisionedResource(
        string $contentType,
        string $surfaceId,
        Model $record,
        array $payload,
        string $revisionStatus,
        array $recordAttributes,
        bool $reviewApproved,
        bool $releaseRequested,
        bool $publishNow,
        int $actorAdminUserId,
        ?array $attestation = null,
    ): Model {
        return DB::transaction(function () use (
            $contentType,
            $surfaceId,
            $record,
            $payload,
            $revisionStatus,
            $recordAttributes,
            $reviewApproved,
            $releaseRequested,
            $publishNow,
            $actorAdminUserId,
            $attestation,
        ): Model {
            if ($attestation !== null && ! $reviewApproved) {
                throw new ReviewAttestationValidationException(
                    'A CMS review attestation may only accompany an approved review transition.'
                );
            }

            if (! $record->exists) {
                $record->save();
            }

            $updated = $this->workspace->saveWorkingDraft(
                $contentType,
                $record,
                $payload,
                $revisionStatus,
                $recordAttributes,
                $actorAdminUserId > 0 ? $actorAdminUserId : null,
            );
            $working = $this->workspace->workingRevision($contentType, $updated);
            $reviewedContent = $this->workspace->editorRecord($contentType, $updated);
            $resources = [
                ['surface_id' => $surfaceId, 'record' => $reviewedContent],
                ['surface_id' => 'cms_translation_revision', 'record' => $working],
            ];

            if ($this->reviewAttestations->usesSoloOwnerMode()) {
                if ($releaseRequested) {
                    if ($attestation !== null) {
                        throw new ReviewAttestationValidationException(
                            'CMS review approval and release must remain separate transitions.'
                        );
                    }
                    $this->reviewAttestations->assertApprovedEvidence($resources);
                } elseif ($reviewApproved) {
                    $this->reviewAttestations->bindOrCreateApproved(
                        attestation: $attestation,
                        scopeType: 'cms_editorial_review',
                        scopeIdentity: $contentType.':'.(string) $updated->getKey().':revision:'.(string) $working->getKey(),
                        resources: $resources,
                        actorAdminUserId: $actorAdminUserId,
                    );
                }
            } elseif ($attestation !== null) {
                throw new ReviewAttestationValidationException(
                    'Compact owner attestations are unavailable in team-separated mode.'
                );
            }

            if ($publishNow) {
                return $this->workspace->publishWorkingRevision($contentType, $updated);
            }

            return $updated->refresh();
        });
    }
}
