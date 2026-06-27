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

final class ArticleReleaseCloseoutCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSeoIntelSqliteConnection();
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('articles:release-closeout', Artisan::all());
    }

    public function test_complete_article_outputs_search_observation_pending_decision(): void
    {
        $article = $this->createReleasedArticle();
        $canonicalUrl = 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist';
        $this->insertUrlTruth($canonicalUrl, (string) $article->locale, (string) $article->slug);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'indexnow', 58);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'baidu_push', 59);

        $exitCode = Artisan::call('articles:release-closeout', [
            '--article-id' => '53',
            '--expected-slug' => 'gaokao-score-major-shortlist-riasec-checklist',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('ARTICLE_RELEASE_COMPLETE_SEARCH_OBSERVATION_PENDING', $payload['decision']);
        $this->assertSame('/zh/articles/gaokao-score-major-shortlist-riasec-checklist', $payload['canonical_path']);
        $this->assertSame(58, data_get($payload, 'checks.search_channel.channels.indexnow.queue_item_id'));
        $this->assertSame(59, data_get($payload, 'checks.search_channel.channels.baidu_push.queue_item_id'));
        $this->assertSame('not_provided', data_get($payload, 'checks.public_html_smoke.state'));
        $this->assertFalse($payload['external_search_submission_attempted']);
        $this->assertFalse($payload['cms_content_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['schema_hreflang_write_attempted']);
        $this->assertFalse($payload['sitemap_llms_mutation_attempted']);
    }

    public function test_closeout_accepts_ops_media_library_origin_and_explicit_schema_hreflang_holds(): void
    {
        $article = $this->createReleasedArticle([
            'content_md' => '# 高考出分后专业太多怎么筛？'."\n\n![流程图](https://ops.fermatmind.com/storage/media-library/body.png)\n\n正文。",
            'content_html' => '<h1>高考出分后专业太多怎么筛？</h1><img src="https://ops.fermatmind.com/storage/media-library/body.png">',
            'cover_image_url' => 'https://ops.fermatmind.com/storage/media-library/cover.jpg',
        ]);
        $article->seoMeta?->forceFill([
            'og_image_url' => 'https://ops.fermatmind.com/storage/media-library/og.jpg',
            'schema_json' => [
                'editorial_package_v1' => [
                    'schema_hold' => true,
                    'hreflang_hold' => true,
                    'article_schema_enabled' => false,
                    'breadcrumb_schema_enabled' => false,
                    'faq_schema_enabled' => false,
                ],
            ],
        ])->save();

        $canonicalUrl = 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist';
        $this->insertUrlTruth($canonicalUrl, (string) $article->locale, (string) $article->slug);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'indexnow', 58);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'baidu_push', 59);

        $exitCode = Artisan::call('articles:release-closeout', [
            '--article-id' => '53',
            '--expected-slug' => 'gaokao-score-major-shortlist-riasec-checklist',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertTrue($payload['ok']);
        $this->assertTrue(data_get($payload, 'checks.media.ok'));
        $this->assertSame('held', data_get($payload, 'checks.schema_hreflang.schema_state'));
        $this->assertSame('held', data_get($payload, 'checks.schema_hreflang.hreflang_state'));
        $this->assertTrue(data_get($payload, 'checks.schema_hreflang.schema_hold'));
        $this->assertTrue(data_get($payload, 'checks.schema_hreflang.hreflang_hold'));
        $this->assertErrorCodeMissing($payload, 'media_url_not_public_origin');
        $this->assertErrorCodeMissing($payload, 'article_schema_not_enabled');
        $this->assertErrorCodeMissing($payload, 'breadcrumb_schema_not_enabled');
        $this->assertErrorCodeMissing($payload, 'hreflang_policy_missing');
    }

    public function test_missing_search_queue_blocks_search_closeout(): void
    {
        $article = $this->createReleasedArticle();
        $canonicalUrl = 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist';
        $this->insertUrlTruth($canonicalUrl, (string) $article->locale, (string) $article->slug);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'indexnow', 58);

        $exitCode = Artisan::call('articles:release-closeout', [
            '--article-id' => '53',
            '--expected-slug' => 'gaokao-score-major-shortlist-riasec-checklist',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertSame('BLOCKED_SEARCH_QUEUE_GAP', $payload['decision']);
        $this->assertErrorCode($payload, 'search_channel_queue_missing');
    }

    public function test_internal_taxonomy_and_private_media_block_operator_input(): void
    {
        $article = $this->createReleasedArticle([
            'cover_image_url' => 'https://private.example.test/cover.jpg',
        ], categoryName: 'SEO Articles');
        $canonicalUrl = 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist';
        $this->insertUrlTruth($canonicalUrl, (string) $article->locale, (string) $article->slug);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'indexnow', 58);
        $this->insertSearchQueue($canonicalUrl, (string) $article->locale, 'baidu_push', 59);

        $exitCode = Artisan::call('articles:release-closeout', [
            '--article-id' => '53',
            '--expected-slug' => 'gaokao-score-major-shortlist-riasec-checklist',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertSame('BLOCKED_OPERATOR_INPUT', $payload['decision']);
        $this->assertErrorCode($payload, 'media_url_not_public_origin');
        $this->assertErrorCode($payload, 'category_not_reader_facing_zh');
    }

    public function test_slug_lock_mismatch_blocks_discoverability(): void
    {
        $this->createReleasedArticle();

        $exitCode = Artisan::call('articles:release-closeout', [
            '--article-id' => '53',
            '--expected-slug' => 'wrong-slug',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertSame('BLOCKED_DISCOVERABILITY_GAP', $payload['decision']);
        $this->assertErrorCode($payload, 'expected_slug_mismatch');
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createReleasedArticle(array $overrides = [], string $categoryName = '职业决策'): Article
    {
        /** @var ArticleCategory $category */
        $category = Model::unguarded(fn (): ArticleCategory => ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'career-decision',
            'name' => $categoryName,
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

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertErrorCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            (array) ($payload['issues'] ?? [])
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertErrorCodeMissing(array $payload, string $code): void
    {
        $this->assertNotContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            (array) ($payload['issues'] ?? [])
        ));
    }
}
