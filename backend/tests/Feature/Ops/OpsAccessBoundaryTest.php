<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\DeliveryTools;
use App\Filament\Ops\Pages\SecureLink;
use App\Models\AdminApproval;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class OpsAccessBoundaryTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_cross_org_explorer_pages_open_without_org_context_but_org_scoped_pages_still_redirect(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_GLOBAL_SEARCH,
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_MENU_COMMERCE,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/orders')
            ->assertOk();

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/attempts')
            ->assertOk();

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/results')
            ->assertOk();

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/reports')
            ->assertOk();

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/global-search')
            ->assertOk();

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/payment-events')
            ->assertRedirect('/ops/select-org?return_to=%2Fops%2Fpayment-events');

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/order-lookup')
            ->assertRedirect('/ops/select-org?return_to=%2Fops%2Forder-lookup');
    }

    public function test_cross_org_explorers_no_longer_allow_plain_ops_read_without_support_or_commerce_menu(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/orders')
            ->assertForbidden();

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/attempts')
            ->assertForbidden();

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/results')
            ->assertForbidden();

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/reports')
            ->assertForbidden();
    }

    public function test_select_org_requires_real_ops_access_capability(): void
    {
        $admin = $this->createAdminWithPermissions([]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/select-org')
            ->assertForbidden();
    }

    public function test_delivery_tools_request_requires_elevated_action_permission(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
        ]);
        $selectedOrg = $this->createOrganization('Delivery Action Org');
        $chain = $this->seedCommerceOpsChain((int) $selectedOrg->id, 'ord_delivery_action_001');

        session($this->opsSession($admin, $selectedOrg));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->bindOpsOrgContext((int) $selectedOrg->id);

        Livewire::test(DeliveryTools::class)
            ->set('orderNo', $chain['order_no'])
            ->set('reason', 'Need regeneration')
            ->call('requestAction')
            ->assertSet('statusMessage', 'permission denied.');

        $this->assertDatabaseCount('admin_approvals', 0);
    }

    public function test_delivery_tools_request_allows_support_reviewer_role(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);
        $selectedOrg = $this->createOrganization('Delivery Reviewer Org');
        $chain = $this->seedCommerceOpsChain((int) $selectedOrg->id, 'ord_delivery_action_002');

        session($this->opsSession($admin, $selectedOrg));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->bindOpsOrgContext((int) $selectedOrg->id);

        Livewire::test(DeliveryTools::class)
            ->set('orderNo', $chain['order_no'])
            ->set('reason', 'Need regeneration')
            ->call('requestAction')
            ->assertSet('statusMessage', fn (string $message): bool => $message !== '' && $message !== 'permission denied.');

        $this->assertDatabaseHas('admin_approvals', [
            'org_id' => (int) $selectedOrg->id,
            'type' => AdminApproval::TYPE_MANUAL_GRANT,
            'status' => AdminApproval::STATUS_PENDING,
        ]);
    }

    public function test_secure_link_generation_requires_more_than_support_menu_only(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_SUPPORT,
        ]);
        $selectedOrg = $this->createOrganization('Secure Link Org');
        $chain = $this->seedCommerceOpsChain((int) $selectedOrg->id, 'ord_secure_link_001');

        session($this->opsSession($admin, $selectedOrg));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->bindOpsOrgContext((int) $selectedOrg->id);

        Livewire::test(SecureLink::class)
            ->set('orderNo', $chain['order_no'])
            ->call('generate')
            ->assertSet('statusMessage', 'permission denied.')
            ->assertSet('generatedLink', '');
    }

    private function bindOpsOrgContext(int $orgId): void
    {
        $context = new OrgContext;
        $context->set($orgId, null, 'admin');

        $this->app->instance(OrgContext::class, $context);
    }
}
