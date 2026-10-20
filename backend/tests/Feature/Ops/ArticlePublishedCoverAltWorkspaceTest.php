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

final class ArticlePublishedCoverAltWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_action_changes_only_alt_and_preserves_versions_and_original_dimensions(): void
    {
        $this->login();
        $article = $this->article();
        $before = $article->getRawOriginal();
        $revisions = ArticleTranslationRevision::withoutGlobalScopes()->get()->map->getRawOriginal()->all();
        $seo = $article->seoMeta->getRawOriginal();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareCoverAlt($article);
        $data['cover_image_alt'] = '读者把人格偏好与工作和沟通情境联系起来。';
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->callAction('updatePublishedCoverAlt', $data)->assertHasNoActionErrors()
            ->assertNotified(__('ops.resources.articles.cover_alt.success'))->assertRedirect();
        $article->refresh();
        $this->assertSame($data['cover_image_alt'], $article->cover_image_alt);
        $this->assertSame(1672, $article->cover_image_width);
        $this->assertSame(941, $article->cover_image_height);
        foreach (array_diff(array_keys($before), ['cover_image_alt', 'cover_image_variants', 'updated_at']) as $key) {
            $this->assertSame($before[$key], $article->getRawOriginal($key), $key);
        }
        $this->assertSame($revisions, ArticleTranslationRevision::withoutGlobalScopes()->get()->map->getRawOriginal()->all());
        $this->assertSame(\Illuminate\Support\Arr::except($seo, ['updated_at']), \Illuminate\Support\Arr::except($article->fresh()->seoMeta->getRawOriginal(), ['updated_at']));
        $this->assertSame($data['cover_image_alt'], data_get($article->cover_image_variants, 'editorial_package_v1.social_image_metadata.alt_text'));
        Http::assertSentCount(1);
        $this->getJson('/api/v0.5/articles/mbti-cover-alt?locale=zh-CN')->assertOk()->assertJsonPath('article.cover_image_alt', $data['cover_image_alt']);
    }

    public function test_alt_only_keeps_a_different_seo_body_visual_override(): void
    {
        $this->login();
        $article = $this->article();
        $schema = ['editorial_package_v1' => ['body_visual_asset_key' => 'seo.body.authority', 'body_visual_image_url' => 'https://assets.fermatmind.com/media/seo-override.jpg', 'body_visual_fallback_authorized' => true]];
        $article->seoMeta->forceFill(['schema_json' => $schema])->saveQuietly();
        $variants = $article->cover_image_variants;
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareCoverAlt($article);
        $data['cover_image_alt'] = 'Updated cover description';
        $this->assertTrue($workspace->updateCoverAlt($article, $data)['ok']);
        $this->assertSame($schema, $article->fresh()->seoMeta->schema_json);
        foreach (['body_visual_asset_key', 'body_visual_image_url', 'body_visual_fallback_authorized'] as $key) {
            $this->assertSame($variants['editorial_package_v1'][$key], $article->fresh()->cover_image_variants['editorial_package_v1'][$key]);
        }
    }

    public function test_native_media_selector_output_needs_no_editorial_asset_key(): void
    {
        $this->login();
        $article = $this->article();
        $asset = \App\Models\MediaAsset::withoutGlobalScopes()->create(['org_id' => 0, 'asset_key' => 'native-selector-cover', 'disk' => 'public', 'path' => 'media-library/original.jpg', 'url' => 'https://assets.fermatmind.com/storage/media-library/original.jpg', 'mime_type' => 'image/jpeg', 'width' => 1672, 'height' => 941, 'alt' => 'Native selector alt', 'status' => 'published', 'is_public' => true, 'sync_status' => 'synced', 'cdn_status' => 'verified']);
        foreach (['hero', 'card', 'thumbnail', 'og', 'preload'] as $key) {
            \App\Models\MediaVariant::create(['media_asset_id' => $asset->id, 'variant_key' => $key, 'path' => 'media-library/'.$key.'.jpg', 'url' => 'https://assets.fermatmind.com/storage/media-library/'.$key.'.jpg', 'width' => 1600, 'height' => 900, 'mime_type' => 'image/jpeg', 'sync_status' => 'synced', 'cdn_status' => 'verified', 'verified_at' => now()]);
        }
        $payload = \App\Filament\Ops\Resources\ArticleResource::articleCoverPayload($asset);
        $this->assertNotNull($payload['cover_image_url']);
        $this->assertArrayNotHasKey('editorial_package_v1', $payload['cover_image_variants']);
        $article->forceFill($payload)->saveQuietly();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareCoverAlt($article);
        $data['cover_image_alt'] = '中文图示说明';
        $this->assertTrue($workspace->updateCoverAlt($article, $data)['ok']);
        $this->assertSame($payload['cover_image_variants'], $article->fresh()->cover_image_variants);
        $this->assertSame('中文图示说明', $article->fresh()->cover_image_alt);
    }

    #[DataProvider('rejectCases')]
    public function test_unauthorized_stale_or_invalid_alt_never_changes_metadata(string $case): void
    {
        $this->login();
        $article = $this->article();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareCoverAlt($article);
        $data['cover_image_alt'] = 'Updated accessible description';
        if ($case === 'stale') {
            $article->workingRevision->forceFill(['title' => 'Concurrent edit'])->saveQuietly();
        } elseif ($case === 'writer') {
            $this->login('admin.content.write');
        } elseif ($case === 'no_mfa') {
            session()->forget('ops_admin_totp_verified_user_id');
        } elseif ($case === 'missing_cache') {
            config(['ops.content_release_observability.cache_invalidation_urls' => []]);
        } elseif ($case === 'empty') {
            $data['cover_image_alt'] = '';
        } elseif ($case === 'long') {
            $data['cover_image_alt'] = str_repeat('字', 256);
        }
        $before = $article->fresh()->getRawOriginal();
        try {
            $workspace->updateCoverAlt($article, $data);
            $this->fail('Rejected condition must not write: '.$case);
        } catch (ValidationException|HttpException) {
            $this->assertSame($before, $article->fresh()->getRawOriginal());
        }
        Http::assertNothingSent();
    }

    public static function rejectCases(): array
    {
        return [['stale'], ['writer'], ['no_mfa'], ['missing_cache'], ['empty'], ['long']];
    }

    public function test_audit_failure_rolls_back_native_media_update(): void
    {
        $this->login();
        $article = $this->article();
        $before = $article->getRawOriginal();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareCoverAlt($article);
        $data['cover_image_alt'] = 'Updated description';
        $this->mock(\App\Services\Audit\AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('Synthetic audit failure.'));
        try {
            $workspace->updateCoverAlt($article, $data);
            $this->fail('Audit failure must roll back metadata.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure.', $exception->getMessage());
        }
        $this->assertSame($before, $article->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_cache_failure_reports_committed_metadata_and_old_snapshot_cannot_replay(): void
    {
        $this->login();
        Http::fake(['*' => Http::response(['ok' => false], 503)]);
        $article = $this->article();
        $workspace = app(ArticleControlledPublicationWorkspace::class);
        $data = $workspace->prepareCoverAlt($article);
        $data['cover_image_alt'] = 'Updated description';
        $result = $workspace->updateCoverAlt($article, $data);
        $this->assertSame(['ok' => false, 'metadata' => 'committed', 'cache_closeout' => 'failed'], $result);
        $this->assertSame('Updated description', $article->fresh()->cover_image_alt);
        try {
            $workspace->updateCoverAlt($article, $data);
            $this->fail('An old snapshot cannot replay a committed update.');
        } catch (ValidationException) {
            $this->assertSame('Updated description', $article->fresh()->cover_image_alt);
        }
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

    private function article(): Article
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
        ArticleSeoMeta::withoutGlobalScopes()->create(['org_id' => 0, 'article_id' => $article->id, 'locale' => 'zh-CN', 'seo_title' => $article->title, 'seo_description' => '报告阅读说明', 'canonical_url' => '/zh/articles/mbti-cover-alt', 'robots' => 'index,follow', 'og_image_url' => $variants['og']['url'], 'is_indexable' => true]);
        Http::fake(['*' => Http::response(['ok' => true, 'revalidated_paths' => ['/zh', '/zh/articles', '/zh/articles/mbti-cover-alt', '/llms.txt', '/llms-full.txt', '/en/articles', '/en/articles/mbti-cover-alt'], 'rejected_paths' => [], 'invalidated_tags' => ['article-detail:zh-CN:mbti-cover-alt', 'article-seo:zh-CN:mbti-cover-alt']])]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
    }
}
