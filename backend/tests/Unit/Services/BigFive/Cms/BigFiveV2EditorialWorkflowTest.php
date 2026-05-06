<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\Cms;

use App\Models\BigFiveV2EditorialAssetIndexEntry;
use App\Models\BigFiveV2EditorialRevision;
use App\Services\BigFive\Cms\BigFiveV2EditorialAssetIndex;
use App\Services\BigFive\Cms\BigFiveV2EditorialWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class BigFiveV2EditorialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'big_five_v2_editorial_revisions';

    public function test_revision_schema_exists_without_runtime_payload_or_public_flags(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));

        foreach ([
            'id',
            'org_id',
            'asset_key',
            'asset_type',
            'asset_path',
            'asset_sha256',
            'version_no',
            'supersedes_revision_id',
            'workflow_state',
            'release_snapshot_id',
            'release_snapshot_hash',
            'draft_payload_hash',
            'created_by_admin_user_id',
            'submitted_by_admin_user_id',
            'reviewed_by_admin_user_id',
            'archived_by_admin_user_id',
            'submitted_at',
            'reviewed_at',
            'approved_at',
            'rejected_at',
            'archived_at',
            'decision_note',
            'metadata_json',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn(self::TABLE, $column), $column);
        }

        foreach ([
            'content_body',
            'runtime_payload',
            'runtime_use',
            'production_use_allowed',
            'ready_for_production',
            'internal_metadata',
            'selector_basis',
            'source_reference',
            'review_status',
            'qa_notes',
        ] as $forbiddenColumn) {
            $this->assertFalse(Schema::hasColumn(self::TABLE, $forbiddenColumn), $forbiddenColumn);
        }
    }

    public function test_create_draft_from_read_only_asset_preserves_release_linkage_without_runtime_publish(): void
    {
        $revision = $this->workflow()->createDraftFromAsset($this->linkedAsset(), 101, [
            'editorial_note' => 'scope-only',
            'internal_metadata' => ['hidden' => true],
            'production_use_allowed' => true,
            'qa_notes' => 'hidden',
        ]);

        $this->assertSame(BigFiveV2EditorialRevision::STATE_DRAFT, $revision->workflow_state);
        $this->assertSame(1, $revision->version_no);
        $this->assertSame(101, $revision->created_by_admin_user_id);
        $this->assertTrue($revision->hasReleaseSnapshotLinkage());
        $this->assertFalse($revision->isRuntimeMutable());
        $this->assertFalse($revision->canPublishToRuntime());
        $this->assertSame(['editorial_note' => 'scope-only'], $revision->metadata_json);
    }

    public function test_workflow_transitions_validate_review_approval_rejection_and_archive_states(): void
    {
        $workflow = $this->workflow();
        $revision = $workflow->createDraftFromAsset($this->linkedAsset(), 101);

        $review = $workflow->submitForReview($revision, 102, 'ready for review');
        $this->assertSame(BigFiveV2EditorialRevision::STATE_REVIEW, $review->workflow_state);
        $this->assertSame(102, $review->submitted_by_admin_user_id);
        $this->assertNotNull($review->submitted_at);

        $approved = $workflow->approve($review, 103, 'approved for release candidate export only');
        $this->assertSame(BigFiveV2EditorialRevision::STATE_APPROVED, $approved->workflow_state);
        $this->assertSame(103, $approved->reviewed_by_admin_user_id);
        $this->assertNotNull($approved->approved_at);

        $archived = $workflow->archive($approved, 104, 'superseded by next draft');
        $this->assertSame(BigFiveV2EditorialRevision::STATE_ARCHIVED, $archived->workflow_state);
        $this->assertSame(104, $archived->archived_by_admin_user_id);
        $this->assertNotNull($archived->archived_at);
    }

    public function test_invalid_transition_and_release_linkage_mutation_are_rejected(): void
    {
        $workflow = $this->workflow();
        $revision = $workflow->createDraftFromAsset($this->linkedAsset(), 101);

        $this->expectException(LogicException::class);
        $workflow->approve($revision, 102);
    }

    public function test_next_version_preserves_lineage_and_immutable_release_linkage(): void
    {
        $workflow = $this->workflow();
        $revision = $workflow->approve(
            $workflow->submitForReview($workflow->createDraftFromAsset($this->linkedAsset(), 101), 102),
            103
        );

        $next = $workflow->createNextDraft($revision, 104, ['lineage' => 'v2']);

        $this->assertSame(2, $next->version_no);
        $this->assertSame((string) $revision->id, $next->supersedes_revision_id);
        $this->assertSame($revision->release_snapshot_id, $next->release_snapshot_id);
        $this->assertSame($revision->release_snapshot_hash, $next->release_snapshot_hash);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');

        $next->release_snapshot_id = 'manual-runtime-bypass';
        $next->save();
    }

    public function test_runtime_and_production_rollout_remain_disabled(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
    }

    private function workflow(): BigFiveV2EditorialWorkflow
    {
        return new BigFiveV2EditorialWorkflow();
    }

    private function linkedAsset(): BigFiveV2EditorialAssetIndexEntry
    {
        foreach ((new BigFiveV2EditorialAssetIndex())->entries() as $entry) {
            if ($entry->linkedReleaseSnapshotIds !== []) {
                return $entry;
            }
        }

        $this->fail('Expected at least one Big Five V2 asset linked to an immutable release snapshot.');
    }
}
