<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\QualityResearchPage;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Analytics\QualityInsightsDailyBuilder;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\SeedsQualityResearchScenario;
use Tests\TestCase;

final class QualityResearchPageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsQualityResearchScenario;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_quality_research_page_route_exists_and_tabs_render_foundational_sections(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Quality Research Org');
        $scenario = $this->seedQualityResearchScenario((int) $selectedOrg->id);

        app(QualityInsightsDailyBuilder::class)->refresh(
            new \DateTimeImmutable($scenario['from']),
            new \DateTimeImmutable($scenario['to']),
            [(int) $selectedOrg->id],
        );

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/quality-research')
            ->assertOk()
            ->assertSee('质量 / 心理测量 / 常模与漂移')
            ->assertSee('Quality')
            ->assertSee('Psychometrics')
            ->assertSee('Norms & Drift')
            ->assertSee('内部质量、心理测量快照、常模覆盖与发布/漂移参考视图。');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/quality-research', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);
        $this->withSession($this->opsSession($admin, $selectedOrg));

        Livewire::test(QualityResearchPage::class)
            ->assertOk()
            ->set('fromDate', $scenario['from'])
            ->set('toDate', $scenario['to'])
            ->call('applyFilters')
            ->assertSet('hasQualityData', true)
            ->assertSet('hasPsychometricsData', true)
            ->assertSet('hasNormsData', true)
            ->assertSet('qualityKpis.0.label', 'Sample size')
            ->assertSet('qualityDailyRows.0.day', '2026-01-03')
            ->call('setActiveTab', 'psychometrics')
            ->assertSet('activeTab', 'psychometrics')
            ->assertSet('psychometricRows.0.scale_code', 'BIG5_OCEAN')
            ->call('setActiveTab', 'norms-drift')
            ->assertSet('activeTab', 'norms-drift')
            ->assertSet('normCoverageRows.0.scale_code', 'BIG5_OCEAN')
            ->assertSet('rolloutRows.0.model_key', 'ml_beta')
            ->assertSet('driftRows.0.reference_state', 'Internal compare reference');
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
