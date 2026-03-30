<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Widgets\CommerceKpiWidget;
use App\Filament\Ops\Widgets\FunnelWidget;
use App\Filament\Ops\Widgets\HealthzStatusWidget;
use App\Filament\Ops\Widgets\QueueFailureWidget;
use App\Filament\Ops\Widgets\WebhookFailureWidget;
use App\Http\Middleware\EnsureAdminTotpVerified;
use App\Http\Middleware\OpsAccessControl;
use App\Http\Middleware\RequireOpsOrgSelected;
use App\Http\Middleware\ResolveOrgContext;
use App\Http\Middleware\SetOpsLocale;
use App\Http\Middleware\SetOpsRequestContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireManager;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class OpsDashboardOrgContextInheritanceTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    public function test_ops_dashboard_uses_selected_org_context_for_shell_and_widgets(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_MENU_COMMERCE,
        ]);
        $selectedOrg = $this->createOrganization('Dashboard Context Org');

        $this->seedCommerceOpsChain((int) $selectedOrg->id, 'ord_dashboard_context_001', [
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(10),
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
            'ops_org_id' => (int) $selectedOrg->id,
        ])->withCookie('ops_org_id', (string) $selectedOrg->id)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops')
            ->assertOk()
            ->assertSee($selectedOrg->name)
            ->assertDontSee(__('ops.topbar.no_org_selected'))
            ->assertDontSee(__('ops.widgets.select_org_to_view_metrics'))
            ->assertSee('window.__opsLivewirePageExpiredRecoveryHookInstalled', false)
            ->assertSee("const autoRefreshStorageKeyPrefix = 'ops-livewire-page-expired-at:'", false);
    }

    public function test_ops_panel_registers_org_context_middleware_for_livewire_persistence(): void
    {
        $persistent = app(LivewireManager::class)->getPersistentMiddleware();

        $this->assertContains(SetOpsRequestContext::class, $persistent);
        $this->assertContains(ResolveOrgContext::class, $persistent);
        $this->assertContains(SetOpsLocale::class, $persistent);
        $this->assertContains(EnsureAdminTotpVerified::class, $persistent);
        $this->assertContains(RequireOpsOrgSelected::class, $persistent);
        $this->assertContains(OpsAccessControl::class, $persistent);
    }

    public function test_ops_dashboard_widgets_render_on_initial_request_instead_of_lazy_livewire_updates(): void
    {
        $this->assertFalse(CommerceKpiWidget::isLazy());
        $this->assertFalse(FunnelWidget::isLazy());
        $this->assertFalse(WebhookFailureWidget::isLazy());
        $this->assertFalse(QueueFailureWidget::isLazy());
        $this->assertFalse(HealthzStatusWidget::isLazy());
    }
}
