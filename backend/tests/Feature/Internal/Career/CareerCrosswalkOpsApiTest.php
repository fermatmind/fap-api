<?php

declare(strict_types=1);

namespace Tests\Feature\Internal\Career;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerCrosswalkOpsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_queue_requires_internal_admin_auth(): void
    {
        $response = $this->getJson('/api/v0.5/internal/career/crosswalk/review-queue');

        $response->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'UNAUTHORIZED');
    }

    public function test_internal_crosswalk_endpoints_support_queue_patch_and_override_flow(): void
    {
        $fixture = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'ops-flow-role',
            'crosswalk_mode' => 'local_heavy_interpretation',
        ]);
        $slug = (string) $fixture['occupation']->canonical_slug;
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $releaseAdmin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_RELEASE]);

        $queueResponse = $this->withSession(['ops_org_id' => 31])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/internal/career/crosswalk/review-queue');

        $queueResponse->assertOk()
            ->assertJsonPath('queue_kind', 'career_crosswalk_review_queue_read_model');

        $createResponse = $this->withSession(['ops_org_id' => 31])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/internal/career/crosswalk/patches', [
                'subject_kind' => 'career_job_detail',
                'subject_slug' => $slug,
                'target_kind' => 'occupation',
                'target_slug' => $slug,
                'crosswalk_mode_override' => 'exact',
                'review_notes' => 'ops console patch create',
            ]);

        $createResponse->assertOk()
            ->assertJsonPath('mutation_kind', 'career_crosswalk_patch_create')
            ->assertJsonPath('patch.subject_slug', $slug)
            ->assertJsonPath('patch.patch_status', 'draft');

        $patchKey = (string) $createResponse->json('patch.patch_key');
        $this->assertNotSame('', $patchKey);

        $approveResponse = $this->withSession(['ops_org_id' => 31])
            ->actingAs($releaseAdmin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/internal/career/crosswalk/patches/'.$patchKey.'/approve', [
                'review_notes' => 'approve from ops console',
            ]);

        $approveResponse->assertOk()
            ->assertJsonPath('mutation_kind', 'career_crosswalk_patch_approve')
            ->assertJsonPath('patch.patch_status', 'approved');

        $historyResponse = $this->withSession(['ops_org_id' => 31])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/internal/career/crosswalk/patches/'.$slug);
        $historyResponse->assertOk()
            ->assertJsonPath('history_kind', 'career_editorial_patch_history')
            ->assertJson(fn ($json) => $json->where('status_counts.approved', 1)->etc());

        $overrideResponse = $this->withSession(['ops_org_id' => 31])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/internal/career/crosswalk/override/'.$slug);
        $overrideResponse->assertOk()
            ->assertJsonPath('override_kind', 'career_crosswalk_override_read_model')
            ->assertJsonPath('override_applied', true)
            ->assertJsonPath('resolved_crosswalk_mode', 'exact');

        $rejectResponse = $this->withSession(['ops_org_id' => 31])
            ->actingAs($releaseAdmin, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/internal/career/crosswalk/patches/'.$patchKey.'/reject', [
                'review_notes' => 'reject after approval should still set rejected',
            ]);

        $rejectResponse->assertOk()
            ->assertJsonPath('mutation_kind', 'career_crosswalk_patch_reject')
            ->assertJsonPath('patch.patch_status', 'rejected');
    }

    public function test_content_write_admin_cannot_approve_or_reject_crosswalk_patches(): void
    {
        $fixture = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'ops-approval-split',
            'crosswalk_mode' => 'local_heavy_interpretation',
        ]);
        $slug = (string) $fixture['occupation']->canonical_slug;
        $writer = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);

        $createResponse = $this->withSession(['ops_org_id' => 32])
            ->actingAs($writer, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/internal/career/crosswalk/patches', [
                'subject_kind' => 'career_job_detail',
                'subject_slug' => $slug,
                'target_kind' => 'occupation',
                'target_slug' => $slug,
                'crosswalk_mode_override' => 'exact',
                'review_notes' => 'writer can draft only',
            ]);

        $createResponse->assertOk()
            ->assertJsonPath('patch.patch_status', 'draft');

        $patchKey = (string) $createResponse->json('patch.patch_key');

        $this->withSession(['ops_org_id' => 32])
            ->actingAs($writer, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/internal/career/crosswalk/patches/'.$patchKey.'/approve', [
                'review_notes' => 'writer should not approve',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN')
            ->assertJsonPath('message', 'admin_content_release_required');

        $this->withSession(['ops_org_id' => 32])
            ->actingAs($writer, (string) config('admin.guard', 'admin'))
            ->postJson('/api/v0.5/internal/career/crosswalk/patches/'.$patchKey.'/reject', [
                'review_notes' => 'writer should not reject',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN')
            ->assertJsonPath('message', 'admin_content_release_required');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        if ($permissions === []) {
            return $admin;
        }

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(10)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
