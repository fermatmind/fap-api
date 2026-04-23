<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ArticleTranslationOpsPage;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Ops\ArticleTranslationOpsService;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ArticleTranslationOpsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $fixtureSlugs = [
        'how-personality-shapes-attitude-toward-ai',
        'which-love-script-fits-you-best',
        'are-infj-men-rare-or-socially-silenced',
        'best-valentines-date-by-personality-and-relationship-science',
        'how-16-personality-types-talk-to-an-ai-coach',
        'childhood-dream-job-still-shapes-career-choice',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_translation_ops_page_lists_current_six_translation_groups(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $selectedOrg = $this->createOrganization();

        foreach ($this->fixtureSlugs as $slug) {
            $this->createPublishedTranslationGroup($slug);
        }

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/article-translation-ops')
            ->assertOk()
            ->assertSee('Translation Ops Console')
            ->assertSee('how-personality-shapes-attitude-toward-ai')
            ->assertSee('childhood-dream-job-still-shapes-career-choice');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ArticleTranslationOpsPage::class)
            ->assertOk()
            ->assertSet('metrics.translation_groups', 6)
            ->assertSet('metrics.stale_groups', 0)
            ->assertSet('metrics.ownership_mismatch_groups', 0)
            ->assertSee('how-16-personality-types-talk-to-an-ai-coach')
            ->assertSee('en published')
            ->assertSee('zh-CN source')
            ->assertSee('Create translation draft disabled')
            ->assertSee('Re-sync from source disabled');
    }

    public function test_translation_ops_service_flags_stale_translation_and_source_update_alert(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $group = $this->createPublishedTranslationGroup('stale-translation-fixture');
        $group['source']->forceFill([
            'source_version_hash' => 'source-hash-new',
        ])->saveQuietly();
        $group['sourceRevision']->forceFill([
            'source_version_hash' => 'source-hash-new',
            'translated_from_version_hash' => 'source-hash-new',
        ])->save();
        $group['translationRevision']->forceFill([
            'translated_from_version_hash' => 'source-hash-old',
        ])->save();

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'stale' => 'stale',
        ]);

        $this->assertSame(1, $dashboard['metrics']['stale_groups']);
        $this->assertCount(1, $dashboard['groups']);
        $this->assertSame(1, $dashboard['groups'][0]['stale_locales_count']);
        $this->assertContains('source updated after target review', collect($dashboard['groups'][0]['alerts'])->pluck('label')->all());
    }

    public function test_translation_ops_service_detects_missing_target_locale(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $this->createSourceOnlyGroup('missing-en-fixture');
        $this->createPublishedTranslationGroup('has-en-fixture');

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'target_locale' => 'en',
            'missing_locale' => true,
        ]);

        $this->assertSame(1, $dashboard['metrics']['missing_target_locale']);
        $this->assertCount(1, $dashboard['groups']);
        $this->assertSame('missing-en-fixture', $dashboard['groups'][0]['slug']);
        $this->assertContains('missing en locale', collect($dashboard['groups'][0]['alerts'])->pluck('label')->all());
    }

    public function test_translation_ops_service_detects_revision_ownership_mismatch(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $group = $this->createPublishedTranslationGroup('ownership-mismatch-fixture');
        $group['translation']->forceFill([
            'org_id' => 2,
        ])->saveQuietly();
        $group['translationRevision']->forceFill([
            'org_id' => 2,
        ])->save();

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'ownership' => 'mismatch',
        ]);

        $this->assertSame(1, $dashboard['metrics']['ownership_mismatch_groups']);
        $this->assertCount(1, $dashboard['groups']);
        $this->assertFalse($dashboard['groups'][0]['ownership_ok']);
        $this->assertContains('article org mismatch', $dashboard['groups'][0]['ownership_issues']);
        $this->assertContains('published revision org mismatch', $dashboard['groups'][0]['ownership_issues']);
    }

    public function test_translation_ops_service_prefers_public_source_when_duplicate_source_exists(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $group = $this->createPublishedTranslationGroup('duplicate-source-fixture');
        $duplicateSource = Article::query()->create([
            'org_id' => 2,
            'slug' => 'duplicate-source-fixture',
            'locale' => 'zh-CN',
            'translation_group_id' => $group['source']->translation_group_id,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '重复中文源文',
            'excerpt' => '重复摘要',
            'content_md' => '重复正文',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
        ]);
        $duplicateRevision = $this->createRevision($duplicateSource, [
            'source_article_id' => (int) $duplicateSource->id,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
        ]);
        $duplicateSource->forceFill([
            'working_revision_id' => (int) $duplicateRevision->id,
        ])->saveQuietly();

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'slug' => 'duplicate-source-fixture',
        ]);

        $this->assertCount(1, $dashboard['groups']);
        $this->assertSame((int) $group['source']->id, $dashboard['groups'][0]['source_article_id']);
        $this->assertFalse($dashboard['groups'][0]['canonical_ok']);
        $this->assertContains('canonical/source split risk', collect($dashboard['groups'][0]['alerts'])->pluck('label')->all());
    }

    public function test_translation_ops_service_flags_published_article_missing_published_revision(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $group = $this->createPublishedTranslationGroup('missing-published-revision-fixture');
        $group['translation']->forceFill([
            'published_revision_id' => null,
        ])->saveQuietly();

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'slug' => 'missing-published-revision-fixture',
        ]);

        $this->assertCount(1, $dashboard['groups']);
        $this->assertContains('missing published revision', collect($dashboard['groups'][0]['alerts'])->pluck('label')->all());
    }

    public function test_translation_ops_published_filter_respects_selected_target_locale(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $this->createSourceOnlyGroup('source-only-published-fixture');
        $this->createPublishedTranslationGroup('target-published-fixture');

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'target_locale' => 'en',
            'published' => 'unpublished',
        ]);

        $this->assertCount(1, $dashboard['groups']);
        $this->assertSame('source-only-published-fixture', $dashboard['groups'][0]['slug']);
    }

    /**
     * @return array{source:Article,translation:Article,sourceRevision:ArticleTranslationRevision,translationRevision:ArticleTranslationRevision}
     */
    private function createPublishedTranslationGroup(string $slug): array
    {
        $source = $this->createSourceOnlyGroup($slug);
        $sourceHash = (string) $source->source_version_hash;

        $translation = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'en',
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'translated_from_article_id' => (int) $source->id,
            'source_article_id' => (int) $source->id,
            'translated_from_version_hash' => $sourceHash,
            'title' => 'English '.$slug,
            'excerpt' => 'English excerpt',
            'content_md' => 'English body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $translationRevision = $this->createRevision($translation, [
            'source_article_id' => (int) $source->id,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $sourceHash,
            'translated_from_version_hash' => $sourceHash,
            'published_at' => now(),
        ]);
        $translation->forceFill([
            'working_revision_id' => (int) $translationRevision->id,
            'published_revision_id' => (int) $translationRevision->id,
        ])->saveQuietly();

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $translation->id,
            'locale' => 'en',
            'seo_title' => 'English SEO '.$slug,
            'seo_description' => 'English SEO description',
            'is_indexable' => true,
        ]);

        return [
            'source' => $source,
            'translation' => $translation,
            'sourceRevision' => $source->workingRevision,
            'translationRevision' => $translationRevision,
        ];
    }

    private function createSourceOnlyGroup(string $slug): Article
    {
        $source = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '中文 '.$slug,
            'excerpt' => '中文摘要',
            'content_md' => '中文正文',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $sourceHash = (string) $source->source_version_hash;
        $sourceRevision = $this->createRevision($source, [
            'source_article_id' => (int) $source->id,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
            'source_version_hash' => $sourceHash,
            'translated_from_version_hash' => $sourceHash,
            'published_at' => now(),
        ]);
        $source->forceFill([
            'working_revision_id' => (int) $sourceRevision->id,
            'published_revision_id' => (int) $sourceRevision->id,
        ])->saveQuietly();

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $source->id,
            'locale' => 'zh-CN',
            'seo_title' => '中文 SEO '.$slug,
            'seo_description' => '中文 SEO 描述',
            'is_indexable' => true,
        ]);

        return $source->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRevision(Article $article, array $overrides = []): ArticleTranslationRevision
    {
        return ArticleTranslationRevision::query()->create(array_merge([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) ($article->source_article_id ?: $article->id),
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
            'source_version_hash' => $article->source_version_hash,
            'translated_from_version_hash' => $article->translated_from_version_hash ?: $article->source_version_hash,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
        ], $overrides));
    }

    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'translation_ops_'.Str::lower(Str::random(6)),
            'email' => 'translation_ops_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'translation_ops_role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function createOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Translation Ops Org',
            'owner_user_id' => 5101,
            'status' => 'active',
            'domain' => 'translation-ops.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @return array{ops_org_id:int,ops_locale:string,ops_admin_totp_verified_user_id:int}
     */
    private function opsSession(AdminUser $admin, Organization $org): array
    {
        return [
            'ops_org_id' => (int) $org->id,
            'ops_locale' => 'en',
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];
    }
}
