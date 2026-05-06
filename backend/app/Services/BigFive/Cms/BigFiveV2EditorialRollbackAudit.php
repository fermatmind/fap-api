<?php

declare(strict_types=1);

namespace App\Services\BigFive\Cms;

use App\Models\AdminUser;
use App\Models\BigFiveV2EditorialRevision;
use RuntimeException;

final class BigFiveV2EditorialRollbackAudit
{
    private const RELEASE_LINKAGE_POLICY_PATH = 'backend/content_assets/big5/result_page_v2/governance/cms_release_linkage_v0_1/big5_v2_cms_release_linkage_policy_v0_1.json';

    private const RELEASE_EVIDENCE_ARCHIVE_PATH = 'backend/content_assets/big5/result_page_v2/qa/release_evidence_archive/v0_1/big5_v2_release_evidence_archive_v0_1.json';

    public function __construct(
        private readonly BigFiveV2EditorialApprovalFlow $approvalFlow = new BigFiveV2EditorialApprovalFlow(),
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function archiveForRollback(
        AdminUser $actor,
        BigFiveV2EditorialRevision $revision,
        ?string $note = null,
    ): array {
        $archived = $this->approvalFlow->archiveForRollback($actor, $revision, $note);

        return $this->evidencePackage($archived);
    }

    /**
     * @return array<string,mixed>
     */
    public function evidencePackage(BigFiveV2EditorialRevision $revision): array
    {
        $trail = $this->approvalFlow->auditTrail($revision);
        $this->assertEvidenceComplete($revision, $trail);

        return [
            'schema_version' => 'big5_v2_cms_editorial_rollback_audit.v0_1',
            'audit_mode' => 'editorial_release_based_rollback',
            'runtime_mutation_allowed' => false,
            'direct_runtime_publish_allowed' => false,
            'release_snapshot_rollback_required' => true,
            'editorial_revision' => [
                'id' => (string) $revision->id,
                'asset_key' => (string) $revision->asset_key,
                'asset_path' => (string) $revision->asset_path,
                'version_no' => (int) $revision->version_no,
                'workflow_state' => (string) $revision->workflow_state,
            ],
            'release_evidence_linkage' => [
                'release_snapshot_id' => (string) $revision->release_snapshot_id,
                'release_snapshot_hash' => (string) $revision->release_snapshot_hash,
                'release_linkage_policy_path' => self::RELEASE_LINKAGE_POLICY_PATH,
                'release_evidence_archive_path' => self::RELEASE_EVIDENCE_ARCHIVE_PATH,
                'immutable_release_required' => true,
            ],
            'audit_evidence' => [
                'approval_audit_present' => $this->hasAuditAction($trail, 'approved'),
                'release_audit_present' => $revision->hasReleaseSnapshotLinkage(),
                'rollback_audit_present' => $this->hasAuditAction($trail, 'rollback_archived'),
                'audit_trail' => $this->publicAuditTrail($trail),
            ],
            'rollback_authority' => [
                'required_permissions' => [
                    'admin.approval.review',
                    'admin.content.release',
                ],
                'rollback_actor_admin_user_id' => $this->lastActorFor($trail, 'rollback_archived'),
                'role_separation_required' => true,
            ],
            'runtime_isolation' => [
                'cms_can_mutate_runtime' => false,
                'cms_can_publish_runtime' => false,
                'rollback_path' => 'git_backed_release_snapshot_revert',
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $trail
     */
    public function canProduceEvidence(BigFiveV2EditorialRevision $revision, array $trail = []): bool
    {
        try {
            $this->assertEvidenceComplete(
                $revision,
                $trail === [] ? $this->approvalFlow->auditTrail($revision) : $trail
            );

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $trail
     */
    private function assertEvidenceComplete(BigFiveV2EditorialRevision $revision, array $trail): void
    {
        if ($revision->workflow_state !== BigFiveV2EditorialRevision::STATE_ARCHIVED) {
            throw new RuntimeException('Big Five V2 editorial rollback evidence requires an archived revision.');
        }

        if (! $revision->hasReleaseSnapshotLinkage()) {
            throw new RuntimeException('Big Five V2 editorial rollback evidence requires immutable release snapshot linkage.');
        }

        if (! $this->hasAuditAction($trail, 'approved')) {
            throw new RuntimeException('Big Five V2 editorial rollback evidence requires approval audit evidence.');
        }

        if (! $this->hasAuditAction($trail, 'rollback_archived')) {
            throw new RuntimeException('Big Five V2 editorial rollback evidence requires rollback audit evidence.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $trail
     */
    private function hasAuditAction(array $trail, string $action): bool
    {
        foreach ($trail as $event) {
            if (($event['action'] ?? null) === $action) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $trail
     */
    private function lastActorFor(array $trail, string $action): ?int
    {
        $actorId = null;
        foreach ($trail as $event) {
            if (($event['action'] ?? null) === $action) {
                $actorId = (int) ($event['actor_admin_user_id'] ?? 0);
            }
        }

        return $actorId > 0 ? $actorId : null;
    }

    /**
     * @param  list<array<string,mixed>>  $trail
     * @return list<array<string,mixed>>
     */
    private function publicAuditTrail(array $trail): array
    {
        return array_map(static fn (array $event): array => [
            'action' => (string) ($event['action'] ?? ''),
            'actor_admin_user_id' => (int) ($event['actor_admin_user_id'] ?? 0),
            'from_state' => (string) ($event['from_state'] ?? ''),
            'to_state' => (string) ($event['to_state'] ?? ''),
            'occurred_at' => (string) ($event['occurred_at'] ?? ''),
        ], $trail);
    }
}
