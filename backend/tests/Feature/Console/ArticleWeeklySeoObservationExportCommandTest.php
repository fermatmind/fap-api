<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ArticleWeeklySeoObservationExportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSeoIntelSqliteConnection();
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('articles:weekly-seo-observation-export', Artisan::all());
    }

    public function test_exports_release_closeout_gsc_and_site_conversion_metrics_for_locked_article(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 26, 8, 0, 0, 'UTC'));
        $article = $this->createReleasedArticle();
        $canonicalUrl = 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist';
        $canonicalPath = '/zh/articles/gaokao-score-major-shortlist-riasec-checklist';

        $this->insertUrlTruth($canonicalUrl, (string) $article->locale, (string) $article->slug);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'indexnow', 58);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'baidu_push', 59);
        $this->createGscDailyTable();
        $this->insertGscDaily($canonicalUrl, '2026-06-20', clicks: 2, impressions: 120, positionMilli: 8400);
        $this->insertGscDaily($canonicalUrl, '2026-06-21', clicks: 1, impressions: 80, positionMilli: 7600);
        $this->insertSeoConversionDaily($canonicalPath);

        $exitCode = Artisan::call('articles:weekly-seo-observation-export', [
            '--article-ids' => '53',
            '--expected-slugs' => 'gaokao-score-major-shortlist-riasec-checklist',
            '--from' => '2026-06-19',
            '--to' => '2026-06-25',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('article_weekly_seo_observation_export.v1', $payload['schema_version']);
        $this->assertSame(1, data_get($payload, 'summary.article_count'));
        $this->assertSame(3, data_get($payload, 'summary.gsc_clicks'));
        $this->assertSame(200, data_get($payload, 'summary.gsc_impressions'));
        $this->assertSame('ARTICLE_RELEASE_COMPLETE_SEARCH_OBSERVATION_PENDING', data_get($payload, 'articles.0.release_closeout.decision'));
        $this->assertSame('due_or_ready_to_record', data_get($payload, 'articles.0.observation_windows.d1.state'));
        $this->assertSame('scheduled', data_get($payload, 'articles.0.observation_windows.d7.state'));
        $this->assertSame(3, data_get($payload, 'articles.0.gsc.clicks'));
        $this->assertSame(200, data_get($payload, 'articles.0.gsc.impressions'));
        $this->assertSame(0.015, data_get($payload, 'articles.0.gsc.ctr'));
        $this->assertSame(8.08, data_get($payload, 'articles.0.gsc.average_position'));
        $this->assertSame(9, data_get($payload, 'articles.0.site_conversion.landing_pv_count'));
        $this->assertSame(2, data_get($payload, 'articles.0.site_conversion.article_to_test_click_count'));
        $this->assertFalse($payload['external_search_submission_attempted']);
        $this->assertFalse($payload['cms_content_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['schema_hreflang_write_attempted']);
        $this->assertFalse($payload['sitemap_llms_mutation_attempted']);
    }

    public function test_missing_gsc_table_warns_without_blocking_export(): void
    {
        $article = $this->createReleasedArticle();
        $canonicalUrl = 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist';
        $this->insertUrlTruth($canonicalUrl, (string) $article->locale, (string) $article->slug);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'indexnow', 58);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'baidu_push', 59);

        $exitCode = Artisan::call('articles:weekly-seo-observation-export', [
            '--article-ids' => '53',
            '--expected-slugs' => 'gaokao-score-major-shortlist-riasec-checklist',
            '--from' => '2026-06-19',
            '--to' => '2026-06-25',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertFalse(data_get($payload, 'articles.0.gsc.table_available'));
        $this->assertContains('seo_gsc_daily_missing', data_get($payload, 'articles.0.gsc.warnings'));
    }

    public function test_slug_lock_mismatch_is_reported_in_closeout_row(): void
    {
        $this->createReleasedArticle();

        $exitCode = Artisan::call('articles:weekly-seo-observation-export', [
            '--article-ids' => '53',
            '--expected-slugs' => 'wrong-slug',
            '--from' => '2026-06-19',
            '--to' => '2026-06-25',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('BLOCKED_DISCOVERABILITY_GAP', data_get($payload, 'articles.0.release_closeout.decision'));
        $this->assertContains('expected_slug_mismatch', data_get($payload, 'articles.0.release_closeout.issue_codes'));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createReleasedArticle(array $overrides = []): Article
    {
        /** @var ArticleCategory $category */
        $category = Model::unguarded(fn (): ArticleCategory => ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'career-decision',
            'name' => '职业决策',
            'is_active' => true,
        ]));

        /** @var Article $article */
        $article = Model::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create(array_merge([
            'id' => 53,
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'author_name' => 'Fermat Institute',
            'reading_minutes' => 12,
            'slug' => 'gaokao-score-major-shortlist-riasec-checklist',
            'locale' => 'zh-CN',
            'translation_group_id' => 'tg_article_gaokao_score_major_shortlist_riasec_2026v1',
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '高考出分后专业太多怎么筛？位次+霍兰德排除清单',
            'excerpt' => '高考出分后，用位次、选科要求、霍兰德职业兴趣测试和排除清单缩小专业范围。',
            'content_md' => '# 高考出分后专业太多怎么筛？'."\n\n![流程图](https://api.fermatmind.com/storage/media-library/body.png)\n\n正文。",
            'content_html' => '<h1>高考出分后专业太多怎么筛？</h1><img src="https://api.fermatmind.com/storage/media-library/body.png">',
            'cover_image_url' => 'https://api.fermatmind.com/storage/media-library/cover.jpg',
            'cover_image_variants' => [
                'card' => ['url' => 'https://api.fermatmind.com/storage/media-library/card.jpg'],
            ],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => Carbon::create(2026, 6, 19, 5, 30, 0, 'UTC'),
        ], $overrides)));

        /** @var ArticleTag $tag */
        $tag = Model::unguarded(fn (): ArticleTag => ArticleTag::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'riasec',
            'name' => 'RIASEC',
            'is_active' => true,
        ]));
        DB::table('article_tag_map')->insert([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'tag_id' => (int) $tag->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var ArticleTranslationRevision $revision */
        $revision = Model::unguarded(fn (): ArticleTranslationRevision => ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'published_at' => $article->published_at,
        ]));

        $article->forceFill(['published_revision_id' => (int) $revision->id])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'canonical_url' => 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist',
            'og_title' => (string) $article->title,
            'og_description' => (string) $article->excerpt,
            'og_image_url' => 'https://api.fermatmind.com/storage/media-library/og.jpg',
            'robots' => 'index,follow',
            'schema_json' => [
                'editorial_package_v1' => [
                    'article_schema_enabled' => true,
                    'breadcrumb_schema_enabled' => true,
                    'faq_schema_enabled' => false,
                    'hreflang_gate_v1' => [
                        'enabled' => false,
                        'policy' => 'no_hreflang',
                        'reason' => 'no_direct_english_counterpart_approved',
                    ],
                ],
            ],
            'is_indexable' => true,
        ]);

        return $article->fresh(['category', 'tags', 'publishedRevision', 'seoMeta']) ?? $article;
    }

    private function prepareSeoIntelSqliteConnection(): void
    {
        config([
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('seo_intel');

        Schema::connection('seo_intel')->create('seo_urls', function ($table): void {
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
        });

        Schema::connection('seo_intel')->create('seo_search_channel_queue_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 255)->nullable();
            $table->string('source_authority', 64);
            $table->string('source_table', 128)->nullable();
            $table->string('channel', 64);
            $table->string('eligibility_state', 64)->default('eligible');
            $table->string('approval_state', 64)->default('pending');
            $table->string('execution_state', 64)->default('dry_run_ready');
            $table->string('indexability_state', 64);
            $table->string('claim_boundary_state', 64)->default('claim_safe');
            $table->boolean('private_flow')->default(false);
            $table->json('reason_codes')->nullable();
            $table->timestamp('lastmod')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->char('url_hash', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    private function createGscDailyTable(): void
    {
        Schema::connection('seo_intel')->create('seo_gsc_daily', function ($table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('query_display_masked', 255)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64)->default('google');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('ctr_ppm')->nullable();
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->boolean('is_brand_query')->default(false);
            $table->string('query_type', 32)->default('unknown');
            $table->timestamps();
        });
    }

    private function insertUrlTruth(string $canonicalUrl, string $locale, string $slug): void
    {
        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
            'canonical_url' => $canonicalUrl,
            'locale' => $locale,
            'page_entity_type' => 'article',
            'entity_id_or_slug' => $slug,
            'cluster' => 'article',
            'source_authority' => 'cms_article',
            'indexability_state' => 'indexable',
            'lastmod_at' => now(),
            'lastmod_source' => 'articles.updated_at',
            'is_private_flow' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'metadata_json' => json_encode(['source_table' => 'articles'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSearchQueue(string $canonicalUrl, string $locale, string $channel, int $id): void
    {
        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert([
            'id' => $id,
            'canonical_url' => $canonicalUrl,
            'locale' => $locale,
            'page_entity_type' => 'article',
            'entity_type' => 'article',
            'entity_id' => '53',
            'source_authority' => 'cms_article',
            'source_table' => 'articles',
            'channel' => $channel,
            'eligibility_state' => 'eligible',
            'approval_state' => 'approved',
            'execution_state' => 'accepted',
            'indexability_state' => 'indexable',
            'claim_boundary_state' => 'claim_safe',
            'private_flow' => false,
            'reason_codes' => json_encode([], JSON_THROW_ON_ERROR),
            'lastmod' => now(),
            'content_hash' => hash('sha256', 'article-53'),
            'url_hash' => hash('sha256', $canonicalUrl),
            'idempotency_key' => hash('sha256', $canonicalUrl.'|'.$locale.'|'.$channel),
            'approved_by' => 'operator',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertGscDaily(string $canonicalUrl, string $date, int $clicks, int $impressions, int $positionMilli): void
    {
        DB::connection('seo_intel')->table('seo_gsc_daily')->insert([
            'report_date' => $date,
            'canonical_url_hash' => hash('sha256', $canonicalUrl),
            'canonical_url' => $canonicalUrl,
            'query_hash' => hash('sha256', '高考专业筛选'),
            'query_display_masked' => '高考专业筛选',
            'locale' => 'zh-CN',
            'source_engine' => 'google',
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr_ppm' => $impressions > 0 ? (int) round(($clicks / $impressions) * 1000000) : null,
            'average_position_milli' => $positionMilli,
            'is_brand_query' => false,
            'query_type' => 'non_brand',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSeoConversionDaily(string $canonicalPath): void
    {
        DB::table('analytics_seo_conversion_daily')->insert([
            'day' => '2026-06-20',
            'org_id' => 0,
            'url' => $canonicalPath,
            'url_hash' => sha1($canonicalPath),
            'lang' => 'zh-CN',
            'page_type' => 'article',
            'source_url' => $canonicalPath,
            'source_url_hash' => sha1($canonicalPath),
            'source_article' => $canonicalPath,
            'source_article_hash' => sha1($canonicalPath),
            'target_test' => '/zh/tests/holland-career-interest-test-riasec',
            'target_test_hash' => sha1('/zh/tests/holland-career-interest-test-riasec'),
            'scale_id' => 'riasec',
            'form_id' => 'riasec_60',
            'session_id_hash' => hash('sha256', 'session'),
            'referrer_host' => '',
            'referrer_host_hash' => '',
            'landing_pv_count' => 9,
            'article_to_test_click_count' => 2,
            'start_test_count' => 1,
            'complete_test_count' => 1,
            'view_result_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }
}
