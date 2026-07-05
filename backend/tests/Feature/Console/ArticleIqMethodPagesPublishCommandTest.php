<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ArticleIqMethodPagesPublish;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class ArticleIqMethodPagesPublishCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(ArticleIqMethodPagesPublish::class));
    }

    public function test_publish_dry_run_passes_without_publishing(): void
    {
        $locks = $this->createApprovedIqMethodArticles();

        [$exit, $payload] = $this->callPublish([
            '--article-lock' => $locks,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['ok']);
        $this->assertSame('dry_run_pass', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['execute']);
        $this->assertCount(7, $payload['articles']);
        $this->assertSame([], $payload['published_articles']);
        $this->assertFalse($payload['side_effects']['db_write']);
        $this->assertFalse($payload['side_effects']['publish']);
        $this->assertFalse($payload['side_effects']['indexability']);
        $this->assertFalse($payload['side_effects']['sitemap']);
        $this->assertFalse($payload['side_effects']['llms']);

        $article = Article::query()->withoutGlobalScopes()->where('slug', 'what-is-iq-style-reasoning-test')->firstOrFail();
        $this->assertSame('review_pending', $article->status);
        $this->assertFalse((bool) $article->is_public);
        $this->assertNull($article->published_revision_id);
    }

    public function test_publish_execute_requires_exact_confirmation(): void
    {
        $locks = $this->createApprovedIqMethodArticles();

        [$exit, $payload] = $this->callPublish([
            '--article-lock' => $locks,
            '--confirm' => 'wrong confirmation',
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('confirmation_mismatch', collect($payload['issues'])->pluck('code')->all());
        $this->assertFalse($payload['side_effects']['db_write']);

        $article = Article::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('review_pending', $article->status);
        $this->assertFalse((bool) $article->is_public);
        $this->assertNull($article->published_revision_id);
    }

    public function test_publish_execute_publishes_articles_but_keeps_noindex_sitemap_and_llms_disabled(): void
    {
        $locks = $this->createApprovedIqMethodArticles();

        [$dryRunExit, $dryRunPayload] = $this->callPublish([
            '--article-lock' => $locks,
            '--json' => true,
        ]);
        $this->assertSame(0, $dryRunExit);

        [$exit, $payload] = $this->callPublish([
            '--article-lock' => $locks,
            '--confirm' => $dryRunPayload['expected_confirmation'],
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['ok']);
        $this->assertSame('published_noindex', $payload['status']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['execute']);
        $this->assertCount(7, $payload['published_articles']);
        $this->assertTrue($payload['side_effects']['db_write']);
        $this->assertTrue($payload['side_effects']['publish']);
        $this->assertFalse($payload['side_effects']['indexability']);
        $this->assertFalse($payload['side_effects']['sitemap']);
        $this->assertFalse($payload['side_effects']['llms']);
        $this->assertFalse($payload['side_effects']['search']);
        $this->assertFalse($payload['side_effects']['deploy']);

        $articles = Article::query()->withoutGlobalScopes()->orderBy('slug')->get();
        $this->assertCount(7, $articles);

        foreach ($articles as $article) {
            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey($article->published_revision_id)->firstOrFail();
            $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $article->id)->firstOrFail();

            $this->assertSame('published', $article->status);
            $this->assertTrue((bool) $article->is_public);
            $this->assertFalse((bool) $article->is_indexable);
            $this->assertFalse((bool) $article->sitemap_eligible);
            $this->assertFalse((bool) $article->llms_eligible);
            $this->assertNotNull($article->published_at);
            $this->assertSame((int) $article->working_revision_id, (int) $article->published_revision_id);
            $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, $revision->revision_status);
            $this->assertNotNull($revision->published_at);
            $this->assertSame('noindex,follow', $seo->robots);
            $this->assertFalse((bool) $seo->is_indexable);
            $this->assertSame(
                'IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-01',
                data_get($seo->schema_json, 'editorial_package_v1.publish_v1.pr_id')
            );
            $this->assertFalse((bool) data_get($seo->schema_json, 'editorial_package_v1.publish_v1.indexability_allowed'));
            $this->assertFalse((bool) data_get($seo->schema_json, 'editorial_package_v1.publish_v1.sitemap_llms_allowed'));
        }
    }

    public function test_publish_blocks_when_discoverability_is_already_enabled(): void
    {
        $locks = $this->createApprovedIqMethodArticles();

        Article::query()
            ->withoutGlobalScopes()
            ->where('slug', 'what-is-iq-style-reasoning-test')
            ->update(['is_indexable' => true]);

        [$exit, $payload] = $this->callPublish([
            '--article-lock' => $locks,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('discoverability_not_disabled', collect($payload['issues'])->pluck('code')->all());
    }

    /**
     * @return list<string>
     */
    private function createApprovedIqMethodArticles(): array
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'iq-method-boundary'],
            ['name' => 'IQ 方法与边界', 'is_active' => true]
        );
        $tag = ArticleTag::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'iq-method-pages'],
            ['name' => 'IQ 方法页', 'is_active' => true]
        );
        $locks = [];

        foreach ($this->pageDefinitions() as $index => $page) {
            $body = "IQ 风格推理测试说明正文。\n\n## 方法边界\n\n非官方、非临床、非认证。\n\n## FAQ\n\n### 这是正式智力测评吗？\n\n不是。";
            $article = Article::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'category_id' => (int) $category->id,
                'slug' => $page['slug'],
                'locale' => 'zh-CN',
                'title' => $page['title'],
                'excerpt' => 'IQ 方法页摘要',
                'content_md' => $body,
                'cover_image_url' => '/storage/articles/iq-method.svg',
                'cover_image_alt' => 'IQ 方法页封面',
                'cover_image_width' => 1200,
                'cover_image_height' => 675,
                'related_test_slug' => 'iq-test-intelligence-quotient-assessment',
                'status' => 'review_pending',
                'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
                'is_public' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
            ]);
            $article->tags()->attach((int) $tag->id, ['org_id' => 0]);

            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'article_id' => (int) $article->id,
                'source_article_id' => (int) $article->id,
                'translation_group_id' => (string) $article->translation_group_id,
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'revision_number' => $index + 1,
                'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                'title' => $page['title'],
                'excerpt' => 'IQ 方法页摘要',
                'content_md' => $body,
                'seo_title' => $page['title'].' | 费马测试',
                'seo_description' => 'IQ 方法页 SEO 描述',
                'reviewed_by' => 42,
                'reviewed_at' => now()->subMinutes(10),
                'approved_at' => now()->subMinutes(5),
            ]);
            $article->forceFill(['working_revision_id' => (int) $revision->id])->save();

            ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'article_id' => (int) $article->id,
                'locale' => 'zh-CN',
                'seo_title' => $page['title'].' | 费马测试',
                'seo_description' => 'IQ 方法页 SEO 描述',
                'canonical_url' => 'https://fermatmind.com/zh/articles/'.$page['slug'],
                'og_title' => $page['title'].' | 费马测试',
                'og_description' => 'IQ 方法页 OG 描述',
                'og_image_url' => 'https://fermatmind.com/storage/articles/iq-method.svg',
                'robots' => 'noindex,follow',
                'schema_json' => [
                    'editorial_package_v1' => [
                        'review_approval_v1' => [
                            'pr_id' => 'IQ-METHOD-PAGES-ZH-CN-CMS-REVIEW-APPROVAL-01',
                            'publish_allowed' => false,
                            'indexability_allowed' => false,
                            'sitemap_llms_allowed' => false,
                        ],
                    ],
                ],
                'is_indexable' => false,
            ]);

            $locks[] = $page['slug'].':'.$article->id.':'.$revision->id;
        }

        return $locks;
    }

    /**
     * @return list<array{slug:string,title:string}>
     */
    private function pageDefinitions(): array
    {
        return [
            ['slug' => 'what-is-iq-style-reasoning-test', 'title' => '什么是 IQ 风格推理测试？'],
            ['slug' => 'online-iq-test-vs-professional-assessment', 'title' => '在线 IQ 风格测试和专业智力测评有什么区别？'],
            ['slug' => 'iq-test-score-meaning-boundary', 'title' => 'IQ 风格测试里的原始分、正确率和完成时间说明什么？'],
            ['slug' => 'matrix-reasoning-pattern-recognition-guide', 'title' => '矩阵推理和模式识别题在测什么？'],
            ['slug' => 'why-fermatmind-iq-v1-not-certification', 'title' => '为什么 FermatMind IQ V1 是非认证测试？'],
            ['slug' => 'iq-test-privacy-data-boundary', 'title' => 'IQ 风格测试的数据和隐私边界是什么？'],
            ['slug' => 'iq-expert-review-disclosure', 'title' => 'FermatMind 如何审查 IQ 风格测试内容？'],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{0:int,1:array<string,mixed>}
     */
    private function callPublish(array $options): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('articles:iq-method-pages-publish', $options, $output);
        $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return [$exit, $decoded];
    }
}
