<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class CommerceOpsActionLinkageTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_order_view_exposes_ops_action_links_for_manual_follow_up(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Commerce Linkage Org');
        $chain = $this->seedCommerceOpsChain(orgId: 74, options: [
            'last_reconciled_at' => now()->subMinutes(5),
        ]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/orders/'.$chain['order']->getKey())
            ->assertOk()
            ->assertSee('Latest payment attempt')
            ->assertSee('Latest payment event')
            ->assertSee('Latest benefit grant')
            ->assertSee('Order Lookup')
            ->assertSee('php artisan commerce:compensate-pending-orders --order='.$chain['order_no'].' --dry-run');
    }
}
