<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use App\Services\Cms\EnneagramArticleRefresh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class EnneagramArticleRefreshTest extends TestCase
{
    use RefreshDatabase;

    private bool $mismatchedCacheReceipt = false;

    private bool $mismatchedDiscoveryReceipt = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => 1,
            'ops.content_release_observability.hmac_revalidation_url' => 'https://frontend.example.test/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'fixture-only-revalidation-secret']);
        Http::preventStrayRequests();
        $this->successfulCacheReceipts();
        AdminUser::query()->create(['name' => 'Fixture owner', 'email' => 'owner@example.test', 'password' => 'fixture-only-password', 'is_active' => 1]);
    }

    public function test_native_bilingual_publication_binds_fresh_source_reviews_and_preserves_history_media_and_metadata(): void
    {
        $this->seedBaseline();
        $before = $this->state();
        $result = app(EnneagramArticleRefresh::class)->publish();
        $this->assertSame(['status' => 'published', 'changed' => 2], $result);
        $package = json_decode(file_get_contents(base_path(EnneagramArticleRefresh::PACKAGE)), true);
        $source = Article::withoutGlobalScopes()->findOrFail(51);
        foreach (['zh-CN' => 51, 'en' => 190] as $locale => $id) {
            $article = Article::withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail($id);
            $old = $before['articles'][(string) $id];
            $this->assertSame($package['candidates'][$locale]['content_md'], $article->content_md);
            $this->assertNotSame($old->published_revision_id, $article->published_revision_id);
            $this->assertSame($article->published_revision_id, $article->working_revision_id);
            $this->assertSame($source->source_version_hash, $article->publishedRevision->translated_from_version_hash);
            $this->assertSame(EnneagramArticleRefresh::PACKAGE_SHA256, $article->publishedRevision->authority_package_sha256);
            $this->assertTrue($article->is_indexable && $article->sitemap_eligible && $article->llms_eligible);
            $this->assertSame('index,follow', $article->seoMeta->robots);
            $this->assertTrue(data_get($article->seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled'));
            $surfaceFaq = collect(data_get($article->seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'))
                ->firstWhere('question', $package['candidates'][$locale]['faq']['question']);
            $this->assertSame($package['candidates'][$locale]['faq']['answer'], $surfaceFaq['answer']);
            foreach (['title', 'excerpt', 'slug', 'locale', 'category_id', 'cover_image_url', 'cover_image_alt',
                'cover_image_width', 'cover_image_height', 'cover_image_variants', 'related_test_slug', 'voice', 'voice_order', 'published_at'] as $key) {
                $this->assertEquals($old->$key, $article->getRawOriginal($key), $key);
            }
            foreach (['seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description', 'og_image_url'] as $key) {
                $this->assertEquals($before['seo'][(string) $id]->$key, $article->seoMeta->getRawOriginal($key), $key);
            }
            app(CmsEditorialReviewAttestationService::class)->assertApprovedEvidence([
                ['surface_id' => 'article', 'record' => $article],
                ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
            ]);
            $latest = DB::table('content_material_decisions')->where('authority_subject_key', 'article:'.$id)->orderByDesc('id')->first();
            $current = app(ArticleMaterialDecisionService::class)->recordPublished($article, $article->publishedRevision, now());
            $this->assertSame($latest->id, $current->id, 'Final material fingerprint must already match the public state.');
            $oldRevision = (array) DB::table('article_translation_revisions')->find($old->published_revision_id);
            $frozen = (array) $before['revisions'][(string) $old->published_revision_id];
            unset($oldRevision['revision_status'], $oldRevision['updated_at'], $frozen['revision_status'], $frozen['updated_at']);
            $this->assertSame($frozen, $oldRevision);
            $this->getJson('/api/v0.5/articles/enneagram-personality-test-explained?locale='.$locale)
                ->assertOk()->assertJsonPath('article.content_md', $package['candidates'][$locale]['content_md'])
                ->assertJsonPath('article.is_indexable', true);
            $seo = $this->getJson('/api/v0.5/articles/enneagram-personality-test-explained/seo?locale='.$locale)
                ->assertOk()->assertJsonPath('meta.robots', 'index,follow');
            $this->assertSame('https://fermatmind.com/en/articles/enneagram-personality-test-explained', $seo->json('meta.alternates.en'));
            $this->assertSame('https://fermatmind.com/zh/articles/enneagram-personality-test-explained', $seo->json('meta.alternates.zh-CN'));
        }
        $this->assertSame($source->source_version_hash, Article::withoutGlobalScopes()->findOrFail(190)->translated_from_version_hash);
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(EnneagramArticleRefresh::class)->publish());
        $this->assertSame(4, DB::table('article_translation_revisions')->count());
        Http::assertSentCount(6);
    }

    public function test_dry_run_is_write_free_and_accepts_the_real_stale_english_article_binding_only_as_a_baseline(): void
    {
        $this->seedBaseline();
        $before = $this->state();
        $this->assertSame(['status' => 'ready', 'changed' => 0], app(EnneagramArticleRefresh::class)->publish(true));
        $this->assertEquals($before, $this->state());
    }

    public function test_foreign_working_draft_fails_without_overwrite(): void
    {
        $this->seedBaseline();
        $published = ArticleTranslationRevision::withoutGlobalScopes()->findOrFail(767);
        $draft = $published->replicate()->forceFill(['revision_number' => 4, 'revision_status' => 'human_review']);
        $draft->saveQuietly();
        DB::table('articles')->where('id', 190)->update(['working_revision_id' => $draft->id]);
        $this->assertRejectedWithoutWrites();
    }

    public function test_content_drift_rejects_the_whole_pair(): void
    {
        $this->seedBaseline();
        DB::table('articles')->where('id', 190)->update(['content_md' => 'Concurrent editorial update']);
        $this->assertRejectedWithoutWrites();
    }

    public function test_manual_hold_is_preserved(): void
    {
        $this->seedBaseline();
        DB::table('articles')->where('id', 190)->update(['lifecycle_note' => 'manual review hold']);
        $this->assertRejectedWithoutWrites();
    }

    public function test_editorial_hold_is_preserved(): void
    {
        $this->seedBaseline();
        DB::table('editorial_reviews')->where('content_id', 190)->update(['workflow_state' => 'changes_requested']);
        $this->assertRejectedWithoutWrites();
    }

    public function test_staging_missing_seo_and_older_source_are_published_through_the_same_native_chain(): void
    {
        $this->seedBaseline('staging');
        $this->assertSame(['status' => 'ready', 'changed' => 0], app(EnneagramArticleRefresh::class)->publish(true));
        $this->assertSame(['status' => 'published', 'changed' => 2], app(EnneagramArticleRefresh::class)->publish());
        $this->assertDatabaseHas('article_seo_meta', ['article_id' => 190, 'locale' => 'en', 'robots' => 'index,follow']);
        $source = Article::withoutGlobalScopes()->findOrFail(51);
        $this->assertTrue($source->isSourceArticle());
        $this->assertSame($source->source_version_hash, Article::withoutGlobalScopes()->findOrFail(190)->translated_from_version_hash);
    }

    public function test_second_language_native_failure_rolls_back_both_languages_reviews_and_material_decisions(): void
    {
        $this->seedBaseline();
        $before = $this->state();
        $event = 'eloquent.updating: '.Article::class;
        Event::listen($event, function (Article $article): void {
            if ($article->locale === 'en' && $article->isDirty('content_md')) {
                throw new RuntimeException('injected second-language promotion failure');
            }
        });
        try {
            app(EnneagramArticleRefresh::class)->publish();
            $this->fail('Second-language failure must reject the whole transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('injected second-language promotion failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertEquals($before, $this->state());
    }

    public function test_replayed_content_drift_fails_closed(): void
    {
        $this->seedBaseline();
        app(EnneagramArticleRefresh::class)->publish();
        DB::table('articles')->where('id', 190)->update(['content_md' => 'later owner edit']);
        $this->assertRejectedWithoutWrites();
    }

    public function test_partial_pair_fails_closed(): void
    {
        $this->seedBaseline();
        DB::table('articles')->where('id', 190)->delete();
        $this->assertRejectedWithoutWrites();
    }

    public function test_missing_cache_configuration_fails_before_data_write(): void
    {
        $this->seedBaseline();
        config(['ops.content_release_observability.hmac_revalidation_secret' => '']);
        $before = $this->state();
        try {
            app(EnneagramArticleRefresh::class)->publish();
            $this->fail('Missing configured consumer must fail closed.');
        } catch (\DomainException $error) {
            $this->assertSame('public_content_53_revalidation_not_configured', $error->getMessage());
        }
        $this->assertEquals($before, $this->state());
    }

    public function test_wrong_cache_receipt_fails_and_replay_closes_cache_without_republishing(): void
    {
        $this->seedBaseline();
        $this->mismatchedCacheReceipt = true;
        try {
            app(EnneagramArticleRefresh::class)->publish();
            $this->fail('Unbound 200 response must not complete publication closeout.');
        } catch (\DomainException $error) {
            $this->assertSame('public_content_53_revalidation_failed', $error->getMessage());
        }
        $beforeRetry = $this->state();
        $this->mismatchedCacheReceipt = false;
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(EnneagramArticleRefresh::class)->publish());
        $this->assertEquals($beforeRetry, $this->state());
    }

    public function test_missing_llms_acknowledgement_fails_and_replay_does_not_republish(): void
    {
        $this->seedBaseline();
        $this->mismatchedDiscoveryReceipt = true;
        try {
            app(EnneagramArticleRefresh::class)->publish();
            $this->fail('Detail receipts cannot substitute for llms-full invalidation.');
        } catch (RuntimeException $error) {
            $this->assertSame('Enneagram Article discovery cache acknowledgement failed.', $error->getMessage());
        }
        $beforeRetry = $this->state();
        $this->mismatchedDiscoveryReceipt = false;
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(EnneagramArticleRefresh::class)->publish());
        $this->assertEquals($beforeRetry, $this->state());
    }

    private function successfulCacheReceipts(): void
    {
        Http::fake(function ($request) {
            $payload = json_decode($request->body(), true);
            $content = $payload['content'];

            if ($this->mismatchedCacheReceipt) {
                return Http::response(['ok' => true, 'revalidated_paths' => [], 'invalidated_tags' => [], 'rejected_paths' => []]);
            }

            $paths = $payload['cache_signal']['paths'];
            if (($content['path_scope'] ?? null) !== 'article_detail_only') {
                $timestamp = $request->header('X-FM-Content-Release-Timestamp')[0];
                $nonce = $request->header('X-FM-Content-Release-Nonce')[0];
                $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->body(), 'fixture-only-revalidation-secret'),
                    $request->header('X-FM-Content-Release-Signature')[0]);
                $this->assertContains('/llms-full.txt', $paths);
                $paths = $this->mismatchedDiscoveryReceipt ? [] : [...$paths, '/zh/articles', '/en/articles',
                    '/zh/articles/'.$content['slug'], '/en/articles/'.$content['slug']];
            }

            return Http::response(['ok' => true, 'revalidated_paths' => $paths,
                'invalidated_tags' => ['article-detail:'.$content['locale'].':'.$content['slug'], 'article-seo:'.$content['locale'].':'.$content['slug']],
                'rejected_paths' => []]);
        });
    }

    private function assertRejectedWithoutWrites(): void
    {
        $before = $this->state();
        try {
            app(EnneagramArticleRefresh::class)->publish();
            $this->fail('Drift must reject publication.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Enneagram Article', $error->getMessage());
        }
        $this->assertEquals($before, $this->state());
    }

    private function state(): array
    {
        $state = [];
        foreach (['articles', 'article_translation_revisions', 'article_seo_meta', 'editorial_reviews',
            'review_attestations', 'review_attestation_target_evidences', 'content_material_decisions'] as $table) {
            $key = ['article_translation_revisions' => 'revisions', 'article_seo_meta' => 'seo'][$table] ?? $table;
            $state[$key] = DB::table($table)->orderBy('id')->get()->keyBy($table === 'article_seo_meta' ? 'article_id' : 'id')->all();
        }

        return $state;
    }

    private function seedBaseline(string $environment = 'production'): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/enneagram_article_refresh_baseline.json')), true)[$environment];
        foreach ($fixture as $row) {
            foreach (['articles' => $row['article'], 'article_translation_revisions' => $row['revision'],
                'article_seo_meta' => $row['seo'], 'editorial_reviews' => $row['editorial_review']] as $table => $data) {
                if ($data === null) {
                    continue;
                }
                $data = array_intersect_key($data, array_flip(Schema::getColumnListing($table)));
                foreach ($data as &$value) {
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
                unset($value);
                DB::table($table)->insert($data);
            }
        }
    }
}
