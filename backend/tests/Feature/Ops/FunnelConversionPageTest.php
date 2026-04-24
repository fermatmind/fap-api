<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Analytics\AnalyticsFunnelDailyBuilder;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
                ->assertSee('test_submit_success')
                ->assertSee('first_result_or_report_view')
                ->assertSee('unlock_success')
                ->assertSee('$38.98');
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
