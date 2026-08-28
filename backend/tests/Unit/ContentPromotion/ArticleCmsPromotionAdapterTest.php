<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentMaterialDecision;
use App\Services\Cms\ArticlePublicListReadCache;
use App\Services\ContentPromotion\ArticleCmsPromotionAuthority;
use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ArticleCmsPromotionAdapterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_w3_article_package_is_revision_bound_idempotent_and_rolls_back_only_its_articles(): void
    {
        $first = $this->article('first-article');
        $second = $this->article('second-article');
        $firstSourceHash = (string) $first->source_version_hash;
        $firstSeo = ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'article_id' => $first->id, 'seo_title' => 'Original SEO', 'seo_description' => 'Original description',
            'og_title' => 'Original OG', 'og_description' => 'Original OG description',
        ]);
        DB::table('article_seo_meta')->insert([
            'org_id' => $first->org_id, 'article_id' => $first->id, 'locale' => 'zh',
            'seo_title' => 'Stale locale SEO', 'seo_description' => 'Stale locale description',
            'og_title' => 'Stale locale OG', 'og_description' => 'Stale locale OG description',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $package = $this->package([$first, $second]);
        $context = $this->context($package, 2);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'W3-ARTICLES');
        self::assertSame('audit_compatible', $adapter->capability());
        self::assertSame(2, $adapter->preflight($context)['readback_count']);
        self::assertSame(2, $adapter->draftImport($context)['created_count']);
        self::assertSame(0, $adapter->draftImport($context)['created_count']);
        self::assertSame(2, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
        Cache::put(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation', 'before-publish', 600);
        $published = $adapter->publish($context);
        self::assertSame(2, $published['published_count']);
        self::assertSame(2, ContentMaterialDecision::query()->where('decision_code', 'initial_publish')->count());
        Cache::put(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation', 'before-replay', 600);
        $replayed = $adapter->publish($context);
        self::assertSame(0, $replayed['written_count']);
        self::assertSame($published['rollback_reference'], $replayed['rollback_reference']);
        self::assertSame(2, $adapter->liveQa($context)['published_count']);
        self::assertSame('Promoted first-article', $first->refresh()->title);
        self::assertNull($first->content_html);
        self::assertSame('Promoted second-article', $second->refresh()->title);
        self::assertNotSame($firstSourceHash, $first->source_version_hash);
        self::assertSame($first->source_version_hash, ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail($first->published_revision_id)->source_version_hash);
        self::assertSame('Promoted SEO', $firstSeo->refresh()->seo_title);
        self::assertSame('Promoted SEO', $firstSeo->refresh()->og_title);
        self::assertNotSame('before-replay', Cache::get(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation'));
        self::assertFalse((bool) $first->is_indexable);
        self::assertFalse((bool) $first->sitemap_eligible);
        self::assertFalse((bool) $first->llms_eligible);
        $adapter->rollback($context, (string) $published['rollback_reference']);
        self::assertSame(2, ContentMaterialDecision::query()->where('decision_code', 'rollback_material_change')->count());
        self::assertSame('Original first-article', $first->refresh()->title);
        self::assertSame('<p>Original body</p>', $first->content_html);
        self::assertSame('Original second-article', $second->refresh()->title);
        self::assertSame($firstSourceHash, $first->source_version_hash);
        self::assertSame('Original SEO', $firstSeo->refresh()->seo_title);
        self::assertSame('Original OG', $firstSeo->refresh()->og_title);
        self::assertSame('Stale locale SEO', DB::table('article_seo_meta')->where('article_id', $first->id)->where('locale', 'zh')->value('seo_title'));
        self::assertNotNull($first->working_revision_id);
        self::assertSame(ArticleTranslationRevision::STATUS_APPROVED, ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail($first->working_revision_id)->revision_status);
    }

    public function test_w3_article_package_fails_closed_on_cjk_private_payload_w9_and_foreign_working_revision(): void
    {
        $article = $this->article('bounded-article');
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'W3-ARTICLES');
        foreach ([
            ['snapshot' => ['title' => '中文'], 'error' => 'article_promotion_cjk_leakage'],
            ['snapshot' => ['seo_title' => '   '], 'error' => 'article_promotion_snapshot_invalid'],
            ['snapshot' => ['title' => str_repeat('a', 256)], 'error' => 'article_promotion_snapshot_invalid'],
            ['snapshot' => ['seo_title' => str_repeat('a', 61)], 'error' => 'article_promotion_snapshot_invalid'],
            ['snapshot' => ['seo_description' => str_repeat('a', 161)], 'error' => 'article_promotion_snapshot_invalid'],
            ['snapshot' => ['content_md' => '# Heading'], 'error' => 'article_promotion_body_h1_invalid'],
            ['snapshot' => ['content_md' => 'See /checkout?token=private'], 'error' => 'article_promotion_private_payload_invalid'],
            ['snapshot' => ['content_md' => 'See /shares/private-share-id'], 'error' => 'article_promotion_private_payload_invalid'],
            ['row' => ['private_token' => 'secret'], 'error' => 'article_promotion_private_payload_invalid'],
        ] as $case) {
            try {
                $adapter->preflight($this->context($this->package([$article], $case['snapshot'] ?? [], $case['row'] ?? []), 1));
                self::fail('Invalid package must fail closed.');
            } catch (\DomainException $exception) {
                self::assertSame($case['error'], $exception->getMessage());
            }
        }
        $directory = $this->package([$article], [], [], false);
        try {
            $adapter->preflight($this->context($directory, 1));
            self::fail('W9 is required.');
        } catch (\DomainException $exception) {
            self::assertSame('article_promotion_w9_evidence_incomplete', $exception->getMessage());
        }
        $foreign = ArticleTranslationRevision::query()->withoutGlobalScopes()->create($this->revisionPayload($article, 'foreign', ArticleTranslationRevision::STATUS_APPROVED));
        $article->forceFill(['working_revision_id' => $foreign->id])->saveQuietly();
        try {
            $adapter->draftImport($this->context($this->package([$article]), 1));
            self::fail('Foreign workspace must be retained.');
        } catch (\DomainException $exception) {
            self::assertSame('article_promotion_foreign_working_revision', $exception->getMessage());
        }
    }

    public function test_w3_article_rollback_refuses_discoverability_drift_and_translation_revisions_bind_the_canonical_source(): void
    {
        $canonical = $this->article('canonical-article');
        $canonical->forceFill(['locale' => 'zh'])->save();
        $translated = $this->article('translated-article');
        $translated->forceFill([
            'translation_status' => Article::TRANSLATION_STATUS_APPROVED,
            'source_article_id' => $canonical->id,
            'translated_from_article_id' => $canonical->id,
            'source_locale' => 'zh',
        ])->saveQuietly();
        $context = $this->context($this->package([$translated]), 1);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'W3-ARTICLES');
        $adapter->draftImport($context);
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $translated->id)->latest('id')->firstOrFail();
        self::assertSame($canonical->refresh()->source_version_hash, $revision->source_version_hash);
        $published = $adapter->publish($context);
        $translated->forceFill(['is_indexable' => true])->save();
        try {
            $adapter->rollback($context, (string) $published['rollback_reference']);
            self::fail('Rollback must reject an intervening discoverability change.');
        } catch (\DomainException $exception) {
            self::assertSame('article_promotion_rollback_public_projection_drift', $exception->getMessage());
        }
        self::assertTrue((bool) $translated->refresh()->is_indexable);
    }

    public function test_w3_article_publish_refuses_a_concurrent_seo_row_change(): void
    {
        $article = $this->article('seo-race-article');
        $context = $this->context($this->package([$article]), 1);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'W3-ARTICLES');
        $adapter->draftImport($context);
        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'article_id' => $article->id,
            'seo_title' => 'Concurrent SEO',
            'seo_description' => 'Concurrent description',
        ]);
        try {
            app(ArticleCmsPromotionAuthority::class)->publish($context, [[
                'asset_key' => '0:en:seo-race-article',
                'package_sha256' => $context->packageSha256,
                'seo_before' => [],
            ]]);
            self::fail('Publication must reject SEO state changed after the snapshot.');
        } catch (\DomainException $exception) {
            self::assertSame('article_promotion_seo_precondition_drift', $exception->getMessage());
        }
    }

    public function test_w3_article_publish_refuses_source_hash_input_drift_after_draft_import(): void
    {
        $article = $this->article('source-hash-race-article');
        $context = $this->context($this->package([$article]), 1);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'W3-ARTICLES');
        $adapter->draftImport($context);
        $article->forceFill(['voice' => 'concurrent-voice'])->save();
        try {
            $adapter->publish($context);
            self::fail('Publication must reject source-hash input drift after draft import.');
        } catch (\DomainException $exception) {
            self::assertSame('article_promotion_working_revision_invalid', $exception->getMessage());
        }
    }

    public function test_w3_article_publish_refuses_canonical_source_drift_after_draft_import(): void
    {
        $canonical = $this->article('canonical-source-race');
        $canonical->forceFill(['locale' => 'zh'])->save();
        $translated = $this->article('translated-source-race');
        $translated->forceFill([
            'translation_status' => Article::TRANSLATION_STATUS_APPROVED,
            'source_article_id' => $canonical->id,
            'translated_from_article_id' => $canonical->id,
            'source_locale' => 'zh',
        ])->saveQuietly();
        $context = $this->context($this->package([$translated]), 1);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'W3-ARTICLES');
        $adapter->draftImport($context);
        $canonical->forceFill(['voice' => 'changed-source-voice'])->save();
        try {
            $adapter->publish($context);
            self::fail('Publication must reject canonical source drift after draft import.');
        } catch (\DomainException $exception) {
            self::assertSame('article_promotion_translation_source_drift', $exception->getMessage());
        }
    }

    public function test_frozen_w3_articles_external_package_creates_candidate_only_english_authority_and_restores_its_private_draft(): void
    {
        $backendRoot = dirname(__DIR__, 3);
        $packageDirectory = $backendRoot.'/content_assets/en-content-parity/W3/articles/d70e468b';
        config(['content_promotion.w9_authority_root' => $backendRoot.'/content_assets/en-content-parity/W9']);
        $ledger = json_decode((string) File::get($packageDirectory.'/frozen_package/source_ledger.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ((array) $ledger['rows'] as $row) {
            $source = Article::query()->withoutGlobalScopes()->forceCreate([
                'id' => (int) $row['source_article_id'], 'org_id' => 0, 'slug' => (string) $row['slug'], 'locale' => 'zh-CN',
                'translation_group_id' => (string) $row['translation_pair_identity'], 'source_locale' => 'zh-CN',
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE, 'title' => 'Frozen source '.(int) $row['source_article_id'],
                'excerpt' => 'Source excerpt', 'content_md' => 'Source body', 'status' => 'published', 'is_public' => true,
                'is_indexable' => true, 'sitemap_eligible' => true, 'llms_eligible' => true, 'published_at' => now(),
            ]);
            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->forceCreate([
                'id' => (int) $row['source_revision_id'], 'org_id' => 0, 'article_id' => $source->id, 'source_article_id' => $source->id,
                'translation_group_id' => $source->translation_group_id, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN',
                'revision_number' => 1, 'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'source_version_hash' => $source->source_version_hash, 'translated_from_version_hash' => $source->source_version_hash,
                'title' => $source->title, 'excerpt' => $source->excerpt, 'content_md' => $source->content_md, 'published_at' => now(),
            ]);
            $source->forceFill(['published_revision_id' => $revision->id])->saveQuietly();
        }
        $context = new PromotionContext(
            $packageDirectory,
            'd70e468bb1a07d74e786e5a93b5279feff5347be49a0264916408a6b2ccbdc9a',
            'W3', 'W3-ARTICLES', str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '1', 1, str_repeat('d', 64), 17, str_repeat('e', 64),
        );
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'W3-ARTICLES');

        self::assertSame(17, $adapter->preflight($context)['readback_count']);
        self::assertSame(17, $adapter->draftImport($context)['created_count']);
        self::assertSame(17, Article::query()->withoutGlobalScopes()->where('locale', 'en')->where('is_public', false)->count());
        self::assertSame(0, Article::query()->withoutGlobalScopes()->where('locale', 'en')->where('is_indexable', true)->count());
        $published = $adapter->publish($context);
        self::assertSame(17, $published['published_count']);
        self::assertSame(17, $adapter->liveQa($context)['published_count']);
        try {
            $adapter->draftImport($context);
            self::fail('A published exact revision must not be reported as a new draft import.');
        } catch (\DomainException $exception) {
            self::assertSame('article_promotion_draft_already_published', $exception->getMessage());
        }
        self::assertSame(17, Article::query()->withoutGlobalScopes()->where('locale', 'en')->where('status', 'published')->where('is_public', true)->count());
        self::assertSame(0, Article::query()->withoutGlobalScopes()->where('locale', 'en')->where('is_indexable', true)->count());

        Cache::put(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':previous-generation', 'published-candidate-generation', ArticlePublicListReadCache::LKG_TTL_SECONDS);
        Article::query()->withoutGlobalScopes()->findOrFail(1)->forceFill(['title' => 'Changed after English publication'])->saveQuietly();
        $adapter->rollback($context, (string) $published['rollback_reference']);
        self::assertNull(Cache::get(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':previous-generation'));
        self::assertSame(17, Article::query()->withoutGlobalScopes()->where('locale', 'en')->where('status', 'draft')->where('is_public', false)->count());
        self::assertSame(17, Article::query()->withoutGlobalScopes()->where('locale', 'en')->where('translation_status', Article::TRANSLATION_STATUS_APPROVED)->count());
        self::assertSame(17, ArticleTranslationRevision::query()->withoutGlobalScopes()->where('authority_package_sha256', $context->packageSha256)->where('revision_status', ArticleTranslationRevision::STATUS_APPROVED)->count());
    }

    private function article(string $slug): Article
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'slug' => $slug, 'locale' => 'en', 'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Original '.$slug, 'excerpt' => 'Original excerpt', 'content_md' => 'Original body', 'content_html' => '<p>Original body</p>', 'status' => 'published', 'is_public' => true,
            'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false, 'published_at' => now(),
        ]);
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create($this->revisionPayload($article, 'original', ArticleTranslationRevision::STATUS_PUBLISHED));
        $article->forceFill(['published_revision_id' => $revision->id])->saveQuietly();

        return $article;
    }

    /** @param list<Article> $articles @param array<string,mixed> $snapshotOverrides @param array<string,mixed> $rowOverrides */
    private function package(array $articles, array $snapshotOverrides = [], array $rowOverrides = [], bool $w9 = true): string
    {
        $directory = base_path('content_assets/en-content-parity/t4-articles-test-'.bin2hex(random_bytes(6)));
        $w9Directory = $directory.'/w9';
        File::ensureDirectoryExists($w9Directory);
        $this->directories[] = $directory;
        $rows = array_map(function (Article $article) use ($snapshotOverrides, $rowOverrides): array {
            return array_replace(['identity' => ['org_id' => 0, 'slug' => $article->slug, 'locale' => 'en'], 'snapshot' => array_replace(['title' => 'Promoted '.$article->slug, 'excerpt' => 'Promoted excerpt', 'content_md' => 'Promoted English body.', 'seo_title' => 'Promoted SEO', 'seo_description' => 'Promoted description'], $snapshotOverrides)], $rowOverrides);
        }, $articles);
        $bytes = json_encode(['assets' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        File::put($directory.'/assets.json', $bytes);
        $manifest = ['schema_version' => 'fermatmind.article_cms_promotion.v2', 'lane' => 'W3', 'subscope' => 'articles', 'locale' => 'en', 'permissions' => ['cms_draft_import' => false, 'public_publish' => false, 'indexability' => false, 'sitemap' => false, 'llms' => false, 'search' => false, 'deploy' => false], 'expected_row_count' => count($rows), 'payloads' => [['path' => 'assets.json', 'sha256' => hash('sha256', $bytes)]]];
        $chainManifest = $manifest;
        $packageSha = hash('sha256', hash('sha256', PromotionContextFactory::canonicalJson($chainManifest))."\nassets.json\n".hash('sha256', $bytes)."\n");
        if ($w9) {
            $report = ['schema_version' => 'fermatmind.en_parity.independent_w9_report.v1', 'review_kind' => 'independent_w9', 'verdict' => 'PASS', 'package_sha256' => $packageSha, 'lane_id' => 'W3', 'subscope' => 'articles', 'reviewed_row_count' => count($rows)];
            $reportBytes = json_encode($report, JSON_THROW_ON_ERROR);
            File::put($w9Directory.'/report.json', $reportBytes);
            $manifest['quality_gates'] = ['independent_w9' => ['status' => 'pass', 'report_ref' => 'report.json', 'report_sha256' => hash('sha256', $reportBytes)]];
            $report['package_sha256'] = $packageSha;
            $reportBytes = json_encode($report, JSON_THROW_ON_ERROR);
            File::put($w9Directory.'/report.json', $reportBytes);
            $manifest['quality_gates']['independent_w9']['report_sha256'] = hash('sha256', $reportBytes);
        }
        $manifest['package_sha256'] = $packageSha;
        File::put($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        config(['content_promotion.w9_authority_root' => $w9Directory]);

        return $directory;
    }

    private function context(string $directory, int $rows): PromotionContext
    {
        $manifest = json_decode((string) File::get($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        return new PromotionContext($directory, $manifest['package_sha256'], 'W3', 'W3-ARTICLES', str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '1', 1, str_repeat('d', 64), $rows, hash('sha256', $directory));
    }

    /** @return array<string,mixed> */
    private function revisionPayload(Article $article, string $suffix, string $status): array
    {
        return ['org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id, 'translation_group_id' => $article->translation_group_id, 'locale' => 'en', 'source_locale' => 'en', 'revision_number' => ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number')) + 1, 'revision_status' => $status, 'source_version_hash' => $article->source_version_hash, 'title' => $status === ArticleTranslationRevision::STATUS_PUBLISHED ? 'Original '.$article->slug : $suffix, 'excerpt' => 'Original excerpt', 'content_md' => 'Original body', 'seo_title' => 'Original SEO', 'seo_description' => 'Original description', 'published_at' => $status === ArticleTranslationRevision::STATUS_PUBLISHED ? now() : null];
    }
}
