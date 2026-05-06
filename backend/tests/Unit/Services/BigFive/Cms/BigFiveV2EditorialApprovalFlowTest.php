<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\Cms;

use App\Models\AdminUser;
use App\Models\BigFiveV2EditorialAssetIndexEntry;
use App\Models\BigFiveV2EditorialRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Services\BigFive\Cms\BigFiveV2EditorialApprovalFlow;
use App\Services\BigFive\Cms\BigFiveV2EditorialAssetIndex;
use App\Services\BigFive\Cms\BigFiveV2EditorialWorkflow;
use App\Support\Rbac\PermissionNames;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class BigFiveV2EditorialApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_reviewer_publisher_and_rollback_capabilities_are_separated(): void
    {
        $editor = $this->adminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $reviewer = $this->adminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $publisher = $this->adminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);
        $flow = $this->flow();

        $draft = $this->draftFor($editor);
        $this->assertSame([
            'view' => true,
            'create_draft' => true,
            'submit_for_review' => true,
            'approve' => false,
            'reject' => false,
            'rollback' => false,
            'export_release_candidate' => false,
            'publish_to_runtime' => false,
        ], $flow->capabilityMap($editor, $draft));

        $review = $flow->submitForReview($editor, $draft, 'ready');
        $this->assertTrue($flow->capabilityMap($reviewer, $review)['approve']);
        $this->assertTrue($flow->capabilityMap($reviewer, $review)['reject']);
        $this->assertFalse($flow->capabilityMap($reviewer, $review)['publish_to_runtime']);

        $approved = $flow->approve($reviewer, $review, 'release candidate export only');
        $this->assertTrue($flow->capabilityMap($publisher, $approved)['export_release_candidate']);
        $this->assertFalse($flow->capabilityMap($publisher, $approved)['publish_to_runtime']);
    }

    public function test_approval_flow_records_audit_trail_without_runtime_publish(): void
    {
        $editor = $this->adminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $reviewer = $this->adminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $flow = $this->flow();

        $review = $flow->submitForReview($editor, $this->draftFor($editor), 'submit note');
        $approved = $flow->approve($reviewer, $review, 'approval note');

        $this->assertSame(BigFiveV2EditorialRevision::STATE_APPROVED, $approved->workflow_state);
        $this->assertFalse($approved->canPublishToRuntime());

        $trail = $flow->auditTrail($approved);
        $this->assertCount(2, $trail);
        $this->assertSame('submitted_for_review', $trail[0]['action']);
        $this->assertSame('approved', $trail[1]['action']);
        $this->assertSame((int) $editor->id, $trail[0]['actor_admin_user_id']);
        $this->assertSame((int) $reviewer->id, $trail[1]['actor_admin_user_id']);
    }

    public function test_self_approval_and_under_privileged_approval_are_rejected(): void
    {
        $editor = $this->adminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $underPrivileged = $this->adminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $dualRole = $this->adminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $flow = $this->flow();

        $review = $flow->submitForReview($editor, $this->draftFor($editor));

        try {
            $flow->approve($underPrivileged, $review);
            $this->fail('Expected under-privileged approval to be rejected.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('Not authorized', $e->getMessage());
        }

        $selfReview = $flow->submitForReview($dualRole, $this->draftFor($dualRole));

        try {
            $flow->approve($dualRole, $selfReview);
            $this->fail('Expected self-approval to be rejected.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('role separation', $e->getMessage());
        }
    }

    public function test_rejection_and_rollback_archive_require_approval_authority(): void
    {
        $editor = $this->adminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $reviewer = $this->adminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $flow = $this->flow();

        $review = $flow->submitForReview($editor, $this->draftFor($editor), 'needs review');
        $rejected = $flow->reject($reviewer, $review, 'needs revision');
        $this->assertSame(BigFiveV2EditorialRevision::STATE_REJECTED, $rejected->workflow_state);

        $archived = $flow->archiveForRollback($reviewer, $rejected, 'rollback audit archive');
        $this->assertSame(BigFiveV2EditorialRevision::STATE_ARCHIVED, $archived->workflow_state);

        $trail = $flow->auditTrail($archived);
        $this->assertSame(['submitted_for_review', 'rejected', 'rollback_archived'], array_column($trail, 'action'));
        $this->assertFalse($archived->isRuntimeMutable());
        $this->assertFalse($archived->canPublishToRuntime());
    }

    public function test_runtime_and_production_remain_disabled(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function adminWithPermissions(array $permissionNames): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'CMS Admin '.uniqid('', true),
            'email' => uniqid('cms_admin_', true).'@example.test',
            'password' => 'secret',
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'cms_role_'.str_replace('.', '_', uniqid('', true)),
            'description' => 'Big Five V2 CMS test role',
        ]);

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => $permissionName]
            );
            $role->permissions()->syncWithoutDetaching([(int) $permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([(int) $role->id]);

        return $admin->refresh();
    }

    private function draftFor(AdminUser $editor): BigFiveV2EditorialRevision
    {
        return (new BigFiveV2EditorialWorkflow())->createDraftFromAsset($this->linkedAsset(), (int) $editor->id);
    }

    private function flow(): BigFiveV2EditorialApprovalFlow
    {
        return new BigFiveV2EditorialApprovalFlow();
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
