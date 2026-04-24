<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Resources\ContentPageResource\Pages\EditContentPage;
use App\Filament\Ops\Resources\InterpretationGuideResource\Pages\EditInterpretationGuide;
use App\Filament\Ops\Resources\LandingSurfaceResource\Pages\EditLandingSurface;
use App\Filament\Ops\Resources\SupportArticleResource\Pages\EditSupportArticle;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\LandingSurface;
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

final class OpsCmsEditPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));

        $admin = $this->createAdminOwner();
        $org = $this->createOrganization($admin);

        app(OrgContext::class)->set((int) $org->id, (int) $admin->id, 'admin');
        session([
            'ops_org_id' => $org->id,
            'ops_locale' => 'en',
            'ops_admin_totp_verified_user_id' => $admin->id,
        ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
    }

    public function test_core_cms_edit_pages_expose_metadata_rail_sections(): void
    {
        $this->assertEditPageHasRail(EditArticle::class, $this->createArticle()->getKey());
        $this->assertEditPageHasRail(EditSupportArticle::class, $this->createSupportArticle()->getKey());
        $this->assertEditPageHasRail(EditInterpretationGuide::class, $this->createInterpretationGuide()->getKey());
        $this->assertEditPageHasRail(EditContentPage::class, $this->createContentPage()->getKey());
        $this->assertEditPageHasRail(EditLandingSurface::class, $this->createLandingSurface()->getKey(), expectsTranslation: false, expectsRevision: false, expectsSeo: false);
    }

    /**
     * @param  class-string<object>  $component
     */
    private function assertEditPageHasRail(
        string $component,
        int|string $record,
        bool $expectsTranslation = true,
        bool $expectsRevision = true,
        bool $expectsSeo = true,
    ): void {
        $test = Livewire::test($component, ['record' => $record])
            ->assertOk()
            ->assertSee(__('ops.edit.sections.status_visibility'))
            ->assertSee(__('ops.edit.sections.publish_readiness'))
            ->assertSee(__('ops.edit.sections.audit'))
            ->assertSee(__('ops.edit.tabs.content'))
            ->assertSee(__('ops.resources.articles.actions.save'));

        if ($expectsTranslation) {
            $test->assertSee(__('ops.edit.sections.translation'));
        }

        if ($expectsRevision) {
            $test->assertSee(__('ops.edit.sections.revision'));
        }

        if ($expectsSeo) {
            $test->assertSee(__('ops.edit.sections.seo'));
        }
    }

    private function createArticle(): Article
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'ops-edit-polish-article',
            'locale' => 'en',
            'source_locale' => 'en',
            'translation_group_id' => 'article-ops-edit-polish',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Ops Edit Polish Article',
            'excerpt' => 'Edit polish excerpt.',
            'content_md' => 'Edit polish body.',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => $article->id,
            'source_article_id' => $article->id,
            'translation_group_id' => 'article-ops-edit-polish',
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
            'source_version_hash' => 'ops-edit-polish-source',
            'translated_from_version_hash' => 'ops-edit-polish-source',
            'title' => 'Ops Edit Polish Article',
            'excerpt' => 'Edit polish excerpt.',
            'content_md' => 'Edit polish body.',
            'seo_title' => 'Ops Edit Polish SEO',
            'seo_description' => 'Ops edit polish description.',
        ]);

        $article->forceFill([
            'working_revision_id' => $revision->id,
        ])->save();

        return $article;
    }

    private function createSupportArticle(): SupportArticle
    {
        return SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => 'ops-edit-polish-support',
            'title' => 'Ops Edit Polish Support',
            'summary' => 'Support summary.',
            'body_md' => 'Support body.',
            'support_category' => 'orders',
            'support_intent' => 'lookup_order',
            'locale' => 'en',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_SOURCE,
            'status' => SupportArticle::STATUS_DRAFT,
            'review_state' => SupportArticle::REVIEW_DRAFT,
            'seo_title' => 'Support SEO',
            'seo_description' => 'Support SEO description.',
            'canonical_path' => '/en/support/ops-edit-polish-support',
        ]);
    }

    private function createInterpretationGuide(): InterpretationGuide
    {
        return InterpretationGuide::query()->create([
            'org_id' => 0,
            'slug' => 'ops-edit-polish-interpretation',
            'title' => 'Ops Edit Polish Interpretation',
            'summary' => 'Interpretation summary.',
            'body_md' => 'Interpretation body.',
            'test_family' => 'mbti',
            'result_context' => 'type_profile',
            'audience' => 'general',
            'locale' => 'en',
            'translation_status' => InterpretationGuide::TRANSLATION_STATUS_SOURCE,
            'status' => InterpretationGuide::STATUS_DRAFT,
            'review_state' => InterpretationGuide::REVIEW_DRAFT,
            'seo_title' => 'Interpretation SEO',
            'seo_description' => 'Interpretation SEO description.',
            'canonical_path' => '/en/interpretation/ops-edit-polish-interpretation',
        ]);
    }

    private function createContentPage(): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'ops-edit-polish-page',
            'path' => '/en/ops-edit-polish-page',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'title' => 'Ops Edit Polish Page',
            'summary' => 'Content page summary.',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
            'status' => ContentPage::STATUS_DRAFT,
            'review_state' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'content_md' => 'Content page body.',
            'seo_title' => 'Content Page SEO',
            'seo_description' => 'Content page SEO description.',
            'canonical_path' => '/en/ops-edit-polish-page',
        ]);
    }

    private function createLandingSurface(): LandingSurface
    {
        return LandingSurface::query()->create([
            'org_id' => 0,
            'surface_key' => 'ops_edit_polish_surface',
            'locale' => 'en',
            'title' => 'Ops Edit Polish Surface',
            'description' => 'Landing surface description.',
            'schema_version' => 'v1',
            'payload_json' => ['modules' => []],
            'status' => LandingSurface::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
        ]);
    }

    private function createAdminOwner(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'ops_edit_'.Str::lower(Str::random(6)),
            'email' => 'ops_edit_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'ops_edit_owner_'.Str::lower(Str::random(6)),
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

    private function createOrganization(AdminUser $admin): Organization
    {
        return Organization::query()->create([
            'name' => 'Ops Edit Polish Org',
            'owner_user_id' => (int) $admin->id,
            'status' => 'active',
            'domain' => 'ops-edit-polish.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }
}
