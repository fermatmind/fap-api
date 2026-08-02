<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticlePublicListReadCache;
use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        $package = $this->package([$first, $second]);
        $context = $this->context($package, 2);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'articles');
        self::assertSame('audit_compatible', $adapter->capability());
        self::assertSame(2, $adapter->preflight($context)['readback_count']);
        self::assertSame(2, $adapter->draftImport($context)['created_count']);
        self::assertSame(0, $adapter->draftImport($context)['created_count']);
        self::assertSame(2, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
        Cache::put(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation', 'before-publish', 600);
        $published = $adapter->publish($context);
        self::assertSame(2, $published['published_count']);
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
        self::assertNotSame('before-publish', Cache::get(ArticlePublicListReadCache::CACHE_KEY_PREFIX.':generation'));
        self::assertFalse((bool) $first->is_indexable);
        self::assertFalse((bool) $first->sitemap_eligible);
        self::assertFalse((bool) $first->llms_eligible);
        $adapter->rollback($context, (string) $published['rollback_reference']);
        self::assertSame('Original first-article', $first->refresh()->title);
        self::assertSame('<p>Original body</p>', $first->content_html);
        self::assertSame('Original second-article', $second->refresh()->title);
        self::assertSame($firstSourceHash, $first->source_version_hash);
        self::assertSame('Original SEO', $firstSeo->refresh()->seo_title);
        self::assertSame('Original OG', $firstSeo->refresh()->og_title);
        self::assertNotNull($first->working_revision_id);
        self::assertSame(ArticleTranslationRevision::STATUS_APPROVED, ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail($first->working_revision_id)->revision_status);
    }

    public function test_w3_article_package_fails_closed_on_cjk_private_payload_w9_and_foreign_working_revision(): void
    {
        $article = $this->article('bounded-article');
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'articles');
        foreach ([
            ['snapshot' => ['title' => '中文'], 'error' => 'article_promotion_cjk_leakage'],
            ['snapshot' => ['seo_title' => '   '], 'error' => 'article_promotion_snapshot_invalid'],
            ['snapshot' => ['content_md' => '# Heading'], 'error' => 'article_promotion_body_h1_invalid'],
            ['snapshot' => ['content_md' => 'See /checkout?token=private'], 'error' => 'article_promotion_private_payload_invalid'],
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
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W3', 'articles');
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

        return new PromotionContext($directory, $manifest['package_sha256'], 'W3', 'articles', str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '1', 1, str_repeat('d', 64), $rows, hash('sha256', $directory));
    }

    /** @return array<string,mixed> */
    private function revisionPayload(Article $article, string $suffix, string $status): array
    {
        return ['org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id, 'translation_group_id' => $article->translation_group_id, 'locale' => 'en', 'source_locale' => 'en', 'revision_number' => ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->max('revision_number')) + 1, 'revision_status' => $status, 'source_version_hash' => $article->source_version_hash, 'title' => $status === ArticleTranslationRevision::STATUS_PUBLISHED ? 'Original '.$article->slug : $suffix, 'excerpt' => 'Original excerpt', 'content_md' => 'Original body', 'seo_title' => 'Original SEO', 'seo_description' => 'Original description', 'published_at' => $status === ArticleTranslationRevision::STATUS_PUBLISHED ? now() : null];
    }
}
