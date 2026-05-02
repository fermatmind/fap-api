<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Livewire\Filament\Ops\Livewire\CurrentOrgSwitcher;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class CurrentOrgSwitcherComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_org_switcher_component_can_boot(): void
    {
        Livewire::test(CurrentOrgSwitcher::class)->assertOk();
        Livewire::test('filament.ops.livewire.current-org-switcher')->assertOk();
    }

    public function test_current_org_switcher_reads_org_context_and_clearing_selection_returns_to_select_org(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_OPS_READ]);
        $organization = Organization::query()->create([
            'name' => 'Switcher Org',
            'owner_user_id' => 1,
            'status' => 'active',
            'domain' => 'switcher.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);

        session(['ops_org_id' => (int) $organization->id]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $context = app(OrgContext::class);
        $context->set((int) $organization->id, 1, 'admin', null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);

        Livewire::test(CurrentOrgSwitcher::class)
            ->assertOk()
            ->assertSet('orgId', (int) $organization->id)
            ->assertSee($organization->name)
            ->call('goSelectOrg')
            ->assertRedirect('/ops/select-org');

        $this->assertNull(session('ops_org_id'));
        $this->assertSame(0, (int) app(OrgContext::class)->orgId());
    }

    public function test_current_org_switcher_clears_org_context_without_visible_admin_guard(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Hidden Switcher Org',
            'owner_user_id' => 1,
            'status' => 'active',
            'domain' => 'hidden-switcher.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);

        session(['ops_org_id' => (int) $organization->id]);

        $context = app(OrgContext::class);
        $context->set((int) $organization->id, 1, 'admin', null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);

        Livewire::test(CurrentOrgSwitcher::class)
            ->assertOk()
            ->assertSet('orgId', null)
            ->assertDontSee($organization->name);

        $this->assertNull(session('ops_org_id'));
        $this->assertSame(0, (int) app(OrgContext::class)->orgId());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'switcher_admin_'.Str::lower(Str::random(6)),
            'email' => 'switcher_admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'switcher_role_'.Str::lower(Str::random(6)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['guard_name' => (string) config('admin.guard', 'admin')]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
