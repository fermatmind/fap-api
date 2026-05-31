<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\FunnelConversionPage;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Analytics\AnalyticsFunnelDailyBuilder;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class FunnelConversionPageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_funnel_conversion_page_is_accessible_and_renders_primary_sections(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_MENU_COMMERCE,
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Funnel Selected Org');
        $scenario = $this->seedFunnelAnalyticsScenario((int) $selectedOrg->id);

        Carbon::setTestNow($scenario['day'].' 12:00:00');

        try {
            app(AnalyticsFunnelDailyBuilder::class)->refresh(
                new \DateTimeImmutable($scenario['day']),
                new \DateTimeImmutable($scenario['day']),
                [(int) $selectedOrg->id],
            );

            $this->withSession($this->opsSession($admin, $selectedOrg))
                ->actingAs($admin, (string) config('admin.guard', 'admin'))
                ->get('/ops/funnel-conversion')
                ->assertOk()
                ->assertSee('漏斗与转化')
                ->assertSee('KPI Cards')
                ->assertSee('Started attempts')
                ->assertSee('Daily Funnel Trend')
                ->assertSee('Step Conversion Table')
                ->assertSee('Locale Comparison')
                ->assertSee('PDF Panel')
                ->assertSee('Share Panel')
                ->assertSee('Stage Definition Note')
                ->assertSee('test_submit')
                ->assertSee('result_view')
                ->assertSee('report_unlock')
                ->assertDontSee('test_submit_success')
                ->assertDontSee('first_result_or_report_view')
                ->assertDontSee('unlock_success')
                ->assertSee('$38.98');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_funnel_conversion_page_reads_global_org_zero_read_model_rows(): void
    {
        Carbon::setTestNow('2026-05-31 12:00:00');

        try {
            $this->setGlobalOpsContext();

            DB::table('analytics_funnel_daily')->insert([
                'day' => '2026-05-31',
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'locale' => 'en',
                'started_attempts' => 382,
                'submitted_attempts' => 286,
                'first_view_attempts' => 277,
                'order_created_attempts' => 11,
                'paid_attempts' => 8,
                'paid_revenue_cents' => 0,
                'unlocked_attempts' => 2,
                'report_ready_attempts' => 0,
                'pdf_download_attempts' => 11,
                'share_generated_attempts' => 12,
                'share_click_attempts' => 5,
                'last_refreshed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $page = app(FunnelConversionPage::class);
            $page->fromDate = '2026-05-25';
            $page->toDate = '2026-05-31';
            $page->scaleCode = 'all';
            $page->locale = 'all';
            $page->refreshPage();

            $this->assertTrue($page->hasData);
            $this->assertSame([], $page->warnings);

            $kpisByLabel = [];
            foreach ($page->kpis as $kpi) {
                $kpisByLabel[(string) $kpi['label']] = (int) $kpi['value'];
            }

            $this->assertSame(382, $kpisByLabel['Started attempts'] ?? null);
            $this->assertSame(286, $kpisByLabel['Submitted attempts'] ?? null);
            $this->assertSame(277, $kpisByLabel['First result/report viewers'] ?? null);
            $this->assertSame(11, $kpisByLabel['Order-created attempts'] ?? null);
            $this->assertSame(8, $kpisByLabel['Paid attempts'] ?? null);
            $this->assertSame(2, $kpisByLabel['Unlocked attempts'] ?? null);
            $this->assertSame(0, $kpisByLabel['Report-ready attempts'] ?? null);

            $conversionLabels = array_map(static fn (array $row): string => (string) $row['label'], $page->conversionRows);

            $this->assertContains('test_start', $conversionLabels);
            $this->assertContains('test_submit', $conversionLabels);
            $this->assertContains('result_view', $conversionLabels);
            $this->assertContains('order_created', $conversionLabels);
            $this->assertContains('payment_success', $conversionLabels);
            $this->assertContains('report_unlock', $conversionLabels);
            $this->assertContains('report_ready', $conversionLabels);
            $this->assertNotContains('test_submit_success', $conversionLabels);
            $this->assertNotContains('first_result_or_report_view', $conversionLabels);
            $this->assertNotContains('unlock_success', $conversionLabels);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_funnel_conversion_page_global_scope_reads_org_zero_even_with_selected_org_session(): void
    {
        Carbon::setTestNow('2026-05-31 12:00:00');

        try {
            $admin = $this->createAdminWithPermissions([
                PermissionNames::ADMIN_MENU_COMMERCE,
                PermissionNames::ADMIN_OPS_READ,
            ]);
            $selectedOrg = $this->createOrganization('Funnel Tenant Org');

            DB::table('analytics_funnel_daily')->insert([
                [
                    'day' => '2026-05-31',
                    'org_id' => 0,
                    'scale_code' => 'MBTI',
                    'locale' => 'en',
                    'started_attempts' => 382,
                    'submitted_attempts' => 286,
                    'first_view_attempts' => 277,
                    'order_created_attempts' => 11,
                    'paid_attempts' => 8,
                    'paid_revenue_cents' => 0,
                    'unlocked_attempts' => 2,
                    'report_ready_attempts' => 0,
                    'pdf_download_attempts' => 11,
                    'share_generated_attempts' => 12,
                    'share_click_attempts' => 5,
                    'last_refreshed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'day' => '2026-05-31',
                    'org_id' => (int) $selectedOrg->id,
                    'scale_code' => 'MBTI',
                    'locale' => 'en',
                    'started_attempts' => 1,
                    'submitted_attempts' => 1,
                    'first_view_attempts' => 1,
                    'order_created_attempts' => 0,
                    'paid_attempts' => 0,
                    'paid_revenue_cents' => 0,
                    'unlocked_attempts' => 0,
                    'report_ready_attempts' => 0,
                    'pdf_download_attempts' => 0,
                    'share_generated_attempts' => 0,
                    'share_click_attempts' => 0,
                    'last_refreshed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $response = $this->withSession($this->opsSession($admin, $selectedOrg))
                ->actingAs($admin, (string) config('admin.guard', 'admin'))
                ->get('/ops/funnel-conversion?scope=global_org0');

            $response
                ->assertOk()
                ->assertSee('Global org_id=0')
                ->assertSee('Global scope reads org_id=0 without changing the selected organization.')
                ->assertSee('382')
                ->assertSee('286')
                ->assertSee('277')
                ->assertSee('report_unlock')
                ->assertDontSee('No analytics_funnel_daily rows match the current scope');

            $this->assertSame((int) $selectedOrg->id, (int) session('ops_org_id'));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'owner_user_id' => random_int(1000, 9999),
            'status' => 'active',
            'domain' => Str::slug($name).'.example.test',
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
            'name' => 'ops_'.Str::lower(Str::random(6)),
            'email' => 'ops_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function setGlobalOpsContext(): void
    {
        $context = app(OrgContext::class);
        $context->set(0, null, null, null, OrgContext::KIND_PUBLIC);
        app()->instance(OrgContext::class, $context);
    }

    /**
     * @return array{ops_org_id:int,ops_admin_totp_verified_user_id:int}
     */
    private function opsSession(AdminUser $admin, Organization $selectedOrg): array
    {
        return [
            'ops_org_id' => (int) $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];
    }
}
