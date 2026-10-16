<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use App\Services\Cms\EnneagramWorkplaceArticleRefresh;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class EnneagramWorkplaceArticleRefreshTest extends TestCase
{
    use DatabaseMigrations;

    private bool $mismatchedCacheReceipt = false;

    private bool $mismatchedReaderReceipt = false;

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

    public function test_native_pair_refresh_preserves_public_metadata_holds_faq_media_and_history(): void
    {
        $this->seedBaseline();
        $this->assertPublicationPreservesBaseline();
    }

    public function test_exact_older_staging_source_is_bound_without_releasing_discovery_gates(): void
    {
        $this->seedBaseline('staging');
        $this->assertNull(Article::withoutGlobalScopes()->findOrFail(69)->source_article_id);
        $this->assertSame(['status' => 'ready', 'changed' => 0], app(EnneagramWorkplaceArticleRefresh::class)->publish(true));
        $this->assertPublicationPreservesBaseline();
        $english = Article::withoutGlobalScopes()->findOrFail(69);
        $this->assertSame(68, $english->source_article_id);
        $this->assertSame('zh-CN', $english->source_locale);
        $this->assertSame(68, $english->publishedRevision->source_article_id);
    }

    private function assertPublicationPreservesBaseline(): void
    {
        $before = $this->state();
        $package = json_decode(file_get_contents(base_path(EnneagramWorkplaceArticleRefresh::PACKAGE)), true);
        $this->assertSame(['status' => 'published', 'changed' => 2], app(EnneagramWorkplaceArticleRefresh::class)->publish());
        $source = Article::withoutGlobalScopes()->findOrFail(68);
        foreach (['zh-CN' => 68, 'en' => 69] as $locale => $id) {
            $article = Article::withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail($id);
            $old = $before['articles'][(string) $id];
            $this->assertSame($package['candidates'][$locale]['content_md'], $article->content_md);
            $this->assertSame($package['candidates'][$locale]['excerpt'], $article->excerpt);
            $this->assertNotSame($old->published_revision_id, $article->published_revision_id);
            $this->assertSame($article->published_revision_id, $article->working_revision_id);
            $this->assertSame($source->source_version_hash, $article->publishedRevision->source_version_hash);
            $this->assertSame($source->source_version_hash, $article->publishedRevision->translated_from_version_hash);
            $this->assertSame(EnneagramWorkplaceArticleRefresh::PACKAGE_SHA256, $article->publishedRevision->authority_package_sha256);
            foreach (['title', 'slug', 'locale', 'category_id', 'cover_image_url', 'cover_image_alt', 'cover_image_width',
                'cover_image_height', 'cover_image_variants', 'related_test_slug', 'voice', 'voice_order', 'published_at',
                'is_indexable', 'sitemap_eligible', 'llms_eligible'] as $key) {
                $this->assertEquals($old->$key, $article->getRawOriginal($key), $key);
            }
            $oldSeo = (array) $before['seo'][(string) $id];
            $newSeo = $article->seoMeta->getAttributes();
            unset($oldSeo['updated_at'], $newSeo['updated_at']);
            $oldSchema = json_decode($oldSeo['schema_json'], true);
            data_set($oldSchema, 'editorial_package_v1.body_hash', $package['candidates'][$locale]['content_sha256']);
            $oldSeo['schema_json'] = $oldSchema;
            $newSeo['schema_json'] = $article->seoMeta->schema_json;
            $this->assertEquals($oldSeo, $newSeo);
            $this->assertTrue(data_get($article->seoMeta->schema_json, 'editorial_package_v1.hreflang_hold'));
            $this->assertTrue(data_get($article->seoMeta->schema_json, 'editorial_package_v1.schema_hold'));
            app(CmsEditorialReviewAttestationService::class)->assertApprovedEvidence([
                ['surface_id' => 'article', 'record' => $article],
                ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
            ]);
            $latest = DB::table('content_material_decisions')->where('authority_subject_key', 'article:'.$id)->orderByDesc('id')->first();
            $current = DB::transaction(fn () => app(ArticleMaterialDecisionService::class)->recordPublished($article, $article->publishedRevision, now()));
            $this->assertSame($latest->id, $current->id, 'Final material fingerprint must already match the final metadata.');
            $oldRevision = (array) DB::table('article_translation_revisions')->find($old->published_revision_id);
            $frozen = (array) $before['revisions'][(string) $old->published_revision_id];
            unset($oldRevision['revision_status'], $oldRevision['updated_at'], $frozen['revision_status'], $frozen['updated_at']);
            $this->assertSame($frozen, $oldRevision);
            $this->getJson('/api/v0.5/articles/enneagram-workplace-friction-core-motivations?locale='.$locale)
                ->assertOk()->assertJsonPath('article.content_md', $package['candidates'][$locale]['content_md'])
                ->assertJsonPath('article.excerpt', $package['candidates'][$locale]['excerpt']);
            $seo = $this->getJson('/api/v0.5/articles/enneagram-workplace-friction-core-motivations/seo?locale='.$locale)
                ->assertOk()->assertJsonPath('meta.robots', 'index,follow');
            $this->assertSame('https://fermatmind.com/en/articles/enneagram-workplace-friction-core-motivations', $seo->json('meta.alternates.en'));
            $this->assertSame('https://fermatmind.com/zh/articles/enneagram-workplace-friction-core-motivations', $seo->json('meta.alternates.zh-CN'));
        }
        $this->assertSame($source->source_version_hash, Article::withoutGlobalScopes()->findOrFail(69)->translated_from_version_hash);
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(EnneagramWorkplaceArticleRefresh::class)->publish());
        $this->assertSame(4, DB::table('article_translation_revisions')->count());
        Http::assertSentCount(6);
    }

    public function test_dry_run_is_write_free(): void
    {
        $this->seedBaseline();
        $before = $this->state();
        $this->assertSame(['status' => 'ready', 'changed' => 0], app(EnneagramWorkplaceArticleRefresh::class)->publish(true));
        $this->assertEquals($before, $this->state());
        Http::assertNothingSent();
    }

    public function test_restored_baseline_can_publish_new_revisions_without_overwriting_prior_history(): void
    {
        $this->seedBaseline();
        $before = $this->state();
        app(EnneagramWorkplaceArticleRefresh::class)->publish();
        $history = DB::table('article_translation_revisions')->whereNotIn('id', [754, 832])->orderBy('id')->get()->all();
        // Restore the exact source snapshots and public pointers, retaining all candidate history.
        foreach (['articles', 'revisions', 'seo', 'editorial_reviews'] as $key) {
            $table = ['revisions' => 'article_translation_revisions', 'seo' => 'article_seo_meta'][$key] ?? $key;
            foreach ($before[$key] as $row) {
                $values = (array) $row;
                DB::table($table)->where('id', $row->id)->update($values);
            }
        }
        $this->assertSame(['status' => 'ready', 'changed' => 0], app(EnneagramWorkplaceArticleRefresh::class)->publish(true));
        $this->assertSame(['status' => 'published', 'changed' => 2], app(EnneagramWorkplaceArticleRefresh::class)->publish());
        foreach ($history as $row) {
            $this->assertEquals($row, DB::table('article_translation_revisions')->find($row->id));
        }
        $this->assertSame(6, DB::table('article_translation_revisions')->count());
        $newOwnerDraft = Article::withoutGlobalScopes()->findOrFail(69)->publishedRevision->replicate()
            ->forceFill(['revision_number' => 100, 'revision_status' => 'human_review']);
        $newOwnerDraft->saveQuietly();
        DB::table('articles')->where('id', 69)->update(['working_revision_id' => $newOwnerDraft->id]);
        $this->assertRejectedWithoutWrites();
    }

    public function test_native_publication_notifies_each_final_article_once(): void
    {
        $this->seedBaseline();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => false, 'queue.default' => 'sync']);
        Queue::fake([SyncPublicAuthorityUrlTruth::class]);
        app(EnneagramWorkplaceArticleRefresh::class)->publish();
        Queue::assertPushed(SyncPublicAuthorityUrlTruth::class, 2);
        foreach (['zh-CN' => 68, 'en' => 69] as $locale => $id) {
            Queue::assertPushed(SyncPublicAuthorityUrlTruth::class, function ($job) use ($locale, $id): bool {
                $article = Article::withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail($id);

                return $job->pageEntityType === 'article' && $job->entityIdentity === (string) $id
                    && $job->locale === $locale && $job->scopedWrite
                    && data_get($article->seoMeta->schema_json, 'editorial_package_v1.hreflang_hold') === true
                    && $article->publishedRevision->authority_package_sha256 === EnneagramWorkplaceArticleRefresh::PACKAGE_SHA256;
            });
        }
    }

    public function test_foreign_working_draft_is_not_overwritten(): void
    {
        $this->seedBaseline();
        $draft = ArticleTranslationRevision::withoutGlobalScopes()->findOrFail(832)->replicate()
            ->forceFill(['revision_number' => 99, 'revision_status' => 'human_review',
                'authority_asset_key' => null, 'authority_package_sha256' => null]);
        $draft->saveQuietly();
        DB::table('articles')->where('id', 69)->update(['working_revision_id' => $draft->id]);
        $this->assertRejectedWithoutWrites();
    }

    public function test_body_drift_is_rejected(): void
    {
        $this->seedBaseline();
        DB::table('articles')->where('id', 69)->update(['content_md' => 'Concurrent editorial update']);
        $this->assertRejectedWithoutWrites();
    }

    public function test_metadata_drift_is_rejected_even_when_body_matches(): void
    {
        $this->seedBaseline();
        DB::table('article_seo_meta')->where('article_id', 69)->update(['seo_description' => 'Concurrent metadata update']);
        $this->assertRejectedWithoutWrites();
    }

    public function test_manual_hold_is_preserved(): void
    {
        $this->seedBaseline();
        DB::table('articles')->where('id', 69)->update(['lifecycle_note' => 'manual review hold']);
        $this->assertRejectedWithoutWrites();
    }

    public function test_editorial_hold_is_preserved(): void
    {
        $this->seedBaseline();
        DB::table('editorial_reviews')->where('content_id', 69)->update(['workflow_state' => 'changes_requested']);
        $this->assertRejectedWithoutWrites();
    }

    public function test_second_language_failure_rolls_back_pair_reviews_and_material_decisions(): void
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
            app(EnneagramWorkplaceArticleRefresh::class)->publish();
            $this->fail('Second-language failure must reject the transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('injected second-language promotion failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertEquals($before, $this->state());
        Http::assertNothingSent();
    }

    public function test_later_owner_body_change_rejects_replay(): void
    {
        $this->seedBaseline();
        app(EnneagramWorkplaceArticleRefresh::class)->publish();
        DB::table('articles')->where('id', 69)->update(['content_md' => 'Later owner edit']);
        $this->assertRejectedWithoutWrites();
    }

    public function test_partial_pair_fails_closed(): void
    {
        $this->seedBaseline();
        DB::table('articles')->where('id', 69)->delete();
        $this->assertRejectedWithoutWrites();
    }

    public function test_missing_cache_configuration_rejects_before_writing(): void
    {
        $this->seedBaseline();
        config(['ops.content_release_observability.hmac_revalidation_secret' => '']);
        $before = $this->state();
        try {
            app(EnneagramWorkplaceArticleRefresh::class)->publish();
            $this->fail('Missing configured consumer must fail closed.');
        } catch (\DomainException $error) {
            $this->assertSame('public_content_53_revalidation_not_configured', $error->getMessage());
        }
        $this->assertEquals($before, $this->state());
    }

    public function test_cache_only_replay_closes_post_commit_failure_without_republishing(): void
    {
        $this->seedBaseline();
        $this->mismatchedCacheReceipt = true;
        try {
            app(EnneagramWorkplaceArticleRefresh::class)->publish();
            $this->fail('Unbound receipt must fail.');
        } catch (\DomainException $error) {
            $this->assertSame('public_content_53_revalidation_failed', $error->getMessage());
        }
        $before = $this->state();
        $this->mismatchedCacheReceipt = false;
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(EnneagramWorkplaceArticleRefresh::class)->publish());
        $this->assertEquals($before, $this->state());
    }

    public function test_shared_reader_receipt_is_required(): void
    {
        $this->seedBaseline();
        $this->mismatchedReaderReceipt = true;
        try {
            app(EnneagramWorkplaceArticleRefresh::class)->publish();
            $this->fail('Detail receipts cannot substitute for shared reader invalidation.');
        } catch (RuntimeException $error) {
            $this->assertSame('Workplace Article reader cache acknowledgement failed.', $error->getMessage());
        }
        $before = $this->state();
        $this->mismatchedReaderReceipt = false;
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(EnneagramWorkplaceArticleRefresh::class)->publish());
        $this->assertEquals($before, $this->state());
    }

    public function test_replay_repairs_both_authorities_after_first_real_after_commit_cache_callback_fails(): void
    {
        $this->seedBaseline();
        $this->prepareUrlTruth();
        $cache = Cache::getFacadeRoot();
        $proxy = \Mockery::mock($cache);
        $proxy->shouldReceive('forget')->withAnyArgs()->andReturnUsing(fn ($key) => $cache->forget($key))->byDefault();
        $proxy->shouldReceive('forget')->with(SitemapSourceController::CACHE_KEY_FRESH)->once()
            ->andThrow(new RuntimeException('injected first after-commit cache failure'));
        Cache::swap($proxy);
        try {
            app(EnneagramWorkplaceArticleRefresh::class)->publish();
            $this->fail('First after-commit callback must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('injected first after-commit cache failure', $error->getMessage());
        } finally {
            Cache::swap($cache);
        }
        $this->assertFalse(DB::connection()->getPdo()->inTransaction(), 'The failure must occur after a real PDO commit.');
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_url_entities')->count());
        $committed = $this->state();
        $this->assertCount(4, $committed['revisions']);
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(EnneagramWorkplaceArticleRefresh::class)->publish());
        $this->assertEquals($committed, $this->state());
        $this->assertCurrentUrlTruthPair();
    }

    public function test_replay_repairs_both_authorities_after_first_queue_admission_fails_without_releasing_unique_lock(): void
    {
        $this->seedBaseline();
        $this->prepareUrlTruth();
        $bus = Bus::getFacadeRoot();
        $failedJob = null;
        $proxy = \Mockery::mock($bus);
        $proxy->shouldReceive('dispatch')->once()->withArgs(function ($job) use (&$failedJob): bool {
            $failedJob = $job;

            return $job instanceof SyncPublicAuthorityUrlTruth;
        })->andThrow(new RuntimeException('injected first queue admission failure'));
        Bus::swap($proxy);
        try {
            app(EnneagramWorkplaceArticleRefresh::class)->publish();
            $this->fail('First queue admission must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('injected first queue admission failure', $error->getMessage());
        } finally {
            Bus::swap($bus);
        }
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertInstanceOf(SyncPublicAuthorityUrlTruth::class, $failedJob);
        $key = \Illuminate\Bus\UniqueLock::getKey($failedJob);
        $this->assertFalse(Cache::lock($key, 86400)->get(), 'Failed queue admission may leave its unique lock held.');
        $committed = $this->state();
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(EnneagramWorkplaceArticleRefresh::class)->publish());
        $this->assertEquals($committed, $this->state());
        $this->assertCurrentUrlTruthPair();
        $this->assertFalse(Cache::lock($key, 86400)->get(), 'Recovery must not release an existing queue lock.');
    }

    private function assertCurrentUrlTruthPair(): void
    {
        $connection = DB::connection('seo_intel');
        $this->assertSame(2, $connection->table('seo_urls')->count());
        $this->assertSame(2, $connection->table('seo_url_entities')->where('binding_status', 'current')->count());
        foreach (['zh-CN' => 68, 'en' => 69] as $locale => $id) {
            $url = $connection->table('seo_urls')->where('locale', $locale)->where('entity_id_or_slug', (string) $id)->first();
            $binding = $connection->table('seo_url_entities')->where('locale', $locale)->where('entity_id_or_slug', (string) $id)->first();
            $this->assertNotNull($url);
            $this->assertSame($url->authority_revision, $binding->authority_revision);
        }
        $truth = $connection->table('seo_urls')->orderBy('id')->get();
        app(EnneagramWorkplaceArticleRefresh::class)->publish();
        $this->assertEquals($truth, $connection->table('seo_urls')->orderBy('id')->get(), 'Already current authority readback must not rewrite Truth.');
        $this->assertFalse(config('seo_intel.write_enabled'), 'Scoped writer must restore its original capability.');
    }

    private function prepareUrlTruth(): void
    {
        // Keep the real CMS authority reader, evaluator, writer and scoped job;
        // unrelated Career authority is outside this Article fixture.
        app()->bind(\App\Services\SeoIntel\Sources\UrlTruthInventorySource::class,
            \App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource::class);
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => false,
            'seo_intel.connection' => 'seo_intel', 'seo_council.connection' => 'seo_intel',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
            'database.connections.seo_intel' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('seo_intel');
        Schema::connection('seo_intel')->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('cluster', 64)->nullable();
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->timestamp('lastmod_at')->nullable();
            $table->string('lastmod_source', 64)->nullable();
            $table->boolean('is_private_flow')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['canonical_url_hash', 'locale']);
        });
        Schema::connection('seo_intel')->create('seo_url_entities', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255);
            $table->string('entity_source', 64);
            $table->string('authority_status', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();
        });
        $migration = require base_path('database/migrations/seo_intel/2026_08_25_020000_expand_url_truth_current_bindings.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        parent::tearDown();
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
                $paths = $this->mismatchedReaderReceipt ? [] : [...$paths, '/zh/articles', '/en/articles',
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
            app(EnneagramWorkplaceArticleRefresh::class)->publish();
            $this->fail('Drift must reject publication.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Workplace Article', $error->getMessage());
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

    public function test_context_links_pair_preserves_protected_fields_in_production(): void
    {
        $this->assertContextPublication('production');
    }

    public function test_context_links_pair_preserves_protected_fields_in_staging(): void
    {
        $this->assertContextPublication('staging');
    }

    private function assertContextPublication(string $environment): void
    {
        $this->seedCurrentBaseline($environment);
        $before = $this->state();
        $service = app(EnneagramWorkplaceArticleRefresh::class);
        $this->assertSame(['status' => 'ready', 'changed' => 0], $service->publishContextLinks(true));
        $this->assertEquals($before, $this->state());
        $this->assertSame(['status' => 'published', 'changed' => 2], $service->publishContextLinks());
        $package = json_decode(file_get_contents(base_path(EnneagramWorkplaceArticleRefresh::CONTEXT_PACKAGE)), true);
        foreach (['zh-CN' => 68, 'en' => 69] as $locale => $id) {
            $article = Article::withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail($id);
            $candidate = $package['candidates'][$locale];
            $this->assertSame($candidate['content_md'], $article->content_md);
            $this->assertSame($candidate['seo_description'], $article->publishedRevision->seo_description);
            $this->assertSame($candidate['seo_description'], $article->seoMeta->seo_description);
            $this->assertSame($candidate['seo_description'], $article->seoMeta->og_description);
            $old = (array) $before['articles'][(string) $id];
            $new = $article->getAttributes();
            foreach (['content_md', 'content_html', 'source_version_hash', 'translated_from_version_hash',
                'published_revision_id', 'working_revision_id', 'updated_at', ...($locale === 'en' ? ['cover_image_alt'] : [])] as $field) {
                unset($old[$field], $new[$field]);
            }
            $this->assertSame($old, $new, 'Every non-target Article field must be preserved.');
            $this->assertSame($candidate['cover_image_alt'] ?? $old['cover_image_alt'], $article->cover_image_alt);
            $this->assertSame($article->computeSourceVersionHash(), $article->source_version_hash);
            $this->assertSame(Article::withoutGlobalScopes()->findOrFail(68)->source_version_hash, $article->publishedRevision->source_version_hash);
            $this->assertSame(EnneagramWorkplaceArticleRefresh::CONTEXT_PACKAGE_SHA256, $article->publishedRevision->authority_package_sha256);
            $this->getJson('/api/v0.5/articles/enneagram-workplace-friction-core-motivations?locale='.$locale)
                ->assertOk()->assertJsonPath('article.content_md', $candidate['content_md'])
                ->assertJsonPath('article.cover_image_alt', $article->cover_image_alt)
                ->assertJsonPath('article.seo_meta.seo_description', $candidate['seo_description'])
                ->assertJsonPath('article.seo_meta.og_description', $candidate['seo_description']);
            $this->getJson('/api/v0.5/articles/enneagram-workplace-friction-core-motivations/seo?locale='.$locale)
                ->assertOk()->assertJsonPath('meta.description', $candidate['seo_description'])
                ->assertJsonPath('meta.og.description', $candidate['seo_description'])
                ->assertJsonPath('meta.twitter.description', $candidate['seo_description']);
            $this->reviewsForContext($article);
            $latest = DB::table('content_material_decisions')->where('authority_subject_key', 'article:'.$id)->orderByDesc('id')->first();
            $this->assertSame($latest->id, DB::transaction(fn () => app(ArticleMaterialDecisionService::class)
                ->recordPublished($article, $article->publishedRevision, now()))->id);
        }
        $after = $this->state();
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], $service->publishContextLinks());
        $this->assertEquals($after, $this->state());
        foreach ($before['revisions'] as $id => $revision) {
            $old = (array) $revision;
            $new = (array) DB::table('article_translation_revisions')->find($id);
            unset($old['revision_status'], $old['updated_at'], $new['revision_status'], $new['updated_at']);
            $this->assertSame($old, $new, 'Prior revision bytes remain immutable.');
        }
    }

    public function test_context_pair_rejects_metadata_note_and_working_revision_drift_without_writes(): void
    {
        $this->seedCurrentBaseline();
        $service = app(EnneagramWorkplaceArticleRefresh::class);
        foreach ([['article_seo_meta', 'article_id', 69, 'seo_description', 'Concurrent SEO edit'],
            ['articles', 'id', 69, 'cover_image_alt', 'Concurrent alt edit'],
            ['editorial_reviews', 'content_id', 69, 'note', 'Owner hold'],
            ['articles', 'id', 69, 'working_revision_id', 832]] as [$table, $key, $id, $field, $value]) {
            $query = DB::table($table)->where($key, $id);
            $old = $query->value($field);
            $query->update([$field => $value]);
            $before = $this->state();
            try {
                $service->publishContextLinks();
                $this->fail('Concurrent authority changes must reject the whole pair.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Workplace Article', $error->getMessage());
            }
            $this->assertEquals($before, $this->state());
            $query->update([$field => $old]);
        }
    }

    public function test_context_second_language_failure_rolls_back_description_alt_and_reviews(): void
    {
        $this->seedCurrentBaseline();
        $before = $this->state();
        $event = 'eloquent.updating: '.Article::class;
        Event::listen($event, function (Article $article): void {
            if ($article->locale === 'en' && $article->isDirty('content_md')) {
                throw new RuntimeException('injected context second-language failure');
            }
        });
        try {
            app(EnneagramWorkplaceArticleRefresh::class)->publishContextLinks();
            $this->fail('Second language failure must reject all writes.');
        } catch (RuntimeException $error) {
            $this->assertSame('injected context second-language failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertEquals($before, $this->state());
    }

    public function test_context_cache_failure_after_real_commit_is_closed_without_republication(): void
    {
        $this->seedCurrentBaseline();
        $migration = require base_path('database/migrations/2026_10_16_140000_publish_enneagram_workplace_context_links.php');
        $this->assertFalse($migration->withinTransaction);
        $this->mismatchedCacheReceipt = true;
        try {
            $migration->up();
            $this->fail('Invalid cache acknowledgement must fail.');
        } catch (\DomainException $error) {
            $this->assertSame('public_content_53_revalidation_failed', $error->getMessage());
        }
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $committed = $this->state();
        $this->mismatchedCacheReceipt = false;
        $migration->up();
        $this->assertEquals($committed, $this->state());
        DB::table('articles')->where('id', 69)->update(['cover_image_alt' => 'Later owner alt']);
        $before = $this->state();
        try {
            $migration->up();
            $this->fail('A later owner edit must reject package replay.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Workplace Article', $error->getMessage());
        }
        $this->assertEquals($before, $this->state());
    }

    private function reviewsForContext(Article $article): void
    {
        app(CmsEditorialReviewAttestationService::class)->assertApprovedEvidence([
            ['surface_id' => 'article', 'record' => $article],
            ['surface_id' => 'article_translation_revision', 'record' => $article->publishedRevision],
        ]);
    }

    private function seedCurrentBaseline(string $environment = 'production'): void
    {
        $this->seedBaseline($environment);
        app(EnneagramWorkplaceArticleRefresh::class)->publish();
        $package = json_decode(file_get_contents(base_path(EnneagramWorkplaceArticleRefresh::CONTEXT_PACKAGE)), true);
        // The previous native publication creates environment-local revision IDs.
        // Bind this synthetic fixture to the captured current staging/production IDs.
        Schema::withoutForeignKeyConstraints(function () use ($environment, $package): void {
            foreach (['zh-CN' => 68, 'en' => 69] as $locale => $id) {
                $old = DB::table('articles')->where('id', $id)->value('published_revision_id');
                $new = $package['baselines'][$environment][$locale]['revision']['id'];
                DB::table('article_translation_revisions')->where('id', $old)->update(['id' => $new]);
                DB::table('articles')->where('id', $id)->update(['published_revision_id' => $new, 'working_revision_id' => $new]);
            }
        });
    }

    private function seedBaseline(string $environment = 'production'): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/enneagram_workplace_article_refresh_baseline.json')), true)[$environment];
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
