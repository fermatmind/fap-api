<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\AdminUserResource\Pages\ListAdminUsers;
use App\Filament\Ops\Resources\ArticleCategoryResource\Pages\ListArticleCategories;
use App\Filament\Ops\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Ops\Resources\ArticleTagResource\Pages\ListArticleTags;
use App\Filament\Ops\Resources\ContentPageResource\Pages\ListContentPages;
use App\Filament\Ops\Resources\InterpretationGuideResource\Pages\ListInterpretationGuides;
use App\Filament\Ops\Resources\LandingSurfaceResource\Pages\ListLandingSurfaces;
use App\Filament\Ops\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Filament\Ops\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Filament\Ops\Resources\PermissionResource\Pages\ListPermissions;
use App\Filament\Ops\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Ops\Resources\SupportArticleResource\Pages\ListSupportArticles;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class OpsCmsListPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));

        $admin = $this->createAdminOwner();
        $org = $this->createOrganization();

        app(OrgContext::class)->set((int) $org->id, (int) $admin->id, 'admin');
        session([
            'ops_org_id' => $org->id,
            'ops_locale' => 'en',
            'ops_admin_totp_verified_user_id' => $admin->id,
        ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
    }

    public function test_content_lists_expose_operator_first_columns_and_filters(): void
    {
        $this->assertListContract(ListArticles::class, [
            'title',
            'status',
            'locale',
            'translation_status',
            'translation_stale',
            'updated_at',
        ], ['status', 'translation_status', 'locale_scope']);

        $this->assertListContract(ListSupportArticles::class, [
            'title',
            'slug',
            'locale',
            'translation_status',
            'support_category',
            'status',
            'updated_at',
        ], ['status', 'translation_status', 'locale_scope']);

        $this->assertListContract(ListInterpretationGuides::class, [
            'title',
            'slug',
            'locale',
            'translation_status',
            'test_family',
            'status',
            'updated_at',
        ], ['status', 'translation_status', 'locale_scope']);

        $this->assertListContract(ListContentPages::class, [
            'title',
            'slug',
            'locale',
            'translation_status',
            'kind',
            'status',
            'updated_at',
        ], ['status', 'translation_status', 'locale_scope']);
    }

    public function test_asset_taxonomy_and_governance_lists_keep_polished_columns(): void
    {
        $this->assertListContract(ListLandingSurfaces::class, [
            'surface_key',
            'locale',
            'status',
            'is_public',
            'updated_at',
        ], ['status', 'locale']);

        $this->assertListContract(ListMediaAssets::class, [
            'url',
            'asset_key',
            'mime_type',
            'status',
            'is_public',
            'updated_at',
        ], ['status']);

        $this->assertListContract(ListArticleCategories::class, [
            'name',
            'slug',
            'is_active',
            'sort_order',
            'updated_at',
        ], ['is_active']);

        $this->assertListContract(ListArticleTags::class, [
            'name',
            'slug',
            'is_active',
            'updated_at',
        ], ['is_active']);

        $this->assertListContract(ListAdminUsers::class, [
            'name',
            'email',
            'is_active',
            'last_login_at',
            'totp_enabled_at',
        ], ['is_active']);

        $this->assertListContract(ListOrganizations::class, [
            'name',
            'id',
            'status',
            'locale',
            'updated_at',
        ], ['status']);

        $this->assertListContract(ListRoles::class, [
            'name',
            'description',
            'permissions_count',
        ]);

        $this->assertListContract(ListPermissions::class, [
            'name',
            'description',
            'created_at',
        ]);
    }

    public function test_article_translation_filter_tracks_displayed_working_revision_status(): void
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'ops-list-polish-filter',
            'locale' => 'en',
            'source_locale' => 'zh-CN',
            'translation_group_id' => 'article-ops-list-polish-filter',
            'translation_status' => Article::TRANSLATION_STATUS_MACHINE_DRAFT,
            'title' => 'Ops List Polish Filter',
            'excerpt' => 'Filter test excerpt',
            'content_md' => 'Filter test body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => $article->id,
            'source_article_id' => $article->id,
            'translation_group_id' => 'article-ops-list-polish-filter',
            'locale' => 'en',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'source_version_hash' => 'source-hash',
            'translated_from_version_hash' => 'source-hash',
            'title' => 'Ops List Polish Filter',
            'excerpt' => 'Filter test excerpt',
            'content_md' => 'Filter test body',
        ]);

        $article->forceFill([
            'working_revision_id' => $revision->id,
        ])->save();

        Livewire::test(ListArticles::class)
            ->loadTable()
            ->filterTable('translation_status', Article::TRANSLATION_STATUS_HUMAN_REVIEW)
            ->assertSee('Ops List Polish Filter')
            ->filterTable('translation_status', Article::TRANSLATION_STATUS_MACHINE_DRAFT)
            ->assertDontSee('Ops List Polish Filter');
    }

    /**
     * @param  class-string<object>  $component
     * @param  list<string>  $columns
     * @param  list<string>  $filters
     */
    private function assertListContract(string $component, array $columns, array $filters = []): void
    {
        $test = Livewire::test($component)
            ->loadTable()
            ->assertOk();

        foreach ($columns as $column) {
            $test->assertTableColumnExists($column);
        }

        foreach ($filters as $filter) {
            $test->assertTableFilterExists($filter);
        }
    }

    private function createAdminOwner(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'ops_list_'.Str::lower(Str::random(6)),
            'email' => 'ops_list_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'ops_list_owner_'.Str::lower(Str::random(6)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        $permission = Permission::query()->firstOrCreate(
            ['name' => PermissionNames::ADMIN_OWNER],
            ['guard_name' => (string) config('admin.guard', 'admin')]
        );

        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function createOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Ops List Polish Org',
            'owner_user_id' => 9001,
            'status' => 'active',
            'domain' => 'ops-list-polish.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }
}
