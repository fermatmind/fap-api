<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\TestKpiDailyPage;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TestKpiDailyPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_test_kpi_daily_page_route_renders_by_test_rows(): void
    {
        Carbon::setTestNow('2026-06-20 12:00:00');

        try {
            $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_OPS_READ]);
            $selectedOrg = $this->createOrganization('Test KPI Daily Org');

            $this->insertDailyMetric('2026-06-20', 0, 'MBTI', 'MBTI_PERSONALITY_TEST_16_TYPES', '144Q', 'zh-CN', 7, 2, 9, 10);
            $this->insertDailyMetric('2026-06-20', 0, 'BIG5_OCEAN', 'BIG_FIVE_OCEAN_MODEL', 'big5-120', 'en', 11, 1, 12, 14);
            $this->insertDailyMetric('2026-06-20', (int) $selectedOrg->id, 'RIASEC', 'RIASEC_HOLLAND_CAREER_INTERESTS', 'riasec-60', 'zh-CN', 100, 100, 200, 210);

            $this->withSession($this->opsSession($admin, $selectedOrg))
                ->actingAs($admin, (string) config('admin.guard', 'admin'))
                ->get('/ops/test-kpi-daily')
                ->assertOk()
                ->assertSee('MBTI')
                ->assertSee('MBTI_PERSONALITY_TEST_16_TYPES')
                ->assertSee('144Q')
                ->assertSee('zh-CN')
                ->assertSee('BIG5_OCEAN')
                ->assertSee('big5-120')
                ->assertSee('91.7%')
                ->assertSee('21')
                ->assertDontSee('RIASEC_HOLLAND_CAREER_INTERESTS');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_test_kpi_daily_page_filters_date_scale_form_locale_and_org_scope(): void
    {
        Carbon::setTestNow('2026-06-20 12:00:00');

        try {
            $this->setOpsOrg(21);

            $this->insertDailyMetric('2026-06-19', 21, 'MBTI', 'MBTI_PERSONALITY_TEST_16_TYPES', '144Q', 'zh-CN', 5, 3, 8, 9);
            $this->insertDailyMetric('2026-06-20', 21, 'MBTI', 'MBTI_PERSONALITY_TEST_16_TYPES', '144Q', 'zh-CN', 7, 2, 9, 10);
            $this->insertDailyMetric('2026-06-20', 21, 'MBTI', 'MBTI_PERSONALITY_TEST_16_TYPES', 'short', 'en', 30, 10, 40, 44);
            $this->insertDailyMetric('2026-06-20', 99, 'MBTI', 'MBTI_PERSONALITY_TEST_16_TYPES', '144Q', 'zh-CN', 100, 100, 200, 200);

            $page = app(TestKpiDailyPage::class);
            $page->fromDate = '2026-06-20';
            $page->toDate = '2026-06-20';
            $page->scope = 'current_org';
            $page->scaleCode = 'MBTI';
            $page->formCode = '144Q';
            $page->locale = 'zh-CN';
            $page->applyFilters();

            $this->assertTrue($page->hasData);
            $this->assertSame([], $page->warnings);
            $this->assertCount(1, $page->dailyRows);
            $this->assertSame('2026-06-20', $page->dailyRows[0]['day']);
            $this->assertSame('MBTI', $page->dailyRows[0]['scale_code']);
            $this->assertSame('144Q', $page->dailyRows[0]['form_code']);
            $this->assertSame('zh-CN', $page->dailyRows[0]['locale']);
            $this->assertSame(7, $page->dailyRows[0]['successful_attempts']);
            $this->assertSame(2, $page->dailyRows[0]['failed_attempts']);
            $this->assertSame(9, $page->dailyRows[0]['total_attempts']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_test_kpi_daily_page_can_read_global_org_zero_scope(): void
    {
        $this->setOpsOrg(21);

        $this->insertDailyMetric('2026-06-20', 0, 'RIASEC', 'RIASEC_HOLLAND_CAREER_INTERESTS', 'riasec-60', 'en', 4, 1, 5, 6);
        $this->insertDailyMetric('2026-06-20', 21, 'RIASEC', 'RIASEC_HOLLAND_CAREER_INTERESTS', 'riasec-60', 'en', 40, 10, 50, 55);

        $page = app(TestKpiDailyPage::class);
        $page->fromDate = '2026-06-20';
        $page->toDate = '2026-06-20';
        $page->scope = 'global_org0';
        $page->scaleCode = 'RIASEC';
        $page->formCode = 'riasec-60';
        $page->locale = 'en';
        $page->applyFilters();

        $this->assertTrue($page->hasData);
        $this->assertCount(1, $page->dailyRows);
        $this->assertSame(4, $page->dailyRows[0]['successful_attempts']);
        $this->assertSame(1, $page->dailyRows[0]['failed_attempts']);
        $this->assertSame(5, $page->dailyRows[0]['total_attempts']);
    }

    private function setOpsOrg(int $orgId): void
    {
        $context = app(OrgContext::class);
        $context->set($orgId, null, null, null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);
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
