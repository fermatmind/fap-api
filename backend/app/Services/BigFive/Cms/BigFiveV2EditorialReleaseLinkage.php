<?php

declare(strict_types=1);

namespace App\Services\BigFive\Cms;

use App\Models\BigFiveV2EditorialRevision;
use RuntimeException;

final class BigFiveV2EditorialReleaseLinkage
{
    private const POLICY_PATH = 'backend/content_assets/big5/result_page_v2/governance/cms_release_linkage_v0_1/big5_v2_cms_release_linkage_policy_v0_1.json';

    private const IMPORT_GATE_POLICY_PATH = 'backend/content_assets/big5/result_page_v2/governance/production_import_gate_v0_1/big5_v2_production_import_gate_policy_v0_1.json';

    /**
     * @return array<string,mixed>
     */
    public function exportPlan(BigFiveV2EditorialRevision $revision): array
    {
        $this->assertExportable($revision);

        return [
            'schema_version' => 'big5_v2_cms_release_linkage_export_plan.v0_1',
            'linkage_mode' => 'cms_export_to_git_backed_release_candidate',
            'cms_export_only' => true,
            'cms_runtime_owner' => false,
            'direct_runtime_publish_allowed' => false,
            'release_snapshot_linked' => true,
            'import_gate_linked' => true,
            'runtime_gate_required' => true,
            'manual_release_approval_required' => true,
            'editorial_revision' => [
                'id' => (string) $revision->id,
                'asset_key' => (string) $revision->asset_key,
                'asset_path' => (string) $revision->asset_path,
                'version_no' => (int) $revision->version_no,
                'workflow_state' => (string) $revision->workflow_state,
            ],
            'git_backed_release_snapshot' => [
                'snapshot_id' => (string) $revision->release_snapshot_id,
                'snapshot_hash' => (string) $revision->release_snapshot_hash,
                'immutable_required' => true,
            ],
            'governance_linkage' => [
                'policy_path' => self::POLICY_PATH,
                'import_gate_policy_path' => self::IMPORT_GATE_POLICY_PATH,
                'release_snapshot_required_before_runtime' => true,
                'import_gate_required_before_runtime' => true,
                'runtime_gate_required_before_exposure' => true,
            ],
            'approval_evidence' => [
                'approved' => true,
                'approved_audit_event_present' => $this->hasAuditAction($revision, 'approved'),
            ],
        ];
    }

    public function canExportReleaseCandidate(BigFiveV2EditorialRevision $revision): bool
    {
        try {
            $this->assertExportable($revision);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function assertExportable(BigFiveV2EditorialRevision $revision): void
    {
        if ($revision->workflow_state !== BigFiveV2EditorialRevision::STATE_APPROVED) {
            throw new RuntimeException('Big Five V2 CMS release linkage requires an approved editorial revision.');
        }

        if (! $revision->hasReleaseSnapshotLinkage()) {
            throw new RuntimeException('Big Five V2 CMS release linkage requires immutable release snapshot linkage.');
        }

        if (! $this->hasAuditAction($revision, 'approved')) {
            throw new RuntimeException('Big Five V2 CMS release linkage requires approval audit evidence.');
        }
    }

    private function hasAuditAction(BigFiveV2EditorialRevision $revision, string $action): bool
    {
        $metadata = is_array($revision->metadata_json) ? $revision->metadata_json : [];
        foreach ((array) ($metadata['editorial_audit_trail'] ?? []) as $event) {
            if (is_array($event) && ($event['action'] ?? null) === $action) {
                return true;
            }
        }

        return false;
    }
}
