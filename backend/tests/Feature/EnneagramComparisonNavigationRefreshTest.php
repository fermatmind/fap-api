<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\EditorialReview;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use App\Services\Cms\EnneagramComparisonNavigationRefresh as Refresh;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EnneagramComparisonNavigationRefreshTest extends TestCase
{
    use DatabaseMigrations;

    private bool $badReceipt = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => 1,
            'ops.content_release_observability.hmac_revalidation_url' => 'https://frontend.example.test/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'fixture-only-secret']);
        AdminUser::query()->create(['name' => 'Fixture owner', 'email' => 'owner@example.test',
            'password' => 'fixture-only-password', 'is_active' => 1]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $content = $body['content'];
            $loc = $content['locale'];
            $slug = $content['slug'];
            $paths = $body['cache_signal']['paths'];
            if (($content['path_scope'] ?? null) !== 'article_detail_only') {
                $paths = [...$paths, '/zh/articles', '/en/articles', '/zh/articles/'.$slug, '/en/articles/'.$slug];
            }

            return Http::response(['ok' => true, 'revalidated_paths' => $this->badReceipt ? [] : $paths,
                'rejected_paths' => [], 'invalidated_tags' => ['article-detail:'.$loc.':'.$slug, 'article-seo:'.$loc.':'.$slug]]);
        });
    }

    public function test_native_pair_navigation_and_reading_time_preserve_every_other_field_and_replay(): void
    {
        $this->seedPair();
        $service = app(Refresh::class);
        $before = $this->state();
        $publicBefore = ['zh-CN' => $this->publicSeo('zh-CN'), 'en' => $this->publicSeo('en')];
        $this->assertSame(['status' => 'ready', 'changed' => 0], $service->publish(true));
        $this->assertEquals($before, $this->state());
        Http::assertNothingSent();
        $this->assertSame(['status' => 'published', 'changed' => 2], $service->publish());
        $package = json_decode(file_get_contents(base_path(Refresh::PACKAGE)), true);
        foreach (['zh-CN' => 164, 'en' => 224] as $locale => $id) {
            $article = Article::withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail($id);
            $this->assertSame($package['candidates']['production'][$locale]['content_md'], $article->content_md);
            $this->assertSame($locale === 'zh-CN' ? 11 : null, $article->reading_minutes);
            $this->assertSame(Refresh::PACKAGE_SHA256, $article->publishedRevision->authority_package_sha256);
            $old = (array) $before['articles'][$id];
            $new = $article->getAttributes();
            foreach (['content_md', 'content_html', 'reading_minutes', 'source_version_hash', 'translated_from_version_hash',
                'working_revision_id', 'published_revision_id', 'updated_at'] as $field) {
                unset($old[$field], $new[$field]);
            }
            $this->assertSame($old, $new);
            $seo = $article->seoMeta->getAttributes();
            $oldSeo = (array) $before['article_seo_meta'][$article->seoMeta->id];
            unset($seo['updated_at'], $oldSeo['updated_at']);
            $this->assertSame($oldSeo, $seo);
            $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale='.$locale)->assertOk()
                ->assertJsonPath('article.content_md', $package['candidates']['production'][$locale]['content_md'])
                ->assertJsonPath('article.reading_minutes', $locale === 'zh-CN' ? 11 : null)
                ->assertJsonPath('article.is_indexable', $locale === 'zh-CN');
            $this->getJson('/api/v0.5/articles/'.$article->slug.'/seo?locale='.$locale)->assertOk()
                ->assertJsonPath('meta.robots', $locale === 'zh-CN' ? 'index,follow' : 'noindex,nofollow');
        }
        $this->assertSame($publicBefore, ['zh-CN' => $this->publicSeo('zh-CN'), 'en' => $this->publicSeo('en')]);
        foreach ($before['editorial_reviews'] as $id => $review) {
            $old = (array) $review;
            $current = (array) DB::table('editorial_reviews')->find($id);
            unset($old['reviewed_at'], $old['last_transition_at'], $old['updated_at'],
                $current['reviewed_at'], $current['last_transition_at'], $current['updated_at']);
            $this->assertSame($old, $current, 'Existing approved review identity, state, assignments and note remain intact.');
        }
        $after = $this->state();
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], $service->publish());
        $this->assertEquals($after, $this->state());
        $migration = require database_path('migrations/2026_10_16_190000_preserve_enneagram_comparison_public_seo.php');
        $migration->up();
        $this->assertEquals($after, $this->state());
        $this->assertSame(count($before['article_translation_revisions']) + 2, DB::table('article_translation_revisions')->count());
        foreach ($before['article_translation_revisions'] as $id => $row) {
            $old = (array) $row;
            $current = (array) DB::table('article_translation_revisions')->find($id);
            unset($old['updated_at'], $old['revision_status'], $current['updated_at'], $current['revision_status']);
            $this->assertSame($old, $current);
        }
        Http::assertSentCount(9);
    }

    public function test_production_requires_both_languages_and_rejects_source_drift(): void
    {
        $this->seedPair();
        $this->app['env'] = 'production';
        try {
            DB::table('articles')->where('id', 224)->update(['is_public' => false]);
            $this->assertRejected();
            DB::table('articles')->where('id', 224)->update(['is_public' => true]);
            DB::table('articles')->where('id', 224)->update(['source_article_id' => 999, 'translated_from_article_id' => 999]);
            $this->assertRejected();
            Schema::withoutForeignKeyConstraints(fn () => DB::table('articles')->where('id', 224)->delete());
            $this->assertRejected();
            $this->assertFalse(DB::table('articles')->where('id', 224)->exists());
        } finally {
            $this->app['env'] = 'testing';
        }
        Http::assertNothingSent();
    }

    public function test_second_language_approval_exception_rolls_back_chinese_and_after_commit_callbacks(): void
    {
        $this->seedPair();
        // Fail the actual database approval write in the second language,
        // after the first language's native promotion has completed.
        DB::statement("CREATE TRIGGER comparison_second_language_reject BEFORE UPDATE ON article_translation_revisions
            WHEN NEW.article_id = 224 AND NEW.revision_status = 'approved'
            BEGIN SELECT RAISE(ABORT, 'Fixture second language rejection'); END");
        $this->assertRejected();
        Http::assertNothingSent();
    }

    public function test_staging_unavailable_pair_fails_without_any_writes_or_publishing_english(): void
    {
        $this->seedPair();
        $this->app['env'] = 'staging';
        foreach ([['is_public', false], ['working_revision_id', 735], ['published_revision_id', null], ['lifecycle_note', 'Owner hold']] as [$field, $value]) {
            $old = DB::table('articles')->where('id', 224)->value($field);
            DB::table('articles')->where('id', 224)->update([$field => $value]);
            $this->assertRejected();
            DB::table('articles')->where('id', 224)->update([$field => $old]);
        }
        Http::assertNothingSent();
    }

    public function test_actual_staging_source_initializes_only_the_missing_english_identity_and_replays(): void
    {
        $this->seedStaging();
        $before = $this->state();
        $publicBefore = $this->publicSeo('zh-CN');
        $this->app['env'] = 'staging';
        $this->assertSame(['status' => 'ready', 'changed' => 0], app(Refresh::class)->publish(true));
        $this->assertEquals($before, $this->state());
        $this->assertSame(['status' => 'published', 'changed' => 2], app(Refresh::class)->publish());
        $package = json_decode(file_get_contents(base_path(Refresh::PACKAGE)), true);
        $source = Article::withoutGlobalScopes()->with('publishedRevision')->findOrFail(164);
        $english = Article::withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail(224);
        $this->assertSame($publicBefore, $this->publicSeo('zh-CN'));
        $this->assertSame('source', $source->translation_status);
        $this->assertSame($package['candidates']['staging']['zh-CN']['content_md'], $source->content_md);
        $this->assertSame($package['candidates']['staging']['en']['content_md'], $english->content_md);
        $this->assertSame($source->source_version_hash, $english->publishedRevision->source_version_hash);
        $this->assertSame($source->source_version_hash, $english->publishedRevision->translated_from_version_hash);
        $this->assertSame($source->translation_group_id, $english->translation_group_id);
        $this->assertSame(164, $english->source_article_id);
        $this->assertTrue($english->is_public);
        $this->assertFalse($english->is_indexable);
        $this->assertFalse($english->sitemap_eligible);
        $this->assertFalse($english->llms_eligible);
        $this->assertSame('noindex,nofollow', $english->seoMeta->robots);
        $this->assertSame(2, DB::table('articles')->count());
        $this->assertSame(2, DB::table('editorial_reviews')->where('workflow_state', 'approved')->count());
        $old = (array) $before['article_translation_revisions'][426];
        $history = (array) DB::table('article_translation_revisions')->find(426);
        unset($old['revision_status'], $old['updated_at'], $history['revision_status'], $history['updated_at']);
        $this->assertSame($old, $history, 'Prior source revision text and provenance remain immutable.');
        $after = $this->state();
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(Refresh::class)->publish());
        $this->assertEquals($after, $this->state());
    }

    public function test_staging_initial_publication_has_no_cache_or_audit_side_effects_before_pair_commit(): void
    {
        $this->seedStaging();
        $this->app['env'] = 'staging';
        Cache::put(\App\Support\PublicProjectionCache::physicalKey(\App\Http\Controllers\API\V0_5\SEO\SitemapSourceController::CACHE_KEY_FRESH), 'fixture', 600);
        DB::statement("CREATE TRIGGER comparison_review_reject BEFORE INSERT ON editorial_reviews
            WHEN NEW.content_id = 224 BEGIN SELECT RAISE(ABORT, 'Fixture after first publish rejection'); END");
        $this->assertRejected();
        $this->assertSame('fixture', Cache::get(\App\Support\PublicProjectionCache::physicalKey(\App\Http\Controllers\API\V0_5\SEO\SitemapSourceController::CACHE_KEY_FRESH)));
        Http::assertNothingSent();
    }

    public function test_initial_publish_audit_failure_rolls_back_the_pair_instead_of_leaving_an_unrecoverable_audit_gap(): void
    {
        $this->seedStaging();
        $this->app['env'] = 'staging';
        DB::statement("CREATE TRIGGER comparison_publish_audit_reject BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'content_release_publish' AND NEW.target_id = '224'
            BEGIN SELECT RAISE(ABORT, 'Fixture first publish audit rejection'); END");
        $this->assertRejected();
        $this->assertFalse(DB::table('articles')->where('id', 224)->exists());
        Http::assertNothingSent();
    }

    public function test_staging_initialization_rejects_concurrent_source_and_global_id_collisions(): void
    {
        $this->seedStaging();
        $this->app['env'] = 'staging';
        DB::table('articles')->where('id', 164)->update(['excerpt' => 'Concurrent source change']);
        $this->assertRejected();
        $original = json_decode(file_get_contents(base_path('tests/Fixtures/enneagram_comparison_staging_baseline.json')), true);
        DB::table('articles')->where('id', 164)->update(['excerpt' => $original['articles'][0]['excerpt']]);
        Schema::withoutForeignKeyConstraints(fn () => DB::table('articles')->insert([
            'id' => 224, 'org_id' => 999, 'locale' => 'en', 'slug' => 'other-owner-article', 'title' => 'Other owner',
            'content_md' => 'Other owner draft', 'status' => 'draft', 'is_public' => false,
        ]));
        $this->assertRejected();
        Http::assertNothingSent();
    }

    public function test_body_eligibility_and_owner_draft_drift_reject_without_any_writes(): void
    {
        $this->seedPair();
        foreach ([['content_md', 'Concurrent content'], ['is_indexable', true], ['working_revision_id', 735],
            ['translation_group_id', 'other-group']] as [$field, $value]) {
            $old = DB::table('articles')->where('id', 224)->value($field);
            DB::table('articles')->where('id', 224)->update([$field => $value]);
            $this->assertRejected();
            DB::table('articles')->where('id', 224)->update([$field => $old]);
        }
        Http::assertNothingSent();
    }

    public function test_post_commit_cache_failure_can_close_without_republishing(): void
    {
        $this->seedPair();
        $this->badReceipt = true;
        try {
            app(Refresh::class)->publish();
            $this->fail('Expected cache receipt failure.');
        } catch (\DomainException $error) {
            $this->assertSame('public_content_53_revalidation_failed', $error->getMessage());
        }
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $after = $this->state();
        $this->badReceipt = false;
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(Refresh::class)->publish());
        $this->assertEquals($after, $this->state());
    }

    private function assertRejected(): void
    {
        $before = $this->state();
        try {
            app(Refresh::class)->publish();
            $this->fail('Expected exact scope rejection.');
        } catch (\Exception $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $this->assertEquals($before, $this->state());
    }

    public function test_known_staging_public_seo_regression_repairs_atomically_without_rewriting_history(): void
    {
        $this->seedStaging();
        $expected = $this->publicSeo('zh-CN');
        DB::table('sqlite_sequence')->where('name', 'article_translation_revisions')->update(['seq' => 714]);
        $this->app['env'] = 'staging';
        app(Refresh::class)->publish();
        $source = Article::withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail(164);
        $oldId = (int) $source->published_revision_id;
        // Reproduce the delivered v1 projection: the attested new revision used
        // canonical DB SEO while the historical public revision had longer copy.
        $this->attestStagingDescription((string) $source->seoMeta->seo_description);
        $history = (array) DB::table('article_translation_revisions')->find($oldId);
        $count = DB::table('article_translation_revisions')->count();
        DB::statement("CREATE TRIGGER comparison_repair_approval_reject BEFORE UPDATE ON article_translation_revisions
            WHEN NEW.article_id = 224 AND NEW.revision_status = 'approved'
            BEGIN SELECT RAISE(ABORT, 'Fixture repair rejection'); END");
        $this->assertRejected();
        Http::assertSentCount(3);
        DB::statement('DROP TRIGGER comparison_repair_approval_reject');
        $migration = require database_path('migrations/2026_10_16_190000_preserve_enneagram_comparison_public_seo.php');
        $migration->up();
        $this->assertSame($expected, $this->publicSeo('zh-CN'));
        $this->assertSame($count + 2, DB::table('article_translation_revisions')->count());
        $oldAfter = (array) DB::table('article_translation_revisions')->find($oldId);
        unset($history['updated_at'], $history['revision_status'], $oldAfter['updated_at'], $oldAfter['revision_status']);
        $this->assertSame($history, $oldAfter);
        $after = $this->state();
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], app(Refresh::class)->publish());
        $this->assertEquals($after, $this->state());
    }

    public function test_staging_public_seo_third_state_is_not_repaired_or_overwritten(): void
    {
        $this->seedStaging();
        $this->app['env'] = 'staging';
        app(Refresh::class)->publish();
        $this->attestStagingDescription('A separately approved, unrelated description.');
        $this->assertRejected();
        Http::assertSentCount(3);
    }

    public function test_native_public_null_description_is_preserved_instead_of_using_canonical_db_copy(): void
    {
        $this->seedPair();
        DB::table('article_translation_revisions')->where('id', 735)->update(['seo_description' => null]);
        $before = $this->publicSeo('zh-CN');
        app(Refresh::class)->publish();
        $this->assertNull(Article::withoutGlobalScopes()->with('publishedRevision')->findOrFail(164)->publishedRevision->seo_description);
        $this->assertSame($before, $this->publicSeo('zh-CN'));
    }

    public function test_correct_replay_rejects_current_editorial_hold_without_writes_or_cache_requests(): void
    {
        $this->seedPair();
        app(Refresh::class)->publish();
        DB::table('editorial_reviews')->where('content_type', 'article')->where('content_id', 164)
            ->update(['workflow_state' => 'changes_requested']);
        $this->assertRejected();
        Http::assertSentCount(3);
    }

    private function attestStagingDescription(string $description): void
    {
        $source = Article::withoutGlobalScopes()->with('publishedRevision')->findOrFail(164);
        $source->publishedRevision->forceFill(['seo_description' => $description])->saveQuietly();
        $source->refresh()->load('publishedRevision');
        $review = EditorialReview::withoutGlobalScopes()->where('content_type', 'article')->where('content_id', 164)->firstOrFail();
        app(CmsEditorialReviewAttestationService::class)->bindOrCreateApproved(null, 'cms_article_review',
            'fixture:staging-public-seo:'.$source->published_revision_id,
            [['surface_id' => 'article', 'record' => $source],
                ['surface_id' => 'article_translation_revision', 'record' => $source->publishedRevision],
                ['surface_id' => 'editorial_review', 'record' => $review]], 1, Refresh::PACKAGE_SHA256);
    }

    private function publicSeo(string $locale): array
    {
        $path = '/api/v0.5/articles/big-five-vs-enneagram-traits-and-motivation-stories';
        $detail = $this->getJson($path.'?locale='.$locale)->assertOk()->json();
        $seo = $this->getJson($path.'/seo?locale='.$locale)->assertOk()->json();
        // Native revision publication/review clocks advance; editorial SEO does not.
        unset($seo['meta']['big_five_structured_data_v1']['visible_alignment']['dates']['published_at'],
            $seo['meta']['big_five_structured_data_v1']['visible_alignment']['dates']['reviewed_at']);

        return ['article_seo_meta' => array_intersect_key($detail['article']['seo_meta'],
            array_flip(['seo_title', 'seo_description', 'og_title', 'og_description', 'robots', 'canonical_url'])),
            'seo_surface_v1' => array_intersect_key($detail['seo_surface_v1'],
                array_flip(['title', 'description', 'canonical_url', 'robots_policy', 'alternates', 'og_payload', 'twitter_payload',
                    'indexability_state', 'sitemap_state', 'llms_exposure_state'])),
            'meta' => $seo['meta']];
    }

    private function state(): array
    {
        $out = [];
        foreach (['articles', 'article_translation_revisions', 'article_seo_meta', 'editorial_reviews',
            'review_attestations', 'review_attestation_target_evidences', 'content_material_decisions', 'audit_logs'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->keyBy('id')->all();
        }

        return $out;
    }

    private function seedPair(): void
    {
        $this->seedCaptured('production');
    }

    private function seedStaging(): void
    {
        $this->seedCaptured('staging');
    }

    private function seedCaptured(string $environment): void
    {
        // Captured current public/workflow rows; production fixture omits unrelated
        // historical drafts and private authority metadata, and uses a test note.
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/enneagram_comparison_'.$environment.'_baseline.json')), true);
        Schema::withoutForeignKeyConstraints(function () use ($fixture): void {
            foreach (['articles', 'article_translation_revisions' => 'revisions', 'article_seo_meta' => 'seo', 'editorial_reviews'] as $table => $key) {
                $table = is_int($table) ? $key : $table;
                foreach ($fixture[$key] as $row) {
                    DB::table($table)->insert($row);
                }
            }
        });
    }
}
