<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleEditorialCompletenessGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class Seo13ArticleRefreshPackageTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_ROOT = 'docs/seo/import-packages/seo-13-article-refresh-2026-07-26';

    public function test_exact_thirteen_article_cohort_passes_existing_article_update_dry_run(): void
    {
        $cohort = $this->cohort();
        $articles = $cohort['articles'] ?? [];

        $this->assertSame(13, $cohort['target_count'] ?? null);
        $this->assertCount(13, $articles);
        $this->assertCount(13, array_unique(array_column($articles, 'id')));
        $this->assertCount(13, array_unique(array_column($articles, 'slug')));
        $this->assertCount(13, array_unique(array_column($articles, 'query_owner')));
        $this->assertSame('content_and_existing_seo_fields_only', $cohort['publication_scope'] ?? null);
        $this->assertTrue((bool) data_get($cohort, 'downstream_holds.schema'));
        $this->assertTrue((bool) data_get($cohort, 'downstream_holds.hreflang'));
        $this->assertTrue((bool) data_get($cohort, 'downstream_holds.search_submission'));
        $this->assertTrue((bool) data_get($cohort, 'downstream_holds.revalidation'));
        $this->assertTrue((bool) data_get($cohort, 'downstream_holds.sitemap_change'));
        $this->assertTrue((bool) data_get($cohort, 'downstream_holds.llms_change'));

        foreach ($articles as $item) {
            $this->assertIsArray($item);
            $article = $this->createExistingPublishedArticle($item);
            $slug = (string) $item['slug'];
            $canonical = '/zh/articles/'.$slug;

            $exitCode = Artisan::call('articles:update-existing-seo-content-package', [
                '--package' => base_path(self::PACKAGE_ROOT.'/'.$slug),
                '--article-id' => (int) $item['id'],
                '--translation-group-id' => (string) $item['translation_group_id'],
                '--locale' => 'zh-CN',
                '--expected-slug' => $slug,
                '--expected-canonical' => 'https://fermatmind.com'.$canonical,
                '--dry-run' => true,
                '--json' => true,
                '--slug-lock' => true,
                '--canonical-lock' => true,
                '--schema-hold' => true,
                '--hreflang-hold' => true,
                '--search-hold' => true,
                '--no-revalidation' => true,
                '--no-sitemap' => true,
                '--no-llms' => true,
            ]);

            $payload = json_decode(Artisan::output(), true);
            $this->assertSame(0, $exitCode, Artisan::output());
            $this->assertIsArray($payload, Artisan::output());
            $this->assertTrue((bool) ($payload['ok'] ?? false), Artisan::output());
            $this->assertSame('would_update_existing_working_revision', $payload['action'] ?? null);
            $this->assertSame((int) $article->id, $payload['article_id'] ?? null);
            $this->assertSame($slug, $payload['slug_lock'] ?? null);
            $this->assertSame('passed', data_get($payload, 'active_surface_guard_scan.status'));
            $this->assertSame([], $payload['errors'] ?? null);
            $this->assertFalse((bool) data_get($payload, 'safety_flags.search_submission_allowed', false));

            $article->refresh();
            $this->assertSame($article->published_revision_id, $article->working_revision_id);
        }
    }

    public function test_reader_facing_copy_passes_completeness_and_template_quality_guards(): void
    {
        $gate = app(ArticleEditorialCompletenessGate::class);

        foreach ($this->cohort()['articles'] as $item) {
            $slug = (string) $item['slug'];
            $page = (string) file_get_contents(base_path(
                self::PACKAGE_ROOT.'/'.$slug.'/pages/zh-CN-'.$slug.'.md'
            ));
            $body = preg_replace('/\A---\n.*?\n---\n/s', '', $page) ?? $page;
            $result = $gate->inspect('zh-CN', $body, [
                'title' => (string) $item['title'],
                'excerpt' => (string) $item['excerpt'],
                'content_md' => $body,
                'seo_title' => (string) $item['meta_title'],
                'seo_description' => (string) $item['meta_description'],
            ]);

            $this->assertTrue((bool) $result['ok'], $slug.': '.json_encode($result['issues']));
            $this->assertGreaterThanOrEqual(2000, (int) $result['actual_han_characters'], $slug);
            $this->assertStringContainsString('## 快速答案', $body, $slug);
            $this->assertStringContainsString('## 常见问题', $body, $slug);
            $this->assertStringContainsString('## 参考来源', $body, $slug);
            $this->assertGreaterThanOrEqual(4, substr_count($body, '### '), $slug);
            $this->assertStringNotContainsString('# '.$item['title'], $body, $slug);
            $this->assertStringNotContainsString('Evidence Note**', $body, $slug);
            $this->assertStringNotContainsString('什么时候适合阅读这篇文章？', $body, $slug);
            $this->assertStringNotContainsString('这篇文章会替代正式判断吗？', $body, $slug);
        }
    }

    public function test_execute_isolates_all_working_revisions_without_changing_public_state(): void
    {
        foreach ($this->cohort()['articles'] as $item) {
            $article = $this->createExistingPublishedArticle($item);
            $publishedRevisionId = (int) $article->published_revision_id;
            $slug = (string) $item['slug'];

            $exitCode = Artisan::call('articles:update-existing-seo-content-package', [
                '--package' => base_path(self::PACKAGE_ROOT.'/'.$slug),
                '--article-id' => (int) $item['id'],
                '--translation-group-id' => (string) $item['translation_group_id'],
                '--locale' => 'zh-CN',
                '--expected-slug' => $slug,
                '--expected-canonical' => 'https://fermatmind.com/zh/articles/'.$slug,
                '--execute' => true,
                '--json' => true,
                '--slug-lock' => true,
                '--canonical-lock' => true,
                '--schema-hold' => true,
                '--hreflang-hold' => true,
                '--search-hold' => true,
                '--no-revalidation' => true,
                '--no-sitemap' => true,
                '--no-llms' => true,
            ]);

            $this->assertSame(0, $exitCode, Artisan::output());

            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['workingRevision', 'publishedRevision', 'seoMeta'])
                ->findOrFail((int) $item['id']);
            $this->assertSame($publishedRevisionId, (int) $article->published_revision_id, $slug);
            $this->assertNotSame($publishedRevisionId, (int) $article->working_revision_id, $slug);
            $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, $article->publishedRevision?->revision_status);
            $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $article->workingRevision?->revision_status);
            $this->assertSame((string) $item['title'], (string) $article->workingRevision?->title);
            $this->assertSame((string) $item['meta_title'], (string) $article->workingRevision?->seo_title);
            $this->assertSame((string) $item['meta_description'], (string) $article->workingRevision?->seo_description);
            $this->assertSame('published', (string) $article->status);
            $this->assertTrue((bool) $article->is_public);
            $this->assertTrue((bool) $article->is_indexable);
            $this->assertTrue((bool) $article->sitemap_eligible);
            $this->assertTrue((bool) $article->llms_eligible);

            $import = ArticleEditorialPackageImport::query()
                ->withoutGlobalScopes()
                ->where('article_id', (int) $article->id)
                ->latest('id')
                ->firstOrFail();
            $this->assertSame('seo_content_package_existing_article_update', $import->content_track);
            $this->assertSame('human_review', data_get($import->claim_result_json, 'status'));
            $this->assertSame('passed', data_get($import->exactness_json, 'status'));
            $this->assertSame('unchanged_hold', data_get($import->media_json, 'status'));
            $this->assertSame('unchanged_hold', data_get($import->graph_json, 'status'));
            $this->assertSame('visible_only', data_get($import->answer_surface_json, 'status'));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function cohort(): array
    {
        $cohort = json_decode(
            (string) file_get_contents(base_path(self::PACKAGE_ROOT.'/cohort.json')),
            true
        );
        $this->assertIsArray($cohort);

        return $cohort;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function createExistingPublishedArticle(array $item): Article
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'seo-article-refresh'],
            ['name' => 'SEO Article Refresh', 'is_active' => true]
        );
        $id = (int) $item['id'];
        $slug = (string) $item['slug'];
        $translationGroupId = (string) $item['translation_group_id'];
        $publishedBody = "## Existing published article\n\n现有公开正文。";

        $article = Article::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create([
            'id' => $id,
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'translation_group_id' => $translationGroupId,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Existing '.$slug,
            'excerpt' => 'Existing excerpt',
            'content_md' => $publishedBody,
            'cover_image_url' => 'https://api.fermatmind.com/static/articles/covers/'.$slug.'.svg',
            'cover_image_alt' => 'Existing article cover',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subDay(),
        ]));

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => $id,
            'source_article_id' => $id,
            'translation_group_id' => $translationGroupId,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Existing '.$slug,
            'excerpt' => 'Existing excerpt',
            'content_md' => $publishedBody,
            'seo_title' => 'Existing SEO title',
            'seo_description' => 'Existing SEO description',
            'published_at' => now()->subDay(),
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => $id,
            'locale' => 'zh-CN',
            'seo_title' => 'Existing SEO title',
            'seo_description' => 'Existing SEO description',
            'canonical_url' => 'https://fermatmind.com/zh/articles/'.$slug,
            'og_title' => 'Existing OG title',
            'og_description' => 'Existing OG description',
            'og_image_url' => 'https://api.fermatmind.com/static/articles/covers/'.$slug.'.svg',
            'robots' => 'index,follow',
            'schema_json' => ['status' => 'existing_public_schema'],
            'is_indexable' => true,
        ]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']) ?? $article;
    }
}
