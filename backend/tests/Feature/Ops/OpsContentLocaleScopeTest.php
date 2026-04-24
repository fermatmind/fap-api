<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Ops\Support\OpsContentLocaleScope;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportArticle;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class OpsContentLocaleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_article_list_defaults_to_current_ops_locale(): void
    {
        app()->setLocale('en');

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $org = $this->createOrganization();
        app(OrgContext::class)->set((int) $org->id, (int) $admin->id, 'admin');
        $zhArticle = $this->createArticle(0, 'zh-CN', '中文源文');
        $enArticle = $this->createArticle(0, 'en', 'English source');

        session($this->opsSession($admin, $org, 'en'));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListArticles::class)
            ->loadTable()
            ->assertOk()
            ->assertSee($enArticle->title)
            ->assertDontSee($zhArticle->title)
            ->assertTableColumnExists('locale')
            ->assertTableColumnExists('source_locale')
            ->assertTableFilterExists('locale_scope');
    }

    public function test_article_list_can_show_all_locales(): void
    {
        app()->setLocale('en');

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $org = $this->createOrganization();
        app(OrgContext::class)->set((int) $org->id, (int) $admin->id, 'admin');
        $zhArticle = $this->createArticle(0, 'zh-CN', '中文源文');
        $enArticle = $this->createArticle(0, 'en', 'English source');

        session($this->opsSession($admin, $org, 'en'));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListArticles::class)
            ->loadTable()
            ->filterTable('locale_scope', OpsContentLocaleScope::ALL_LOCALES)
            ->assertSee($enArticle->title)
            ->assertSee($zhArticle->title);
    }

    public function test_article_list_uses_locale_specific_empty_state_without_fallback(): void
    {
        app()->setLocale('en');

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $org = $this->createOrganization();
        app(OrgContext::class)->set((int) $org->id, (int) $admin->id, 'admin');
        $this->createArticle(0, 'zh-CN', '中文源文');

        session($this->opsSession($admin, $org, 'en'));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListArticles::class)
            ->loadTable()
            ->assertOk()
            ->assertDontSee('中文源文')
            ->assertSee('No content available in this language yet');
    }

    public function test_locale_scope_query_contract_is_shared_by_supported_content_models(): void
    {
        app()->setLocale('zh_CN');

        $org = $this->createOrganization();
        $this->createArticle((int) $org->id, 'zh-CN', '中文源文');
        $this->createArticle((int) $org->id, 'en', 'English source');
        $this->createSupportArticle('zh-CN', '支持中文');
        $this->createSupportArticle('en', 'Support English');
        $this->createInterpretationGuide('zh-CN', '解读中文');
        $this->createInterpretationGuide('en', 'Interpretation English');
        $this->createContentPage('zh-CN', '页面中文');
        $this->createContentPage('en', 'Content Page English');

        $this->assertSame(
            ['中文源文'],
            OpsContentLocaleScope::applyToQuery(Article::query()->where('org_id', $org->id), OpsContentLocaleScope::currentContentLocale())
                ->orderBy('id')
                ->pluck('title')
                ->all()
        );
        $this->assertSame(
            ['支持中文'],
            OpsContentLocaleScope::applyToQuery(SupportArticle::query()->withoutGlobalScopes(), OpsContentLocaleScope::currentContentLocale())
                ->orderBy('id')
                ->pluck('title')
                ->all()
        );
        $this->assertSame(
            ['解读中文'],
            OpsContentLocaleScope::applyToQuery(InterpretationGuide::query()->withoutGlobalScopes(), OpsContentLocaleScope::currentContentLocale())
                ->orderBy('id')
                ->pluck('title')
                ->all()
        );
        $this->assertSame(
            ['页面中文'],
            OpsContentLocaleScope::applyToQuery(ContentPage::query()->withoutGlobalScopes(), OpsContentLocaleScope::currentContentLocale())
                ->orderBy('id')
                ->pluck('title')
                ->all()
        );

        $this->assertCount(
            2,
            OpsContentLocaleScope::applyToQuery(SupportArticle::query()->withoutGlobalScopes(), OpsContentLocaleScope::ALL_LOCALES)->get()
        );
    }

    public function test_locale_scope_empty_state_preserves_non_locale_empty_state_copy(): void
    {
        app()->setLocale('en');

        $localeOnlyLivewire = new class
        {
            public string $tableSearch = '';

            /**
             * @var array<string, mixed>
             */
            public array $tableFilters = [
                'locale_scope' => ['value' => 'en'],
            ];
        };

        $allLocalesLivewire = new class
        {
            public string $tableSearch = '';

            /**
             * @var array<string, mixed>
             */
            public array $tableFilters = [
                'locale_scope' => ['value' => OpsContentLocaleScope::ALL_LOCALES],
            ];
        };

        $searchedLivewire = new class
        {
            public string $tableSearch = 'missing';

            /**
             * @var array<string, mixed>
             */
            public array $tableFilters = [
                'locale_scope' => ['value' => 'en'],
            ];
        };

        $this->assertSame(
            'No content available in this language yet',
            OpsContentLocaleScope::emptyStateDescription($localeOnlyLivewire, 'Article', true)
        );
        $this->assertSame(
            'Create the first article to start this workspace.',
            OpsContentLocaleScope::emptyStateDescription($allLocalesLivewire, 'Article', true)
        );
        $this->assertSame(
            'Try adjusting the current search or filters to widen the result set.',
            OpsContentLocaleScope::emptyStateDescription($searchedLivewire, 'Article', true)
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'ops_locale_scope_'.Str::lower(Str::random(6)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['guard_name' => (string) config('admin.guard', 'admin')]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function createOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Ops Locale Scope Org',
            'owner_user_id' => 9001,
            'status' => 'active',
            'domain' => 'ops-locale-scope.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function opsSession(AdminUser $admin, Organization $org, string $opsLocale): array
    {
        return [
            'ops_org_id' => $org->id,
            'ops_locale' => $opsLocale,
            'ops_admin_totp_verified_user_id' => $admin->id,
        ];
    }

    private function createArticle(int $orgId, string $locale, string $title): Article
    {
        return Article::query()->create([
            'org_id' => $orgId,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'locale' => $locale,
            'title' => $title,
            'excerpt' => 'Scope test excerpt',
            'content_md' => 'Scope test body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
    }

    private function createSupportArticle(string $locale, string $title): SupportArticle
    {
        return SupportArticle::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'support_category' => 'orders',
            'support_intent' => 'lookup_order',
            'locale' => $locale,
            'status' => SupportArticle::STATUS_DRAFT,
            'review_state' => SupportArticle::REVIEW_DRAFT,
        ]);
    }

    private function createInterpretationGuide(string $locale, string $title): InterpretationGuide
    {
        return InterpretationGuide::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'test_family' => 'general',
            'result_context' => 'how_to_read',
            'audience' => 'general',
            'locale' => $locale,
            'status' => InterpretationGuide::STATUS_DRAFT,
            'review_state' => InterpretationGuide::REVIEW_DRAFT,
        ]);
    }

    private function createContentPage(string $locale, string $title): ContentPage
    {
        return ContentPage::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'path' => '/'.Str::slug($title).'-'.Str::lower(Str::random(6)),
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'title' => $title,
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => $locale,
            'is_public' => false,
            'is_indexable' => true,
            'review_state' => 'draft',
            'status' => ContentPage::STATUS_DRAFT,
        ]);
    }
}
