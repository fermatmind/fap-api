<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Widgets\CommerceKpiWidget;
use App\Filament\Ops\Widgets\FunnelWidget;
use App\Filament\Ops\Widgets\HealthzStatusWidget;
use App\Filament\Ops\Widgets\QueueFailureWidget;
use App\Filament\Ops\Widgets\TestKpiDailyInlineWidget;
use App\Filament\Ops\Widgets\TestKpiSummaryWidget;
use App\Filament\Ops\Widgets\WebhookFailureWidget;
use App\Http\Middleware\EnsureAdminTotpVerified;
use App\Http\Middleware\OpsAccessControl;
use App\Http\Middleware\RequireOpsOrgSelected;
use App\Http\Middleware\ResolveOrgContext;
use App\Http\Middleware\SetOpsLocale;
use App\Http\Middleware\SetOpsRequestContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\LivewireManager;
use Tests\Feature\Ops\Support\InteractsWithCommerceOpsWorkbench;
use Tests\TestCase;

final class OpsDashboardOrgContextInheritanceTest extends TestCase
{
    use InteractsWithCommerceOpsWorkbench;
    use RefreshDatabase;

    public function test_ops_dashboard_uses_selected_org_context_for_shell_and_widgets(): void
    {
        Carbon::setTestNow('2026-06-20 12:00:00');

        try {
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

            $this->insertDailyMetric('2026-06-20', 0, 'MBTI', 'MBTI_PERSONALITY_TEST_16_TYPES', '144Q', 'zh-CN', 7, 2, 9, 10);
            $this->insertDailyMetric('2026-06-20', 0, 'BIG5_OCEAN', 'BIG_FIVE_OCEAN_MODEL', 'big5-120', 'en', 11, 1, 12, 14);
            $this->insertDailyMetric('2026-06-20', (int) $selectedOrg->id, 'RIASEC', 'RIASEC_HOLLAND_CAREER_INTERESTS', 'riasec-60', 'zh-CN', 100, 100, 200, 210);

            $this->withSession([
                'ops_admin_totp_verified_user_id' => (int) $admin->id,
                'ops_org_id' => (int) $selectedOrg->id,
            ])->withCookie('ops_org_id', (string) $selectedOrg->id)
                ->actingAs($admin, (string) config('admin.guard', 'admin'))
                ->get('/ops')
                ->assertOk()
                ->assertSee($selectedOrg->name)
                ->assertSee(__('ops.widgets.paid_orders_today'))
                ->assertSee(__('ops.widgets.test_kpi_overview'))
                ->assertSee(__('ops.widgets.test_kpi_daily_detail'))
                ->assertSee('MBTI_PERSONALITY_TEST_16_TYPES')
                ->assertSee('BIG_FIVE_OCEAN_MODEL')
                ->assertSee('big5-120')
                ->assertSee('21')
                ->assertDontSee('RIASEC_HOLLAND_CAREER_INTERESTS')
                ->assertSee(__('ops.widgets.funnel_snapshot_7d'))
                ->assertSee(__('ops.widgets.webhook_monitoring'))
                ->assertDontSee(__('ops.topbar.no_org_selected'))
                ->assertDontSee(__('ops.widgets.select_org_to_view_metrics'))
                ->assertDontSee('@js(request()->fullUrl())', false)
                ->assertSee('setLocale(', false)
                ->assertSee('window.__opsLivewirePageExpiredRecoveryHookInstalled', false)
                ->assertSee("const autoRefreshStorageKeyPrefix = 'ops-livewire-page-expired-at:'", false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_content_role_does_not_render_commerce_kpis_on_ops_dashboard(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('Dashboard Content Only Org');

        $this->seedCommerceOpsChain((int) $selectedOrg->id, 'ord_dashboard_content_only', [
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
            ->assertDontSee(__('ops.widgets.commerce_overview'))
            ->assertDontSee(__('ops.widgets.paid_orders_today'))
            ->assertDontSee(__('ops.widgets.paid_without_grant'))
            ->assertDontSee(__('ops.widgets.test_kpi_overview'))
            ->assertDontSee(__('ops.widgets.funnel_snapshot_7d'))
            ->assertDontSee(__('ops.widgets.webhook_monitoring'));
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
        $this->assertFalse(TestKpiSummaryWidget::isLazy());
        $this->assertFalse(TestKpiDailyInlineWidget::isLazy());
        $this->assertFalse(FunnelWidget::isLazy());
        $this->assertFalse(WebhookFailureWidget::isLazy());
        $this->assertFalse(QueueFailureWidget::isLazy());
        $this->assertFalse(HealthzStatusWidget::isLazy());
    }

    private function insertDailyMetric(
        string $day,
        int $orgId,
        string $scaleCode,
        string $scaleCodeV2,
        string $formCode,
        string $locale,
        int $successfulAttempts,
        int $failedAttempts,
        int $totalAttempts,
        int $startedAttempts,
    ): void {
        DB::table('analytics_test_metrics_daily')->insert([
            'day' => $day,
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'scale_code_v2' => $scaleCodeV2,
            'scale_uid' => '',
            'form_code' => $formCode,
            'locale' => $locale,
            'started_attempts' => $startedAttempts,
            'successful_attempts' => $successfulAttempts,
            'failed_attempts' => $failedAttempts,
            'total_attempts' => $totalAttempts,
            'last_refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
