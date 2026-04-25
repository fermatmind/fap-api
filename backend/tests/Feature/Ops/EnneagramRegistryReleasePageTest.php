<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\EnneagramRegistryReleasePage;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class EnneagramRegistryReleasePageTest extends TestCase
{
    use RefreshDatabase;

    private string $typeRegistryPath;

    private string $typeRegistryBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->typeRegistryPath = base_path('content_packs/ENNEAGRAM/v2/registry/type_registry.json');
        $this->typeRegistryBackup = (string) File::get($this->typeRegistryPath);

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    protected function tearDown(): void
    {
        File::put($this->typeRegistryPath, $this->typeRegistryBackup);

        parent::tearDown();
    }

    public function test_ops_page_renders_registry_preview_sections(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/enneagram-registry-release')
            ->assertOk()
            ->assertSee('Enneagram Registry Governance')
            ->assertSee('Technical Note preview')
            ->assertSee('Sample reports')
            ->assertSee('Workplace / team placeholder')
            ->assertSee('non-hard-judgement');
    }

    public function test_ops_page_publish_action_creates_governance_release_records(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(EnneagramRegistryReleasePage::class)
            ->call('publishRegistryRelease')
            ->assertSet('preview.validation.status', 'passed');

        $this->assertSame(1, DB::table('content_pack_releases')
            ->where('to_pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->where('action', 'enneagram_registry_publish')
            ->count());
    }

    public function test_refresh_action_reloads_preview_from_registry_files(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $component = Livewire::test(EnneagramRegistryReleasePage::class);

        $this->assertNull(data_get($component->get('preview'), 'content_maturity_summary.review_only'));

        $registry = json_decode($this->typeRegistryBackup, true, 512, JSON_THROW_ON_ERROR);
        $registry['content_maturity'] = 'review_only';
        File::put(
            $this->typeRegistryPath,
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
        );

        $component
            ->call('refreshRegistryPreview')
            ->assertSet('preview.content_maturity_summary.review_only', 1);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'enneagram_registry_admin_'.Str::lower(Str::random(6)),
            'email' => 'enneagram_registry_admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'enneagram_registry_role_'.Str::lower(Str::random(6)),
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
