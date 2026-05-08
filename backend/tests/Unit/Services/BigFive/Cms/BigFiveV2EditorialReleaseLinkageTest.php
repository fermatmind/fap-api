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
use App\Services\BigFive\Cms\BigFiveV2EditorialReleaseLinkage;
use App\Services\BigFive\Cms\BigFiveV2EditorialWorkflow;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class BigFiveV2EditorialReleaseLinkageTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_DIR = 'content_assets/big5/result_page_v2/governance/cms_release_linkage_v0_1';

    public function test_approved_revision_builds_export_only_release_linkage_plan(): void
    {
        $revision = $this->approvedRevision();
        $plan = (new BigFiveV2EditorialReleaseLinkage)->exportPlan($revision);

        $this->assertSame('big5_v2_cms_release_linkage_export_plan.v0_1', $plan['schema_version']);
        $this->assertTrue($plan['cms_export_only']);
        $this->assertFalse($plan['cms_runtime_owner']);
        $this->assertFalse($plan['direct_runtime_publish_allowed']);
        $this->assertTrue($plan['release_snapshot_linked']);
        $this->assertTrue($plan['import_gate_linked']);
        $this->assertTrue($plan['runtime_gate_required']);
        $this->assertSame((string) $revision->release_snapshot_id, $plan['git_backed_release_snapshot']['snapshot_id']);
        $this->assertSame((string) $revision->release_snapshot_hash, $plan['git_backed_release_snapshot']['snapshot_hash']);
        $this->assertSame(
            'backend/content_assets/big5/result_page_v2/governance/production_import_gate_v0_1/big5_v2_production_import_gate_policy_v0_1.json',
            $plan['governance_linkage']['import_gate_policy_path']
        );
        $this->assertTrue($plan['approval_evidence']['approved']);
        $this->assertTrue($plan['approval_evidence']['approved_audit_event_present']);
    }

    public function test_release_linkage_rejects_unapproved_or_missing_audit_revisions(): void
    {
        $service = new BigFiveV2EditorialReleaseLinkage;
        $draft = (new BigFiveV2EditorialWorkflow)->createDraftFromAsset($this->linkedAsset(), 501);

        $this->assertFalse($service->canExportReleaseCandidate($draft));

        $this->expectException(RuntimeException::class);
        $service->exportPlan($draft);
    }

    public function test_release_linkage_requires_approval_audit_evidence(): void
    {
        $workflow = new BigFiveV2EditorialWorkflow;
        $revision = $workflow->approve(
            $workflow->submitForReview($workflow->createDraftFromAsset($this->linkedAsset(), 501), 502),
            503
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approval audit');

        (new BigFiveV2EditorialReleaseLinkage)->exportPlan($revision);
    }

    public function test_policy_package_exists_and_sha256sums_are_reproducible(): void
    {
        $manifest = $this->jsonFile(self::PACKAGE_DIR.'/manifest.json');
        $policy = $this->jsonFile(self::PACKAGE_DIR.'/big5_v2_cms_release_linkage_policy_v0_1.json');

        $this->assertSame('editorial_governance', $manifest['mode'] ?? null);
        $this->assertTrue((bool) ($manifest['cms_export_only'] ?? false));
        $this->assertFalse((bool) ($manifest['cms_runtime_owner'] ?? true));
        $this->assertFalse((bool) ($manifest['direct_runtime_publish_allowed'] ?? true));
        $this->assertTrue((bool) ($policy['git_backed_source_of_truth'] ?? false));
        $this->assertTrue((bool) data_get($policy, 'release_candidate_export.requires_import_gate'));
        $this->assertTrue((bool) data_get($policy, 'release_candidate_export.requires_runtime_gate'));
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

    public function test_release_linkage_outputs_do_not_expose_forbidden_metadata_or_enable_runtime(): void
    {
        $plan = (new BigFiveV2EditorialReleaseLinkage)->exportPlan($this->approvedRevision());
        $encoded = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

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

        $this->assertFalse((bool) $plan['direct_runtime_publish_allowed']);
    }

    public function test_runtime_and_production_remain_disabled(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
    }

    private function approvedRevision(): BigFiveV2EditorialRevision
    {
        $editor = $this->adminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $reviewer = $this->adminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $workflow = new BigFiveV2EditorialWorkflow;
        $flow = new BigFiveV2EditorialApprovalFlow;
        $draft = $workflow->createDraftFromAsset($this->linkedAsset(), (int) $editor->id);
        $review = $flow->submitForReview($editor, $draft, 'linkage candidate');

        return $flow->approve($reviewer, $review, 'approved for Git-backed export only');
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function adminWithPermissions(array $permissionNames): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'CMS Release Admin '.uniqid('', true),
            'email' => uniqid('cms_release_admin_', true).'@example.test',
            'password' => 'secret',
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'cms_release_role_'.str_replace('.', '_', uniqid('', true)),
            'description' => 'Big Five V2 CMS release linkage test role',
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
