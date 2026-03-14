<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\QuestionAnalyticsPage;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Analytics\QuestionAnalyticsDailyBuilder;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\SeedsQuestionAnalyticsScenario;
use Tests\TestCase;

final class QuestionAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;
    use SeedsQuestionAnalyticsScenario;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_question_analytics_page_route_exists_and_tabs_render_big5_authority_data(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $selectedOrg = $this->createOrganization('Question Analytics Org');
        $scenario = $this->seedQuestionAnalyticsScenario((int) $selectedOrg->id);

        app(QuestionAnalyticsDailyBuilder::class)->refresh(
            new \DateTimeImmutable($scenario['from']),
            new \DateTimeImmutable($scenario['to']),
            [(int) $selectedOrg->id],
        );

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/question-analytics')
            ->assertOk()
            ->assertSee('Question Analytics')
            ->assertSee('Option Distribution')
            ->assertSee('Dropoff / Completion')
            ->assertSee('Authority Scope')
            ->assertSee('Duration is deferred in v1');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/question-analytics', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);
        $this->withSession($this->opsSession($admin, $selectedOrg));

        Livewire::test(QuestionAnalyticsPage::class)
            ->assertOk()
            ->assertSet('scaleCode', 'BIG5_OCEAN')
            ->assertSet('excludeNonAuthoritativeScales', true)
            ->set('fromDate', $scenario['from'])
            ->set('toDate', $scenario['to'])
            ->call('applyFilters')
            ->assertSet('hasOptionData', true)
            ->assertSet('hasProgressData', true)
            ->assertSet('optionKpis.0.label', 'Total answered rows')
            ->assertSet('optionDistributionRows.0.question_order', 1)
            ->call('setActiveTab', 'dropoff-completion')
            ->assertSet('activeTab', 'dropoff-completion')
            ->assertSet('progressKpis.0.label', 'Reached attempts')
            ->assertSet('progressRows.0.question_order', 1)
            ->assertSet('scopeNotes.0', 'Current authoritative scope is fixed to BIG5_OCEAN only. MBTI, EQ_60, IQ_RAVEN, SDS_20, and CLINICAL_COMBO_68 stay out of this first-phase authority page.');
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
