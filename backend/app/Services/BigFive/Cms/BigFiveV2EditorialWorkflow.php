<?php

declare(strict_types=1);

namespace App\Services\BigFive\Cms;

use App\Models\BigFiveV2EditorialAssetIndexEntry;
use App\Models\BigFiveV2EditorialRevision;
use Illuminate\Support\Carbon;
use LogicException;

final class BigFiveV2EditorialWorkflow
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function createDraftFromAsset(
        BigFiveV2EditorialAssetIndexEntry $asset,
        int $createdByAdminUserId,
        array $metadata = [],
    ): BigFiveV2EditorialRevision {
        return BigFiveV2EditorialRevision::query()->create([
            'org_id' => 0,
            'asset_key' => $asset->assetKey,
            'asset_type' => $asset->assetType,
            'asset_path' => $asset->relativePath,
            'asset_sha256' => $asset->sha256,
            'version_no' => 1,
            'workflow_state' => BigFiveV2EditorialRevision::STATE_DRAFT,
            'release_snapshot_id' => $asset->linkedReleaseSnapshotIds[0] ?? null,
            'release_snapshot_hash' => $this->releaseSnapshotHash($asset->linkedReleaseSnapshotIds),
            'draft_payload_hash' => $asset->sha256,
            'created_by_admin_user_id' => $createdByAdminUserId,
            'metadata_json' => $this->safeMetadata($metadata),
        ]);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function createNextDraft(
        BigFiveV2EditorialRevision $previousRevision,
        int $createdByAdminUserId,
        array $metadata = [],
    ): BigFiveV2EditorialRevision {
        if (! $previousRevision->isTerminalState()) {
            throw new LogicException('Big Five V2 editorial revisions can only branch a new version from a terminal revision.');
        }

        return BigFiveV2EditorialRevision::query()->create([
            'org_id' => (int) $previousRevision->org_id,
            'asset_key' => (string) $previousRevision->asset_key,
            'asset_type' => (string) $previousRevision->asset_type,
            'asset_path' => (string) $previousRevision->asset_path,
            'asset_sha256' => (string) $previousRevision->asset_sha256,
            'version_no' => ((int) $previousRevision->version_no) + 1,
            'supersedes_revision_id' => (string) $previousRevision->id,
            'workflow_state' => BigFiveV2EditorialRevision::STATE_DRAFT,
            'release_snapshot_id' => $previousRevision->release_snapshot_id,
            'release_snapshot_hash' => $previousRevision->release_snapshot_hash,
            'draft_payload_hash' => $previousRevision->draft_payload_hash,
            'created_by_admin_user_id' => $createdByAdminUserId,
            'metadata_json' => $this->safeMetadata($metadata),
        ]);
    }

    public function submitForReview(
        BigFiveV2EditorialRevision $revision,
        int $submittedByAdminUserId,
        ?string $note = null,
    ): BigFiveV2EditorialRevision {
        return $this->transition(
            revision: $revision,
            targetState: BigFiveV2EditorialRevision::STATE_REVIEW,
            actorColumn: 'submitted_by_admin_user_id',
            actorId: $submittedByAdminUserId,
            timestampColumns: ['submitted_at'],
            note: $note,
        );
    }

    public function approve(
        BigFiveV2EditorialRevision $revision,
        int $reviewedByAdminUserId,
        ?string $note = null,
    ): BigFiveV2EditorialRevision {
        return $this->transition(
            revision: $revision,
            targetState: BigFiveV2EditorialRevision::STATE_APPROVED,
            actorColumn: 'reviewed_by_admin_user_id',
            actorId: $reviewedByAdminUserId,
            timestampColumns: ['reviewed_at', 'approved_at'],
            note: $note,
        );
    }

    public function reject(
        BigFiveV2EditorialRevision $revision,
        int $reviewedByAdminUserId,
        ?string $note = null,
    ): BigFiveV2EditorialRevision {
        return $this->transition(
            revision: $revision,
            targetState: BigFiveV2EditorialRevision::STATE_REJECTED,
            actorColumn: 'reviewed_by_admin_user_id',
            actorId: $reviewedByAdminUserId,
            timestampColumns: ['reviewed_at', 'rejected_at'],
            note: $note,
        );
    }

    public function archive(
        BigFiveV2EditorialRevision $revision,
        int $archivedByAdminUserId,
        ?string $note = null,
    ): BigFiveV2EditorialRevision {
        return $this->transition(
            revision: $revision,
            targetState: BigFiveV2EditorialRevision::STATE_ARCHIVED,
            actorColumn: 'archived_by_admin_user_id',
            actorId: $archivedByAdminUserId,
            timestampColumns: ['archived_at'],
            note: $note,
        );
    }

    /**
     * @return array<string,list<string>>
     */
    public function transitionMap(): array
    {
        return [
            BigFiveV2EditorialRevision::STATE_DRAFT => [
                BigFiveV2EditorialRevision::STATE_REVIEW,
                BigFiveV2EditorialRevision::STATE_ARCHIVED,
            ],
            BigFiveV2EditorialRevision::STATE_REVIEW => [
                BigFiveV2EditorialRevision::STATE_APPROVED,
                BigFiveV2EditorialRevision::STATE_REJECTED,
                BigFiveV2EditorialRevision::STATE_ARCHIVED,
            ],
            BigFiveV2EditorialRevision::STATE_APPROVED => [
                BigFiveV2EditorialRevision::STATE_ARCHIVED,
            ],
            BigFiveV2EditorialRevision::STATE_REJECTED => [
                BigFiveV2EditorialRevision::STATE_ARCHIVED,
            ],
            BigFiveV2EditorialRevision::STATE_ARCHIVED => [],
        ];
    }

    /**
     * @param  list<string>  $releaseSnapshotIds
     */
    private function releaseSnapshotHash(array $releaseSnapshotIds): ?string
    {
        if ($releaseSnapshotIds === []) {
            return null;
        }

        return hash('sha256', implode('|', $releaseSnapshotIds));
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        unset(
            $metadata['internal_metadata'],
            $metadata['selector_basis'],
            $metadata['source_reference'],
            $metadata['runtime_use'],
            $metadata['production_use_allowed'],
            $metadata['review_status'],
            $metadata['qa_notes']
        );

        return $metadata;
    }

    /**
     * @param  list<string>  $timestampColumns
     */
    private function transition(
        BigFiveV2EditorialRevision $revision,
        string $targetState,
        string $actorColumn,
        int $actorId,
        array $timestampColumns,
        ?string $note,
    ): BigFiveV2EditorialRevision {
        $currentState = (string) $revision->workflow_state;
        if (! in_array($targetState, $this->transitionMap()[$currentState] ?? [], true)) {
            throw new LogicException(sprintf(
                'Invalid Big Five V2 editorial workflow transition from %s to %s.',
                $currentState,
                $targetState
            ));
        }

        $now = Carbon::now();
        $revision->workflow_state = $targetState;
        $revision->{$actorColumn} = $actorId;
        $revision->decision_note = $note;

        foreach ($timestampColumns as $column) {
            $revision->{$column} = $now;
        }

        $revision->save();

        return $revision->refresh();
    }
}
