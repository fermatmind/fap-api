<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Ops\Support\ArticleControlledPublicationWorkspace;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\Permission;
use App\Models\Role;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ArticlePublishedCanonicalWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_action_changes_only_missing_canonical(): void
    {
        $this->login();
        $article = $this->article();
        $before = $this->state($article);
        $data = app(ArticleControlledPublicationWorkspace::class)->prepareCanonical($article);
        $data['canonical_reviewed'] = true;
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->callAction('fillPublishedCanonical', $data)->assertHasNoActionErrors()
            ->assertNotified('自指 canonical 已补齐')->assertRedirect();
        $this->assertSame($data['canonical_url'], $article->fresh()->seoMeta->canonical_url);
        $this->assertSame($before, $this->state($article));
        Http::assertSentCount(1);
    }

    #[DataProvider('rejectCases')]
    public function test_invalid_state_cannot_write(string $case): void
    {
        $this->login();
        $article = $this->article();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareCanonical($article);
        $data['canonical_reviewed'] = true;
        match ($case) {
            'writer' => $this->login('admin.content.write'),
            'no_mfa' => session()->forget('ops_admin_totp_verified_user_id'),
            'stale' => $article->workingRevision->forceFill(['title' => 'Concurrent edit'])->saveQuietly(),
            'existing' => $article->seoMeta->forceFill(['canonical_url' => 'https://example.test/existing'])->saveQuietly(),
            'private_working' => $article->forceFill(['working_revision_id' => ArticleTranslationRevision::withoutGlobalScopes()->latest('id')->value('id')])->saveQuietly(),
            'missing_cache' => config(['ops.content_release_observability.cache_invalidation_urls' => []]),
            'tampered' => $data['canonical_url'] = 'https://example.test/other',
            'unreviewed' => $data['canonical_reviewed'] = false,
            'not_public' => $article->forceFill(['is_public' => false])->saveQuietly(),
            'tenant' => $article->forceFill(['org_id' => 23])->saveQuietly(),
            'missing_seo' => $article->seoMeta->delete(),
            'indexability_mismatch' => $article->seoMeta->forceFill(['is_indexable' => false])->saveQuietly(),
        };
        $before = $this->state($article);
        $canonical = $article->fresh()->seoMeta?->canonical_url;
        try {
            $workspace->fillCanonical($article, $data);
            $this->fail('Rejected condition must not write: '.$case);
        } catch (ValidationException|HttpException) {
            $this->assertSame($before, $this->state($article));
            $this->assertSame($canonical, $article->fresh()->seoMeta?->canonical_url);
        }
        Http::assertNothingSent();
    }

    public static function rejectCases(): array
    {
        return array_map(fn ($case) => [$case], ['writer', 'no_mfa', 'stale', 'existing', 'private_working', 'missing_cache', 'tampered', 'unreviewed', 'not_public', 'tenant', 'missing_seo', 'indexability_mismatch']);
    }

    public function test_audit_failure_rolls_back(): void
    {
        $this->login();
        $article = $this->article();
        $before = $this->state($article);
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareCanonical($article);
        $data['canonical_reviewed'] = true;
        $this->mock(\App\Services\Audit\AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Synthetic audit failure.'));
        try {
            $workspace->fillCanonical($article, $data);
            $this->fail('Audit failure must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure.', $exception->getMessage());
        }
        $this->assertSame($before, $this->state($article));
        $this->assertNull($article->fresh()->seoMeta->canonical_url);
        Http::assertNothingSent();
    }

    public function test_cache_failure_retains_commit_and_rejects_replay(): void
    {
        $this->login();
        $article = $this->article(false);
        $before = $this->state($article);
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareCanonical($article);
        $data['canonical_reviewed'] = true;
        $this->assertSame(['ok' => false, 'metadata' => 'committed', 'cache_closeout' => 'failed'], $workspace->fillCanonical($article, $data));
        $this->assertSame($data['canonical_url'], $article->fresh()->seoMeta->canonical_url);
        $this->assertSame($before, $this->state($article));
        $this->expectException(ValidationException::class);
        $workspace->fillCanonical($article, $data);
    }

    private function state(Article $article): array
    {
        return [$article->fresh()->getRawOriginal(), ArticleTranslationRevision::withoutGlobalScopes()->get()->map->getRawOriginal()->all(), \Illuminate\Support\Arr::except($article->fresh()->seoMeta?->getRawOriginal() ?? [], ['canonical_url', 'updated_at'])];
    }

    private function login(string $permission = 'admin.owner'): void
    {
        config(['admin.totp.enabled' => true,
            'ops.content_release_observability.cache_invalidation_urls' => ['https://cache.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'fixture-only-secret']);
        $role = Role::create(['name' => 'alt-editor-'.uniqid()]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => $permission], ['description' => $permission]));
        $user = AdminUser::create(['name' => 'Alt editor', 'email' => uniqid().'@example.test', 'password' => 'test-only', 'is_active' => true]);
        $user->roles()->attach($role);
        $this->actingAs($user, 'admin');
        session(['ops_admin_totp_verified_user_id' => (int) $user->id]);
    }

    private function article(bool $cacheOk = true): Article
    {
        $variants = [];
        foreach (['hero', 'card', 'thumbnail', 'og', 'preload'] as $key) {
            $variants[$key] = ['url' => 'https://assets.fermatmind.com/media/'.$key.'.jpg', 'width' => 1600, 'height' => 900, 'mime_type' => 'image/jpeg'];
        }
        $variants['editorial_package_v1'] = ['social_image_metadata' => ['alt_text' => 'Old English alt'], 'body_visual_asset_key' => 'article.mbti.body.v1', 'body_visual_image_url' => 'https://assets.fermatmind.com/media/body.jpg', 'body_visual_fallback_authorized' => false];
        $article = Article::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'mbti-cover-alt', 'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'translation_status' => 'source', 'title' => 'MBTI报告阅读', 'excerpt' => '报告阅读说明', 'content_md' => "## 阅读报告\n\n结合具体情境理解偏好。", 'cover_image_url' => $variants['hero']['url'], 'cover_image_alt' => 'Old English alt', 'cover_image_width' => 1672, 'cover_image_height' => 941, 'cover_image_variants' => $variants, 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'published_at' => now()]);
        foreach (['published', 'human_review'] as $i => $status) {
            $revision = ArticleTranslationRevision::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id, 'translation_group_id' => $article->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'revision_number' => $i + 1, 'revision_status' => $status, 'source_version_hash' => $article->source_version_hash, 'translated_from_version_hash' => $article->source_version_hash, 'title' => $article->title, 'content_md' => $article->content_md, 'published_at' => $i === 0 ? now() : null]);
            $article->forceFill([$i === 0 ? 'published_revision_id' : 'working_revision_id' => $revision->id])->saveQuietly();
        }
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => 'zh-CN', 'seo_title' => $article->title, 'seo_description' => '报告阅读说明', 'canonical_url' => null, 'robots' => 'index,follow', 'og_image_url' => $variants['og']['url'], 'is_indexable' => true]);
        $article->forceFill(['working_revision_id' => $article->published_revision_id])->saveQuietly();
        Http::fake(['*' => $cacheOk ? Http::response(['ok' => true, 'revalidated_paths' => ['/zh', '/zh/articles', '/zh/articles/mbti-cover-alt', '/llms.txt', '/llms-full.txt', '/en/articles', '/en/articles/mbti-cover-alt'], 'rejected_paths' => [], 'invalidated_tags' => ['article-detail:zh-CN:mbti-cover-alt', 'article-seo:zh-CN:mbti-cover-alt']]) : Http::response(['ok' => false], 503)]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
    }
}
