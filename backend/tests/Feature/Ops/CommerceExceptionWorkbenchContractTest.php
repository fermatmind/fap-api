<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\OrderResource\Pages\ListOrders;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class CommerceExceptionWorkbenchContractTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_order_resource_exposes_exception_workbench_columns_and_filter(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Commerce Workbench Org');
        $chain = $this->seedCommerceOpsChain(orgId: (int) $selectedOrg->id);

        session($this->opsSession($admin, $selectedOrg));
        $this->setOpsOrgContext((int) $selectedOrg->id, $admin);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListOrders::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$chain['order']])
            ->assertTableColumnExists('commerce_exception')
            ->assertTableColumnExists('exception_count')
            ->assertTableColumnExists('payment_attempts_count')
            ->assertTableColumnExists('latest_payment_attempt_state')
            ->assertTableColumnExists('compensation_status')
            ->assertTableFilterExists('commerce_exception_filter');
    }
}
