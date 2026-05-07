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
use App\Services\BigFive\Cms\BigFiveV2EditorialRollbackAudit;
use App\Services\BigFive\Cms\BigFiveV2EditorialWorkflow;
use App\Support\Rbac\PermissionNames;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class BigFiveV2EditorialRollbackAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_DIR = 'content_assets/big5/result_page_v2/qa/cms_rollback_audit_v0_1';

    public function test_rollback_archive_records_release_based_audit_evidence(): void
    {
        [$approved, $reviewer, $rollbacker] = $this->approvedRevisionReviewerAndRollbacker();

        $evidence = (new BigFiveV2EditorialRollbackAudit)->archiveForRollback(
            $rollbacker,
            $approved,
            'rollback to previous Git-backed release snapshot'
        );

        $this->assertSame('big5_v2_cms_editorial_rollback_audit.v0_1', $evidence['schema_version']);
        $this->assertSame('editorial_release_based_rollback', $evidence['audit_mode']);
        $this->assertFalse($evidence['runtime_mutation_allowed']);
        $this->assertFalse($evidence['direct_runtime_publish_allowed']);
        $this->assertTrue($evidence['release_snapshot_rollback_required']);
        $this->assertSame(BigFiveV2EditorialRevision::STATE_ARCHIVED, $evidence['editorial_revision']['workflow_state']);
        $this->assertTrue($evidence['audit_evidence']['approval_audit_present']);
        $this->assertTrue($evidence['audit_evidence']['release_audit_present']);
        $this->assertTrue($evidence['audit_evidence']['rollback_audit_present']);
        $this->assertSame((int) $rollbacker->id, $evidence['rollback_authority']['rollback_actor_admin_user_id']);
        $this->assertTrue($evidence['rollback_authority']['role_separation_verified']);
        $this->assertNotSame((int) $reviewer->id, $evidence['rollback_authority']['rollback_actor_admin_user_id']);
        $this->assertSame('git_backed_release_snapshot_revert', $evidence['runtime_isolation']['rollback_path']);
    }

    public function test_rollback_audit_rejects_missing_approval_or_rollback_evidence(): void
    {
        [$approved] = $this->approvedRevisionReviewerAndRollbacker();
        $service = new BigFiveV2EditorialRollbackAudit;

        $this->assertFalse($service->canProduceEvidence($approved));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('archived revision');

        $service->evidencePackage($approved);
    }

    public function test_under_privileged_actor_cannot_archive_for_rollback(): void
    {
        [$approved] = $this->approvedRevisionReviewerAndRollbacker();
        $underPrivileged = $this->adminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);

        $this->expectException(AuthorizationException::class);

        (new BigFiveV2EditorialRollbackAudit)->archiveForRollback($underPrivileged, $approved);
    }

    public function test_reviewer_cannot_archive_for_rollback(): void
    {
        [$approved, $reviewer] = $this->approvedRevisionReviewerAndRollbacker();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('role separation');

        (new BigFiveV2EditorialRollbackAudit)->archiveForRollback($reviewer, $approved);
    }

    public function test_evidence_rejects_historical_rollback_without_role_separation(): void
    {
        [$approved, $reviewer] = $this->approvedRevisionReviewerAndRollbacker();
        $flow = new BigFiveV2EditorialApprovalFlow;
        $trail = $flow->auditTrail($approved);
        $trail[] = [
            'action' => 'rollback_archived',
            'actor_admin_user_id' => (int) $reviewer->id,
            'from_state' => BigFiveV2EditorialRevision::STATE_APPROVED,
            'to_state' => BigFiveV2EditorialRevision::STATE_ARCHIVED,
            'note' => 'legacy rollback audit fixture',
            'occurred_at' => '2026-05-07T00:00:00Z',
        ];

        $approved->workflow_state = BigFiveV2EditorialRevision::STATE_ARCHIVED;
        $approved->archived_by_admin_user_id = (int) $reviewer->id;
        $approved->metadata_json = ['editorial_audit_trail' => $trail];
        $approved->save();

        $service = new BigFiveV2EditorialRollbackAudit;

        $this->assertFalse($service->canProduceEvidence($approved->refresh()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('role separation');

        $service->evidencePackage($approved);
    }

    public function test_qa_policy_package_exists_and_sha256sums_are_reproducible(): void
    {
        $manifest = $this->jsonFile(self::PACKAGE_DIR.'/manifest.json');
        $policy = $this->jsonFile(self::PACKAGE_DIR.'/big5_v2_cms_rollback_audit_policy_v0_1.json');
        $evidence = $this->jsonFile(self::PACKAGE_DIR.'/big5_v2_cms_rollback_audit_evidence_v0_1.json');

        $this->assertSame('editorial_governance', $manifest['mode'] ?? null);
        $this->assertFalse((bool) ($manifest['runtime_mutation_allowed'] ?? true));
        $this->assertFalse((bool) ($manifest['direct_runtime_publish_allowed'] ?? true));
        $this->assertTrue((bool) ($policy['required_audits']['approval_audit'] ?? false));
        $this->assertTrue((bool) ($policy['required_audits']['release_audit'] ?? false));
        $this->assertTrue((bool) ($policy['required_audits']['rollback_audit'] ?? false));
        $this->assertSame('git_backed_release_snapshot_revert', $evidence['rollback_path'] ?? null);
        $this->assertContains('cms_runtime_gate_bypass', $policy['forbidden_paths'] ?? []);

        foreach (explode("\n", trim((string) file_get_contents(base_path(self::PACKAGE_DIR.'/SHA256SUMS')))) as $line) {
            [$expectedHash, $fileName] = preg_split('/\s+/', trim($line), 2);
            $this->assertSame(
                $expectedHash,
                hash_file('sha256', base_path(self::PACKAGE_DIR.'/'.$fileName)),
                $fileName
            );
        }
    }

    public function test_rollback_evidence_does_not_expose_forbidden_metadata_or_enable_runtime(): void
    {
        [$approved, , $rollbacker] = $this->approvedRevisionReviewerAndRollbacker();
        $evidence = (new BigFiveV2EditorialRollbackAudit)->archiveForRollback($rollbacker, $approved);
        $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ([
            'internal_metadata',
            'selector_basis',
            'source_reference',
            'runtime_use',
            'production_use_allowed',
            'review_status',
            'qa_notes',
        ] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $encoded, $forbiddenKey);
        }

        $this->assertFalse((bool) $evidence['direct_runtime_publish_allowed']);
        $this->assertFalse((bool) $evidence['runtime_isolation']['cms_can_mutate_runtime']);
        $this->assertFalse((bool) $evidence['runtime_isolation']['cms_can_publish_runtime']);
    }

    public function test_runtime_and_production_remain_disabled(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
    }

    /**
     * @return array{0:BigFiveV2EditorialRevision,1:AdminUser,2:AdminUser}
     */
    private function approvedRevisionReviewerAndRollbacker(): array
    {
        $editor = $this->adminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $reviewer = $this->adminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $rollbacker = $this->adminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $workflow = new BigFiveV2EditorialWorkflow;
        $flow = new BigFiveV2EditorialApprovalFlow;
        $draft = $workflow->createDraftFromAsset($this->linkedAsset(), (int) $editor->id);
        $review = $flow->submitForReview($editor, $draft, 'rollback audit candidate');

        return [
            $flow->approve($reviewer, $review, 'approved for release-based rollback evidence'),
            $reviewer,
            $rollbacker,
        ];
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function adminWithPermissions(array $permissionNames): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'CMS Rollback Admin '.uniqid('', true),
            'email' => uniqid('cms_rollback_admin_', true).'@example.test',
            'password' => 'secret',
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'cms_rollback_role_'.str_replace('.', '_', uniqid('', true)),
            'description' => 'Big Five V2 CMS rollback audit test role',
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

    private function linkedAsset(): BigFiveV2EditorialAssetIndexEntry
    {
        foreach ((new BigFiveV2EditorialAssetIndex)->entries() as $entry) {
            if ($entry->linkedReleaseSnapshotIds !== []) {
                return $entry;
            }
        }

        $this->fail('Expected at least one Big Five V2 asset linked to an immutable release snapshot.');
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(base_path($path)), true);

        return is_array($decoded) ? $decoded : [];
    }
}
