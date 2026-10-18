<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\MediaAsset;
use App\Services\Cms\CareerInterestsValuesArticleRelease as Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class CareerInterestsValuesArticleReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('s3');
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => 1,
            'fap.media.oss_sync_enabled' => true, 'fap.media.oss_disk' => 's3', 'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
            'ops.content_release_observability.hmac_revalidation_url' => 'https://frontend.example.test/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'fixture-only-secret']);
        AdminUser::query()->create(['id' => 1, 'name' => 'Fixture owner', 'email' => 'owner@example.test',
            'password' => 'fixture-only-password', 'is_active' => 1]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/content-release/revalidate')) {
                $body = json_decode($request->body(), true);
                $content = $body['content'];

                return Http::response(['ok' => true, 'revalidated_paths' => $body['cache_signal']['paths'], 'rejected_paths' => [],
                    'invalidated_tags' => ['article-detail:'.$content['locale'].':'.$content['slug'], 'article-seo:'.$content['locale'].':'.$content['slug']]]);
            }

            return Http::response('', 200, ['Content-Type' => 'image/jpeg']);
        });
    }

    public function test_dry_run_then_native_pair_publication_and_replay(): void
    {
        $service = app(Release::class);
        $this->assertSame('ready_for_media_and_draft_import', $service->run(true)['status']);
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
        $this->assertSame(0, MediaAsset::withoutGlobalScopes()->count());
        Http::assertNothingSent();
        $this->assertSame(['status' => 'published', 'changed' => 2], $service->run());
        foreach (Release::SLUGS as $locale => $slug) {
            $candidate = json_decode(file_get_contents(base_path(Release::ROOT.'/'.$locale.'.json')), true);
            $this->getJson('/api/v0.5/articles/'.$slug.'?locale='.$locale.'&org_id=0')
                ->assertOk()->assertJsonPath('article.content_md', trim($candidate['body_markdown']))
                ->assertJsonPath('article.title', $candidate['title']);
            $article = Article::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
            $this->assertSame(Release::SHA256, $article->publishedRevision->authority_package_sha256);
            $this->assertTrue($article->sitemap_eligible);
            $this->assertTrue($article->llms_eligible);
            $this->assertSame($candidate['cover_image_alt'], $article->cover_image_alt);
        }
        $before = DB::table('article_translation_revisions')->get()->toJson();
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], $service->run());
        $this->assertSame($before, DB::table('article_translation_revisions')->get()->toJson());
        $this->assertSame(1, MediaAsset::withoutGlobalScopes()->count());
    }

    public function test_existing_unowned_draft_is_never_overwritten(): void
    {
        Article::withoutGlobalScopes()->create(['org_id' => 0, 'locale' => 'zh-CN',
            'slug' => Release::SLUGS['zh-CN'], 'title' => 'Unrelated working draft', 'content_md' => 'Keep', 'status' => 'draft']);
        $this->expectExceptionMessage('existing identity collision');
        try {
            app(Release::class)->run();
        } finally {
            $this->assertSame('Keep', Article::withoutGlobalScopes()->first()->content_md);
            $this->assertSame(0, MediaAsset::withoutGlobalScopes()->count());
            Http::assertNothingSent();
        }
    }

    public function test_second_locale_publication_failure_rolls_back_the_whole_pair(): void
    {
        Article::updated(function (Article $article): void {
            if ($article->locale === 'en' && $article->is_public) {
                throw new RuntimeException('Fixture second locale failure');
            }
        });
        try {
            app(Release::class)->run();
            $this->fail('The second locale failure must reject the release.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('native publication failed', $error->getMessage());
        } finally {
            Article::flushEventListeners();
        }
        $this->assertSame(0, Article::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('article_translation_revisions')->count());
        $this->assertSame(0, MediaAsset::withoutGlobalScopes()->count());
    }
}
