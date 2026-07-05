<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ArticleIqMethodPagesPostPublishReadback;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PageBlock;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class ArticleIqMethodPagesPostPublishReadbackCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(ArticleIqMethodPagesPostPublishReadback::class));
    }

    public function test_post_publish_readback_passes_for_published_noindex_iq_method_articles(): void
    {
        $this->createPublishedIqMethodArticles();
        $this->createTopicAndLandingLinks();

        [$exit, $payload] = $this->callReadback();

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['execute']);
        $this->assertSame(0, $payload['mismatch_count']);
        $this->assertCount(7, $payload['article_readbacks']);
        $this->assertSame(7, $payload['topic_readback']['actual_items_count']);
        $this->assertSame(7, $payload['landing_readback']['actual_items_count']);
        $this->assertFalse($payload['side_effects']['db_write']);
        $this->assertFalse($payload['side_effects']['indexability']);
        $this->assertFalse($payload['side_effects']['sitemap']);
        $this->assertFalse($payload['side_effects']['llms']);
    }

    public function test_post_publish_readback_blocks_if_article_is_prematurely_indexable(): void
    {
        $this->createPublishedIqMethodArticles();
        $this->createTopicAndLandingLinks();

        Article::query()->withoutGlobalScopes()
            ->where('slug', 'what-is-iq-style-reasoning-test')
            ->update(['is_indexable' => true, 'sitemap_eligible' => true]);

        [$exit, $payload] = $this->callReadback();

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('value_mismatch', collect($payload['issues'])->pluck('code')->all());
        $this->assertContains('what-is-iq-style-reasoning-test.is_indexable', collect($payload['issues'])->pluck('field')->all());
        $this->assertContains('what-is-iq-style-reasoning-test.sitemap_eligible', collect($payload['issues'])->pluck('field')->all());
    }

    public function test_post_publish_readback_blocks_private_or_scoring_token_leaks(): void
    {
        $this->createPublishedIqMethodArticles();
        $this->createTopicAndLandingLinks();

        Article::query()->withoutGlobalScopes()
            ->where('slug', 'iq-test-privacy-data-boundary')
            ->update(['content_md' => '公开页不应包含 answer_key 字段。']);

        [$exit, $payload] = $this->callReadback();

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertContains('private_or_scoring_token_leak', collect($payload['issues'])->pluck('code')->all());
    }

    private function createPublishedIqMethodArticles(): void
    {
        foreach ($this->slugs() as $index => $slug) {
            $body = "IQ 方法页正文。\n\n## 方法边界\n\n非官方、非临床、非认证。";
            $article = Article::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'slug' => $slug,
                'locale' => 'zh-CN',
                'title' => 'IQ 方法页 '.$index,
                'excerpt' => 'IQ 方法页摘要',
                'content_md' => $body,
                'related_test_slug' => 'iq-test-intelligence-quotient-assessment',
                'status' => 'published',
                'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
                'is_public' => true,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'published_at' => now()->subMinute(),
            ]);

            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'article_id' => (int) $article->id,
                'source_article_id' => (int) $article->id,
                'translation_group_id' => (string) $article->translation_group_id,
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'revision_number' => $index + 1,
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'title' => 'IQ 方法页 '.$index,
                'excerpt' => 'IQ 方法页摘要',
                'content_md' => $body,
                'reviewed_by' => 42,
                'reviewed_at' => now()->subMinutes(10),
                'approved_at' => now()->subMinutes(5),
                'published_at' => now()->subMinute(),
            ]);
            $article->forceFill([
                'working_revision_id' => (int) $revision->id,
                'published_revision_id' => (int) $revision->id,
            ])->save();

            ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'article_id' => (int) $article->id,
                'locale' => 'zh-CN',
                'seo_title' => 'IQ 方法页 '.$index,
                'seo_description' => 'IQ 方法页 SEO 描述',
                'canonical_url' => 'https://fermatmind.com/zh/articles/'.$slug,
                'og_title' => 'IQ 方法页 '.$index,
                'og_description' => 'IQ 方法页 OG 描述',
                'og_image_url' => 'https://fermatmind.com/storage/articles/iq-method.svg',
                'robots' => 'noindex,follow',
                'schema_json' => [
                    'editorial_package_v1' => [
                        'publish_v1' => [
                            'pr_id' => 'IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-01',
                            'indexability_allowed' => false,
                            'sitemap_llms_allowed' => false,
                        ],
                    ],
                ],
                'is_indexable' => false,
            ]);
        }
    }

    private function createTopicAndLandingLinks(): void
    {
        $profile = TopicProfile::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'topic_code' => 'iq-eq',
            'slug' => 'iq-eq',
            'locale' => 'zh-CN',
            'title' => 'IQ 与 EQ 主题内容聚合',
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
        ]);

        foreach ($this->slugs() as $index => $slug) {
            TopicProfileEntry::query()->create([
                'profile_id' => (int) $profile->id,
                'entry_type' => 'article',
                'group_key' => 'iq_articles',
                'target_key' => $slug,
                'target_locale' => 'zh-CN',
                'target_url_override' => '/zh/articles/'.$slug,
                'payload_json' => ['slug' => $slug],
                'sort_order' => $index + 1,
                'is_enabled' => true,
            ]);
        }

        $surface = LandingSurface::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'surface_key' => 'test:iq-test-intelligence-quotient-assessment',
            'locale' => 'zh-CN',
            'title' => 'IQ 测试 landing',
            'status' => LandingSurface::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
        ]);

        PageBlock::query()->create([
            'landing_surface_id' => (int) $surface->id,
            'block_key' => 'iq_methodology_boundary_links',
            'block_type' => 'article_link_cluster',
            'title' => 'IQ 测试方法与边界',
            'payload_json' => [
                'frontend_hardcode_allowed' => false,
                'items' => collect($this->slugs())
                    ->map(static fn (string $slug): array => ['slug' => $slug, 'href' => '/zh/articles/'.$slug])
                    ->all(),
            ],
            'sort_order' => 1,
            'is_enabled' => true,
        ]);
    }

    /**
     * @return list<string>
     */
    private function slugs(): array
    {
        return [
            'what-is-iq-style-reasoning-test',
            'online-iq-test-vs-professional-assessment',
            'iq-test-score-meaning-boundary',
            'matrix-reasoning-pattern-recognition-guide',
            'why-fermatmind-iq-v1-not-certification',
            'iq-test-privacy-data-boundary',
            'iq-expert-review-disclosure',
        ];
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    private function callReadback(): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('articles:iq-method-pages-post-publish-readback', ['--json' => true], $output);
        $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return [$exit, $decoded];
    }
}
