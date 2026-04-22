<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Ops\Support\OpsContentLocaleScope;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ArticleTranslationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_article_defaults_to_source_translation_contract(): void
    {
        $article = $this->createArticle(1, 'zh-CN', '中文源文');

        $this->assertSame('zh-CN', $article->source_locale);
        $this->assertSame(Article::TRANSLATION_STATUS_SOURCE, $article->translation_status);
        $this->assertNull($article->translated_from_article_id);
        $this->assertNull($article->translated_from_version_hash);
        $this->assertNotEmpty($article->translation_group_id);
        $this->assertSame($article->computeSourceVersionHash(), $article->source_version_hash);
        $this->assertTrue($article->isSourceArticle());
        $this->assertFalse($article->isTranslationArticle());
        $this->assertFalse($article->isTranslationStale());
    }

    public function test_translation_article_links_to_source_and_group(): void
    {
        $source = $this->createArticle(1, 'zh-CN', '中文源文');
        $translation = $this->createArticle(1, 'en', 'English translation', [
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => $source->source_locale,
            'translation_status' => Article::TRANSLATION_STATUS_MACHINE_DRAFT,
            'translated_from_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ]);

        $this->assertSame($source->translation_group_id, $translation->translation_group_id);
        $this->assertSame('zh-CN', $translation->source_locale);
        $this->assertSame($source->id, $translation->translated_from_article_id);
        $this->assertTrue($translation->isTranslationArticle());
        $this->assertFalse($translation->isSourceArticle());
        $this->assertFalse($translation->isTranslationStale($source));
        $this->assertTrue($translation->translatedFrom->is($source));
        $this->assertSame($source->source_version_hash, $translation->currentSourceVersionHash());
    }

    public function test_translation_is_stale_when_source_hash_changes(): void
    {
        $source = $this->createArticle(1, 'zh-CN', '中文源文');
        $translation = $this->createArticle(1, 'en', 'English translation', [
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => $source->source_locale,
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ]);

        $source->forceFill(['title' => '中文源文已更新'])->save();
        $translation->refresh();

        $this->assertNotSame($source->source_version_hash, $translation->translated_from_version_hash);
        $this->assertTrue($translation->isTranslationStale($source));
    }

    public function test_translation_contract_migration_backfills_existing_articles(): void
    {
        $article = $this->createArticle(1, 'zh-CN', '旧中文草稿');
        DB::table('articles')->where('id', $article->id)->update([
            'translation_group_id' => null,
            'source_locale' => null,
            'translated_from_article_id' => null,
            'source_version_hash' => null,
            'translated_from_version_hash' => null,
        ]);

        $migration = require database_path('migrations/2026_04_23_000100_add_article_translation_contract_v1.php');
        $migration->up();

        $article->refresh();

        $this->assertSame('article-'.$article->id, $article->translation_group_id);
        $this->assertSame('zh-CN', $article->source_locale);
        $this->assertSame(Article::TRANSLATION_STATUS_SOURCE, $article->translation_status);
        $this->assertNull($article->translated_from_article_id);
        $this->assertNull($article->translated_from_version_hash);
        $this->assertSame($article->computeSourceVersionHash(), $article->source_version_hash);
    }

    public function test_article_locale_scoped_list_still_uses_current_ops_locale(): void
    {
        app()->setLocale('en');

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $org = $this->createOrganization();
        app(OrgContext::class)->set((int) $org->id, (int) $admin->id, 'admin');

        $zhArticle = $this->createArticle((int) $org->id, 'zh-CN', '中文源文');
        $enArticle = $this->createArticle((int) $org->id, 'en', 'English source');

        session($this->opsSession($admin, $org, 'en'));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ListArticles::class)
            ->loadTable()
            ->assertOk()
            ->assertSee($enArticle->title)
            ->assertDontSee($zhArticle->title)
            ->assertTableColumnExists('source_locale')
            ->assertTableColumnExists('translation_status')
            ->assertTableColumnExists('translation_group_id')
            ->assertTableColumnExists('translation_stale')
            ->filterTable('locale_scope', OpsContentLocaleScope::ALL_LOCALES)
            ->assertSee($enArticle->title)
            ->assertSee($zhArticle->title);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(int $orgId, string $locale, string $title, array $overrides = []): Article
    {
        return Article::query()->create(array_merge([
            'org_id' => $orgId,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'locale' => $locale,
            'title' => $title,
            'excerpt' => 'Translation contract excerpt',
            'content_md' => 'Translation contract body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ], $overrides));
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
            'name' => 'article_translation_contract_'.Str::lower(Str::random(6)),
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
            'name' => 'Article Translation Contract Org',
            'owner_user_id' => 9101,
            'status' => 'active',
            'domain' => 'article-translation-contract.example.test',
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
}
