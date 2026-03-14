<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\MbtiInsightsPage;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Analytics\MbtiDistributionDailyBuilder;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\SeedsMbtiInsightsScenario;
use Tests\TestCase;

final class MbtiInsightsPageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMbtiInsightsScenario;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_mbti_insights_page_route_exists_and_tabs_render_basic_mbti_data(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('MBTI Insights Org');
        $scenario = $this->seedMbtiInsightsAuthorityScenario((int) $selectedOrg->id);

        app(MbtiDistributionDailyBuilder::class)->refresh(
            new \DateTimeImmutable($scenario['from']),
            new \DateTimeImmutable($scenario['to']),
            [(int) $selectedOrg->id],
        );

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/mbti-insights')
            ->assertOk()
            ->assertSee('MBTI Insights')
            ->assertSee('Overview')
            ->assertSee('Type Distribution')
            ->assertSee('Axis Distribution')
            ->assertSee('Authority Scope');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/mbti-insights', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(MbtiInsightsPage::class)
            ->assertOk()
            ->set('fromDate', $scenario['from'])
            ->set('toDate', $scenario['to'])
            ->call('applyFilters')
            ->assertSet('hasData', true)
            ->assertSet('kpis.0.label', 'Total results')
            ->call('setActiveTab', 'types')
            ->assertSet('activeTab', 'types')
            ->assertSet('typeDistribution.0.type_code', 'INTJ')
            ->call('setActiveTab', 'axes')
            ->assertSet('activeTab', 'axes')
            ->assertSet('showsAtAxis', true)
            ->assertSet('axisSummary.0.axis_code', 'EI');
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
