<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\OrganizationsImportPage;
use App\Filament\Ops\Pages\SelectOrgPage;
use App\Filament\Ops\Resources\OrganizationResource\Pages\CreateOrganization;
use App\Models\Organization;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class SelectOrgFlowTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_select_org_page_lists_filters_and_creates_admin_visible_organizations(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_ORG_MANAGE,
        ]);

        $alpha = $this->createOrganization('Alpha Workspace');
        $beta = $this->createOrganization('Beta Workspace');

        session(['ops_admin_totp_verified_user_id' => (int) $admin->id]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(SelectOrgPage::class)
            ->assertOk()
            ->assertSee($alpha->name)
            ->assertSee($beta->name)
            ->set('search', 'Alpha')
            ->assertSee($alpha->name)
            ->assertDontSee($beta->name)
            ->call('createOrganization');

        $createdOrg = Organization::query()->latest('id')->firstOrFail();

        $this->assertDatabaseCount('organizations', 3);
        $this->assertSame(0, (int) $createdOrg->owner_user_id);
        $this->assertDatabaseMissing('organization_members', [
            'org_id' => (int) $createdOrg->id,
        ]);

        Livewire::test(SelectOrgPage::class)
            ->call('selectOrg', (int) $createdOrg->id)
            ->assertRedirect('/ops');
    }

    public function test_select_org_action_persists_context_and_return_to_target_opens(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_MENU_COMMERCE,
        ]);
        $selectedOrg = $this->createOrganization('Selected Workspace');
        $chain = $this->seedCommerceOpsChain((int) $selectedOrg->id, 'ord_select_org_flow_001');

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/orders')
            ->assertRedirect('/ops/select-org?return_to=%2Fops%2Forders');

        session(['ops_admin_totp_verified_user_id' => (int) $admin->id]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(SelectOrgPage::class)
            ->set('returnTo', '/ops/orders')
            ->call('selectOrg', (int) $selectedOrg->id)
            ->assertRedirect('/ops/orders');

        $this->assertSame((int) $selectedOrg->id, (int) session('ops_org_id'));

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
            'ops_org_id' => (int) $selectedOrg->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/orders')
            ->assertOk()
            ->assertSee($chain['order_no']);
    }

    public function test_cookie_backed_org_context_keeps_org_scoped_ops_pages_available(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_MENU_SUPPORT,
        ]);
        $selectedOrg = $this->createOrganization('Cookie Context Org');
        $chain = $this->seedCommerceOpsChain((int) $selectedOrg->id, 'ord_cookie_scope_001');

        $baseSession = [
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];

        $this->withSession($baseSession)
            ->withCookie('ops_org_id', (string) $selectedOrg->id)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/orders')
            ->assertOk()
            ->assertSee($chain['order_no'])
            ->assertSessionHas('ops_org_id', (int) $selectedOrg->id);

        $this->withSession($baseSession)
            ->withCookie('ops_org_id', (string) $selectedOrg->id)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/benefit-grants')
            ->assertOk()
            ->assertSee((string) DB::table('benefit_grants')->where('org_id', (int) $selectedOrg->id)->value('benefit_code'));

        $this->withSession($baseSession)
            ->withCookie('ops_org_id', (string) $selectedOrg->id)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/order-lookup')
            ->assertOk()
            ->assertSee('Order Lookup');

        $this->withSession($baseSession)
            ->withCookie('ops_org_id', (string) $selectedOrg->id)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops')
            ->assertOk();
    }

    public function test_organizations_import_page_is_reachable_and_explains_runbook_status(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_ORG_MANAGE,
        ]);

        session(['ops_admin_totp_verified_user_id' => (int) $admin->id]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(SelectOrgPage::class)
            ->call('goToImport')
            ->assertRedirect(route('filament.ops.pages.organizations-import'));

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get(route('filament.ops.pages.organizations-import'))
            ->assertOk()
            ->assertSee((new OrganizationsImportPage)->getNavigationLabel())
            ->assertSee('Runbook-driven import only')
            ->assertSee('Back to Select Org');
    }

    public function test_organization_resource_create_uses_same_bootstrap_semantics_without_membership_side_effects(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_ORG_MANAGE,
        ]);

        session(['ops_admin_totp_verified_user_id' => (int) $admin->id]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(CreateOrganization::class)
            ->fillForm([
                'name' => 'Resource Created Org',
                'status' => 'active',
                'domain' => '',
                'timezone' => 'UTC',
                'locale' => 'en-US',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $createdOrg = Organization::query()->where('name', 'Resource Created Org')->firstOrFail();

        $this->assertSame(0, (int) $createdOrg->owner_user_id);
        $this->assertSame('active', (string) $createdOrg->status);
        $this->assertSame('UTC', (string) $createdOrg->timezone);
        $this->assertSame('en-US', (string) $createdOrg->locale);
        $this->assertNull($createdOrg->domain);
        $this->assertDatabaseMissing('organization_members', [
            'org_id' => (int) $createdOrg->id,
        ]);
    }
}
