<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\AdminUser;
use App\Models\ContentPackVersion;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentPackControlPlaneWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_pack_version_workspace_renders_control_plane_surface_for_authorized_admin(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);
        $selectedOrg = $this->createSelectedOrg();

        $version = ContentPackVersion::query()->create([
            'id' => (string) Str::uuid(),
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'content_package_version' => 'content_2026_03',
            'dir_version_alias' => 'MBTI-CN-v0.3-control-plane',
            'source_type' => 'upload',
            'source_ref' => 'private://content-control-plane/pack.zip',
            'sha256' => str_repeat('b', 64),
            'manifest_json' => [
                'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'content_package_version' => 'content_2026_03',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'schemas' => [
                    'content_governance' => 'fap.mbti.content_governance.v1',
                ],
            ],
            'extracted_rel_path' => '',
            'created_by' => 'ops_admin',
        ]);

        $session = [
            'ops_org_id' => $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-pack-versions')
            ->assertOk()
            ->assertSee('Open Release Queue')
            ->assertSee('Release Candidate');

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-pack-versions/'.$version->id.'/edit')
            ->assertOk()
            ->assertSee('Content control plane')
            ->assertSee('Draft state')
            ->assertSee('Runtime artifact ref')
            ->assertSee('First-wave managed objects')
            ->assertSee('Object-level contracts')
            ->assertSee('narrative_fragment')
            ->assertSee('release_candidate_metadata')
            ->assertSee('locale_variant_draft')
            ->assertSee('draft_only')
            ->assertSee('release_metadata_pending');
    }

    private function createSelectedOrg(): Organization
    {
        return Organization::query()->create([
            'name' => 'Content Control Plane Org',
            'owner_user_id' => 9001,
            'status' => 'active',
            'domain' => 'content-control-plane.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
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

        $role = Role::query()->create([
            'name' => 'content_control_plane_'.Str::lower(Str::random(6)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => (string) config('admin.guard', 'admin'),
            ]);

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
