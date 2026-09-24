<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentMaterialDecision;
use App\Services\Cms\ArticleMaterialDecisionService;
use App\Services\Cms\ArticlePublishService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class ArticleMaterialDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_publish_transaction_records_material_change_and_identical_republish_preserves_lastmod(): void
    {
        $firstPublishedAt = CarbonImmutable::parse('2026-08-28T01:00:00Z');
        CarbonImmutable::setTestNow($firstPublishedAt);
        $article = $this->draftArticle('zh-CN', 'material-article');
        $firstRevision = $article->workingRevision()->firstOrFail();
        $firstRevision->forceFill([
            'authority_metadata_json' => [
                'claims_and_sources' => [
                    ['claim' => 'A', 'source' => ['label' => 'Source A', 'url' => 'https://example.test/a']],
                    ['claim' => 'B', 'source' => ['label' => 'Source B', 'url' => 'https://example.test/b']],
                ],
            ],
        ])->saveQuietly();

        app(ArticlePublishService::class)->publishArticle((int) $article->id);

        $initial = ContentMaterialDecision::query()->firstOrFail();
        self::assertSame('initial_publish', $initial->decision_code);
        self::assertTrue((bool) $initial->material_changed);
        self::assertSame($firstPublishedAt->toISOString(), $initial->material_changed_at?->toISOString());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $initial->material_fingerprint);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $initial->search_surface_fingerprint);
        self::assertSame((string) $firstRevision->id, (string) $initial->authority_revision);
        self::assertSame('/zh-CN/articles/material-article', $initial->public_identity);

        CarbonImmutable::setTestNow('2026-08-29T01:00:00Z');
        $secondRevision = $this->approvedRevision($article->fresh(), 2, [
            'content_md' => "## Stable body  \r\n\r\nMaterial content.\r\n",
            'authority_metadata_json' => [
                'private_token' => 'non-material-private-context',
                'claims_and_sources' => [
                    ['source' => ['url' => 'https://example.test/b', 'label' => 'Source B'], 'claim' => 'B'],
                    ['source' => ['url' => 'https://example.test/a', 'label' => 'Source A'], 'claim' => 'A'],
                ],
            ],
        ]);
        $article->forceFill(['working_revision_id' => $secondRevision->id])->saveQuietly();
        app(ArticlePublishService::class)->promoteExistingWorkingRevision(
            (int) $article->id,
            (int) $secondRevision->id,
            (int) $firstRevision->id,
            dispatchFollowUp: false,
        );

        $unchanged = ContentMaterialDecision::query()->latest('id')->firstOrFail();
        self::assertSame('unchanged_republish', $unchanged->decision_code);
        self::assertFalse((bool) $unchanged->material_changed);
        self::assertSame((string) $initial->material_fingerprint, (string) $unchanged->material_fingerprint);
        self::assertSame((string) $initial->search_surface_fingerprint, (string) $unchanged->search_surface_fingerprint);
        self::assertSame($firstPublishedAt->toISOString(), $unchanged->material_changed_at?->toISOString());
    }

    public function test_search_surface_change_updates_material_lastmod_and_private_metadata_is_not_stored(): void
    {
        CarbonImmutable::setTestNow('2026-08-28T02:00:00Z');
        $article = $this->draftArticle('en', 'search-material-article');
        app(ArticlePublishService::class)->publishArticle((int) $article->id);
        $initial = ContentMaterialDecision::query()->firstOrFail();

        CarbonImmutable::setTestNow('2026-08-30T02:00:00Z');
        $currentRevisionId = (int) $article->fresh()->published_revision_id;
        $revision = $this->approvedRevision($article->fresh(), 2, [
            'seo_title' => 'Changed public SEO title',
            'authority_metadata_json' => [
                'claims_and_sources' => [
                    ['claim' => 'Public bounded claim', 'source' => 'https://example.test/public-source'],
                ],
                'private_token' => 'must-never-be-stored',
                'visible_provenance' => [
                    'sources' => [['label' => 'Public source', 'url' => 'https://example.test/public-source']],
                ],
            ],
        ]);
        $article->forceFill(['working_revision_id' => $revision->id])->saveQuietly();

        app(ArticlePublishService::class)->promoteExistingWorkingRevision(
            (int) $article->id,
            (int) $revision->id,
            $currentRevisionId,
            dispatchFollowUp: false,
        );

        $changed = ContentMaterialDecision::query()->latest('id')->firstOrFail();
        self::assertSame('material_change', $changed->decision_code);
        self::assertTrue((bool) $changed->material_changed);
        self::assertNotSame((string) $initial->material_fingerprint, (string) $changed->material_fingerprint);
        self::assertNotSame((string) $initial->search_surface_fingerprint, (string) $changed->search_surface_fingerprint);
        self::assertSame('2026-08-30T02:00:00.000000Z', $changed->material_changed_at?->toISOString());
        self::assertStringNotContainsString('must-never-be-stored', json_encode($changed->getAttributes(), JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('payload', $changed->getAttributes());
    }

    public function test_body_only_change_does_not_change_search_surface_fingerprint(): void
    {
        $article = $this->draftArticle('zh-CN', 'body-only-article');
        app(ArticlePublishService::class)->publishArticle((int) $article->id);
        $initial = ContentMaterialDecision::query()->firstOrFail();
        $currentRevisionId = (int) $article->fresh()->published_revision_id;

        $revision = $this->approvedRevision($article->fresh(), 2, [
            'content_md' => "## Stable body\n\nSubstantially revised content.",
        ]);
        $article->forceFill(['working_revision_id' => $revision->id])->saveQuietly();
        app(ArticlePublishService::class)->promoteExistingWorkingRevision(
            (int) $article->id,
            (int) $revision->id,
            $currentRevisionId,
            dispatchFollowUp: false,
        );

        $changed = ContentMaterialDecision::query()->latest('id')->firstOrFail();
        self::assertTrue((bool) $changed->material_changed);
        self::assertNotSame((string) $initial->material_fingerprint, (string) $changed->material_fingerprint);
        self::assertSame((string) $initial->search_surface_fingerprint, (string) $changed->search_surface_fingerprint);
    }

    public function test_indexnow_candidate_planning_uses_exact_public_url_and_skips_body_only_changes(): void
    {
        $article = $this->draftArticle('zh-CN', 'indexnow-candidate');
        $article->seoMeta->forceFill(['canonical_url' => '/zh/articles/indexnow-candidate'])->saveQuietly();
        app(ArticlePublishService::class)->publishArticle((int) $article->id);

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
        ]);
        DB::purge('seo_intel');
        Schema::connection('seo_intel')->create('seo_urls', static function ($table): void {
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
        $this->createIndexNowQueueTables();
        $url = 'https://fermatmind.com/zh/articles/indexnow-candidate';
        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => hash('sha256', $url),
            'canonical_url' => $url,
            'locale' => 'zh-CN',
            'page_entity_type' => 'article',
            'entity_id_or_slug' => (string) $article->id,
            'cluster' => 'article',
            'source_authority' => 'backend_cms',
            'indexability_state' => 'indexable',
            'lastmod_at' => now(),
            'lastmod_source' => 'articles.updated_at',
            'is_private_flow' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'metadata_json' => json_encode(['claim_safe' => true, 'publication_state' => 'published', 'source_table' => 'articles'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $initial = $this->indexNowPlan((int) $article->id);
        self::assertSame(1, $initial['candidate_count']);
        self::assertSame('initial_publish', $initial['items'][0]['reason']);
        self::assertSame(hash('sha256', $url), $initial['items'][0]['url_hash']);
        self::assertFalse($initial['writes_attempted']);

        $currentRevisionId = (int) $article->fresh()->published_revision_id;
        $bodyRevision = $this->approvedRevision($article->fresh(), 2, ['content_md' => "## New body\n\nSubstantially revised content."]);
        $article->forceFill(['working_revision_id' => $bodyRevision->id])->saveQuietly();
        app(ArticlePublishService::class)->promoteExistingWorkingRevision(
            (int) $article->id,
            (int) $bodyRevision->id,
            $currentRevisionId,
            dispatchFollowUp: false,
        );

        $bodyOnly = $this->indexNowPlan((int) $article->id);
        self::assertSame(0, $bodyOnly['candidate_count']);
        self::assertSame('search_surface_unchanged', $bodyOnly['items'][0]['reason']);
        self::assertFalse($bodyOnly['external_calls_attempted']);

        $seoRevision = $this->approvedRevision($article->fresh(), 3, ['seo_title' => 'Changed search title']);
        $article->forceFill(['working_revision_id' => $seoRevision->id])->saveQuietly();
        app(ArticlePublishService::class)->promoteExistingWorkingRevision(
            (int) $article->id,
            (int) $seoRevision->id,
            (int) $bodyRevision->id,
            dispatchFollowUp: false,
        );
        $metadataChanged = $this->indexNowPlan((int) $article->id);
        self::assertSame(1, $metadataChanged['candidate_count']);
        self::assertSame('search_surface_changed', $metadataChanged['items'][0]['reason']);

        DB::connection('seo_intel')->table('seo_urls')
            ->where('canonical_url', $url)
            ->update(['indexability_state' => 'noindex']);
        $blocked = $this->indexNowPlan((int) $article->id);
        self::assertSame(0, $blocked['candidate_count']);
        self::assertContains('exact_url_truth_not_ready', $blocked['items'][0]['issues']);

        DB::connection('seo_intel')->table('seo_urls')->where('canonical_url', $url)->update(['indexability_state' => 'indexable']);
        config([
            'seo_intel.article_indexnow_auto_enabled' => true,
            'seo_intel.search_channel_queue.live_submission.indexnow.key' => 'fixture-indexnow-key',
            'seo_intel.search_channel_queue.live_submission.indexnow.key_location' => 'https://fermatmind.com/fixture-indexnow-key.txt',
        ]);
        $seo = $article->fresh()->seoMeta;
        $title = htmlspecialchars((string) $seo->seo_title, ENT_QUOTES | ENT_HTML5);
        $description = htmlspecialchars((string) $seo->seo_description, ENT_QUOTES | ENT_HTML5);
        Http::fake([
            $url => Http::response('<html><head><title>'.$title.'</title><link rel="canonical" href="'.$url.'">'
                .'<meta name="robots" content="index, follow"><meta name="description" content="'.$description.'">'
                .'</head><body><main><h1>Article title</h1>'.str_repeat('Current public article body. ', 20).'</main></body></html>', 200),
            'api.indexnow.org/*' => Http::response('', 202),
        ]);
        $submitted = $this->indexNowPlan((int) $article->id, true);
        self::assertSame(1, $submitted['submitted_count']);
        self::assertSame('provider_accepted', $submitted['items'][0]['state']);
        self::assertSame('submitted', DB::connection('seo_intel')->table('seo_search_channel_queue_items')->value('execution_state'));
        self::assertSame(1, DB::connection('seo_intel')->table('seo_search_channel_queue_events')->where('event_type', 'bounded_live_submission_response')->count());
        Http::assertSentCount(2);

        $secondPlan = app(\App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueuePlanner::class)->plan('indexnow', 'article', 1, $url);
        self::assertSame(0, $secondPlan['planned_queue_count'], json_encode($secondPlan, JSON_THROW_ON_ERROR));

        $repeated = $this->indexNowPlan((int) $article->id, true);
        self::assertSame(0, $repeated['submitted_count'], json_encode($repeated, JSON_THROW_ON_ERROR));
        Http::assertSentCount(2);
    }

    /** @return array<string, mixed> */
    private function indexNowPlan(int $articleId, bool $execute = false): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('articles:indexnow-candidates', [
            '--article-id' => $articleId,
            '--execute' => $execute,
        ], $output);
        $raw = $output->fetch();
        self::assertSame(0, $exitCode, $raw);
        $result = json_decode(trim($raw), true);
        self::assertIsArray($result, $raw);

        return $result;
    }

    private function createIndexNowQueueTables(): void
    {
        Schema::connection('seo_intel')->create('seo_search_channel_queue_batches', static function ($table): void {
            $table->id();
            $table->string('channel', 64);
            $table->string('status', 64);
            $table->unsignedInteger('item_count');
            $table->json('dry_run_report')->nullable();
            $table->text('approval_note')->nullable();
            $table->string('created_by', 128)->nullable();
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('seo_intel')->create('seo_search_channel_queue_items', static function ($table): void {
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
            $table->string('eligibility_state', 64);
            $table->string('approval_state', 64);
            $table->string('execution_state', 64);
            $table->string('indexability_state', 64);
            $table->string('claim_boundary_state', 64);
            $table->boolean('private_flow');
            $table->json('reason_codes')->nullable();
            $table->timestamp('lastmod')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->char('url_hash', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('seo_intel')->create('seo_search_channel_queue_events', static function ($table): void {
            $table->id();
            $table->unsignedBigInteger('queue_item_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('event_type', 96);
            $table->json('event_payload')->nullable();
            $table->string('actor_type', 64);
            $table->string('actor_id', 128)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function test_unpublish_is_traceable_idempotent_and_unknown_legacy_hash_holds(): void
    {
        CarbonImmutable::setTestNow('2026-08-28T03:00:00Z');
        $article = $this->draftArticle('zh-CN', 'unpublish-material-article');
        app(ArticlePublishService::class)->publishArticle((int) $article->id);

        CarbonImmutable::setTestNow('2026-08-29T03:00:00Z');
        app(ArticlePublishService::class)->unpublishArticle((int) $article->id);
        app(ArticlePublishService::class)->unpublishArticle((int) $article->id);

        self::assertSame(2, ContentMaterialDecision::query()->count());
        $unpublished = ContentMaterialDecision::query()->latest('id')->firstOrFail();
        self::assertSame('unpublish', $unpublished->decision_code);
        self::assertSame('unpublished', $unpublished->publication_state);
        self::assertTrue((bool) $unpublished->material_changed);

        $legacy = $this->draftArticle('en', 'legacy-unpublish-hold');
        $legacy->forceFill([
            'status' => 'draft',
            'is_public' => false,
            'published_revision_id' => $legacy->working_revision_id,
        ])->saveQuietly();
        DB::transaction(fn () => app(ArticleMaterialDecisionService::class)->recordUnpublished(
            $legacy,
            CarbonImmutable::parse('2026-08-30T03:00:00Z'),
        ));

        $hold = ContentMaterialDecision::query()
            ->where('public_identity', '/en/articles/legacy-unpublish-hold')
            ->firstOrFail();
        self::assertSame('unpublish_hold_unknown_legacy_fingerprint', $hold->decision_code);
        self::assertFalse((bool) $hold->material_changed);
        self::assertNull($hold->material_changed_at);
        self::assertNull($hold->material_fingerprint);
    }

    public function test_same_revision_can_be_republished_after_unpublish_without_key_collision(): void
    {
        CarbonImmutable::setTestNow('2026-08-28T03:30:00Z');
        $article = $this->draftArticle('zh-CN', 'republish-cycle');
        app(ArticlePublishService::class)->publishArticle((int) $article->id);

        app(ArticlePublishService::class)->unpublishArticle((int) $article->id);
        app(ArticlePublishService::class)->publishArticle((int) $article->id);

        self::assertSame(3, ContentMaterialDecision::query()->count());
        $republished = ContentMaterialDecision::query()->latest('id')->firstOrFail();
        self::assertSame('republish_after_unpublish', $republished->decision_code);
        self::assertTrue((bool) $republished->material_changed);
    }

    public function test_locale_material_times_are_independent(): void
    {
        CarbonImmutable::setTestNow('2026-08-28T04:00:00Z');
        $zh = $this->draftArticle('zh-CN', 'locale-independent-zh');
        app(ArticlePublishService::class)->publishArticle((int) $zh->id);

        CarbonImmutable::setTestNow('2026-08-31T04:00:00Z');
        $en = $this->draftArticle('en', 'locale-independent-en');
        $en->forceFill(['translation_group_id' => $zh->translation_group_id])->saveQuietly();
        app(ArticlePublishService::class)->publishArticle((int) $en->id);

        $zhDecision = ContentMaterialDecision::query()->where('locale', 'zh-CN')->firstOrFail();
        $enDecision = ContentMaterialDecision::query()->where('locale', 'en')->firstOrFail();
        self::assertNotSame($zhDecision->material_changed_at?->toISOString(), $enDecision->material_changed_at?->toISOString());
        self::assertNotSame((string) $zhDecision->material_fingerprint, (string) $enDecision->material_fingerprint);
    }

    public function test_public_slug_change_keeps_article_lineage_without_hashing_internal_identity(): void
    {
        CarbonImmutable::setTestNow('2026-08-28T05:00:00Z');
        $article = $this->draftArticle('en', 'before-slug');
        app(ArticlePublishService::class)->publishArticle((int) $article->id);
        $initial = ContentMaterialDecision::query()->firstOrFail();

        CarbonImmutable::setTestNow('2026-08-29T05:00:00Z');
        $article = $article->fresh();
        $article->forceFill(['slug' => 'after-slug'])->saveQuietly();
        DB::transaction(function () use ($article): void {
            $locked = Article::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($article->id);
            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()
                ->findOrFail($locked->published_revision_id);
            app(ArticleMaterialDecisionService::class)->recordPublished($locked, $revision, now());
        });

        $changed = ContentMaterialDecision::query()->latest('id')->firstOrFail();
        self::assertSame('material_change', $changed->decision_code);
        self::assertSame('/en/articles/after-slug', $changed->public_identity);
        self::assertSame('/en/articles/before-slug', $changed->previous_public_identity);
        self::assertSame('article:'.$article->id, $changed->authority_subject_key);
        self::assertNotSame($initial->material_fingerprint, $changed->material_fingerprint);
    }

    private function draftArticle(string $locale, string $slug): Article
    {
        $article = Article::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'translation_group_id' => 'group-'.$slug,
            'source_locale' => $locale,
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Stable title',
            'excerpt' => 'Stable excerpt',
            'content_md' => "## Stable body\n\nMaterial content.",
            'status' => 'draft',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => false,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
        ]));
        $revision = $this->approvedRevision($article, 1);
        $article->forceFill(['working_revision_id' => $revision->id])->saveQuietly();
        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => $article->id,
            'locale' => $locale,
            'seo_title' => 'Stable SEO title',
            'seo_description' => 'Stable SEO description',
            'canonical_url' => '/'.$locale.'/articles/'.$slug,
            'og_title' => 'Stable SEO title',
            'og_description' => 'Stable SEO description',
            'robots' => 'index,follow',
            'schema_json' => ['@type' => 'Article', 'dateModified' => '2099-01-01T00:00:00Z'],
            'is_indexable' => true,
        ]);

        return $article->fresh(['workingRevision', 'seoMeta']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function approvedRevision(Article $article, int $revisionNumber, array $overrides = []): ArticleTranslationRevision
    {
        return ArticleTranslationRevision::query()->withoutGlobalScopes()->create(array_replace([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) $article->source_locale,
            'revision_number' => $revisionNumber,
            'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'supersedes_revision_id' => $article->published_revision_id,
            'title' => 'Stable title',
            'excerpt' => 'Stable excerpt',
            'content_md' => "## Stable body\n\nMaterial content.",
            'seo_title' => 'Stable SEO title',
            'seo_description' => 'Stable SEO description',
            'reviewed_at' => now()->subMinute(),
            'approved_at' => now(),
        ], $overrides));
    }
}
