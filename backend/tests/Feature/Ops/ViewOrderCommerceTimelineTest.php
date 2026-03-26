<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class ViewOrderCommerceTimelineTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_order_view_renders_commerce_timeline_sections(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Commerce Timeline Org');
        $chain = $this->seedCommerceOpsChain(orgId: 73, options: [
            'last_reconciled_at' => now()->subMinutes(10),
        ]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/orders/'.$chain['order']->getKey())
            ->assertOk()
            ->assertSee('Commerce Timeline')
            ->assertSee('Order Summary')
            ->assertSee('Payment Attempts')
            ->assertSee('Payment Events')
            ->assertSee('Unified Access')
            ->assertSee('Compensation Summary')
            ->assertSee('Assessment Attempt Linkage')
            ->assertSee((string) $chain['payment_attempt_id'])
            ->assertSee((string) $chain['payment_event_id'])
            ->assertSee((string) $chain['benefit_grant_id']);
    }
}
