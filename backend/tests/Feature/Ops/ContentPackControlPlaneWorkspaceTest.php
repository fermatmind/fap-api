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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentPackControlPlaneWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $tmpRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpRoots as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    public function test_content_pack_version_workspace_renders_control_plane_surface_for_authorized_admin(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);
        $selectedOrg = $this->createSelectedOrg();

        $version = $this->seedVersionFromRealMbtiPack();

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
            ->assertSee('Objectized fragment groups')
            ->assertSee('First-wave managed objects')
            ->assertSee('Object-level contracts')
            ->assertSee('narrative_fragment')
            ->assertSee('tone_fragment')
            ->assertSee('faq_explainability_copy')
            ->assertSee('release_candidate_metadata')
            ->assertSee('locale_variant_draft')
            ->assertSee('draft_only')
            ->assertSee('release_metadata_ready')
            ->assertSee('runtime_binding=runtime_bindable');
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

    private function seedVersionFromRealMbtiPack(): ContentPackVersion
    {
        $sourceDir = base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3');
        $versionId = (string) Str::uuid();
        $relativePath = 'content_control_plane_workspace_tests/'.$versionId.'/source_pack';
        $targetDir = storage_path('app/private/'.$relativePath);

        File::ensureDirectoryExists(dirname($targetDir));
        File::copyDirectory($sourceDir, $targetDir);
        $this->tmpRoots[] = dirname(dirname($targetDir));

        $manifest = json_decode((string) file_get_contents($sourceDir.'/manifest.json'), true);
        $this->assertIsArray($manifest);

        return ContentPackVersion::query()->create([
            'id' => $versionId,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'pack_id' => (string) ($manifest['pack_id'] ?? 'MBTI.cn-mainland.zh-CN.v0.3'),
            'content_package_version' => 'content_2026_03',
            'dir_version_alias' => 'MBTI-CN-v0.3-control-plane',
            'source_type' => 'upload',
            'source_ref' => 'private://content-control-plane/'.$versionId.'/pack.zip',
            'sha256' => str_repeat('b', 64),
            'manifest_json' => $manifest,
            'extracted_rel_path' => $relativePath,
            'created_by' => 'ops_admin',
        ]);
    }
}
