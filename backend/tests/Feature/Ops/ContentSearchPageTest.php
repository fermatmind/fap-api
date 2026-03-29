<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ContentSearchPage;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Ops\ContentSearchService;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ContentSearchPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_content_search_page_renders_for_content_read_admin_and_returns_cms_results(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('Content Search Org');
        $otherOrg = $this->createOrganization('Other Content Search Org');

        Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'quarterly-review-article',
            'locale' => 'en',
            'title' => 'Quarterly Review Article',
            'excerpt' => 'Quarterly search article excerpt',
            'content_md' => 'Quarterly review content',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        Article::query()->create([
            'org_id' => (int) $otherOrg->id,
            'slug' => 'other-org-article',
            'locale' => 'en',
            'title' => 'Other Org Article',
            'excerpt' => 'Should not appear',
            'content_md' => 'Other content',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        ArticleCategory::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'quarterly-category',
            'name' => 'Quarterly Category',
            'description' => 'Quarterly category description',
            'is_active' => true,
        ]);
        ArticleTag::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'quarterly-tag',
            'name' => 'Quarterly Tag',
            'is_active' => true,
        ]);
        CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'quarterly-guide',
            'slug' => 'quarterly-guide',
            'locale' => 'en',
            'title' => 'Quarterly Career Guide',
            'excerpt' => 'Quarterly guide excerpt',
            'category_slug' => 'career-planning',
            'body_md' => 'Guide body',
            'body_html' => '<p>Guide body</p>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
        ]);
        CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'quarterly-job',
            'slug' => 'quarterly-job',
            'locale' => 'en',
            'title' => 'Quarterly Career Job',
            'excerpt' => 'Quarterly job excerpt',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-search')
            ->assertOk()
            ->assertSee('Content search')
            ->assertSee('Search by title / slug / excerpt / category / tag');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-search', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(ContentSearchPage::class)
            ->assertOk()
            ->set('query', 'Quarterly')
            ->set('typeFilter', 'all')
            ->call('runSearch')
            ->assertSet('items.0.label', 'Quarterly Review Article');

        $result = app(ContentSearchService::class)->search('Quarterly', [(int) $selectedOrg->id]);

        $labels = array_map(static fn (array $item): string => (string) ($item['label'] ?? ''), $result['items']);

        $this->assertContains('Quarterly Review Article', $labels);
        $this->assertContains('Quarterly Category', $labels);
        $this->assertContains('Quarterly Tag', $labels);
        $this->assertContains('Quarterly Career Guide', $labels);
        $this->assertContains('Quarterly Career Job', $labels);
        $this->assertNotContains('Other Org Article', $labels);
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
