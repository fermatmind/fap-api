<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ArticleIqMethodPagesSeoGeoActivationGate;
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

final class ArticleIqMethodPagesSeoGeoActivationGateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(ArticleIqMethodPagesSeoGeoActivationGate::class));
    }

    public function test_activation_gate_passes_for_published_noindex_iq_method_articles(): void
    {
        $this->createPublishedIqMethodArticles();
        $this->createTopicAndLandingLinks();

        [$exit, $payload] = $this->callGate();

        $this->assertSame(0, $exit, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['execute']);
        $this->assertSame(7, $payload['activation_candidate_count']);
        $this->assertCount(7, $payload['activation_candidates']);
        $this->assertSame('what-is-iq-style-reasoning-test', $payload['activation_candidates'][0]['slug']);
        $this->assertSame('index,follow', $payload['activation_candidates'][0]['next_robots']);
        $this->assertStringContainsString('articles:iq-method-pages-seo-geo-activate', $payload['activation_candidates'][0]['activation_command']);
        $this->assertFalse($payload['side_effects']['db_write']);
        $this->assertFalse($payload['side_effects']['indexability']);
        $this->assertFalse($payload['side_effects']['sitemap']);
        $this->assertFalse($payload['side_effects']['llms']);

        $fresh = Article::query()->withoutGlobalScopes()->where('slug', 'what-is-iq-style-reasoning-test')->firstOrFail();
        $this->assertFalse((bool) $fresh->is_indexable);
        $this->assertFalse((bool) $fresh->sitemap_eligible);
        $this->assertFalse((bool) $fresh->llms_eligible);
    }

    public function test_activation_gate_blocks_forbidden_claims(): void
    {
        $this->createPublishedIqMethodArticles();
        $this->createTopicAndLandingLinks();

        Article::query()->withoutGlobalScopes()
            ->where('slug', 'what-is-iq-style-reasoning-test')
            ->update(['content_md' => $this->body()."\n\n这是官方 IQ 和 Mensa 认证。"]);

        [$exit, $payload] = $this->callGate();

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertContains('forbidden_claim_detected', collect($payload['issues'])->pluck('code')->all());
        $this->assertSame(6, $payload['activation_candidate_count']);
    }

    public function test_activation_gate_blocks_premature_discoverability_flags(): void
    {
        $this->createPublishedIqMethodArticles();
        $this->createTopicAndLandingLinks();

        Article::query()->withoutGlobalScopes()
            ->where('slug', 'iq-test-privacy-data-boundary')
            ->update(['sitemap_eligible' => true, 'llms_eligible' => true]);

        [$exit, $payload] = $this->callGate();

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertContains('iq-test-privacy-data-boundary.current_sitemap_eligible', collect($payload['issues'])->pluck('field')->all());
        $this->assertContains('iq-test-privacy-data-boundary.current_llms_eligible', collect($payload['issues'])->pluck('field')->all());
    }

    private function createPublishedIqMethodArticles(): void
    {
        foreach ($this->slugs() as $index => $slug) {
            $article = Article::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'slug' => $slug,
                'locale' => 'zh-CN',
                'title' => 'IQ 方法页 '.$index,
                'excerpt' => 'IQ 方法页摘要，说明非官方、非临床、非认证边界。',
                'content_md' => $this->body(),
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
                'excerpt' => 'IQ 方法页摘要，说明非官方、非临床、非认证边界。',
                'content_md' => $this->body(),
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
                'seo_description' => 'IQ 方法页 SEO 描述，说明非官方、非临床、非认证边界。',
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

    private function body(): string
    {
        return <<<'MARKDOWN'
# 什么是 IQ 风格推理测试？

这篇文章解释 FermatMind IQ V1 的方法边界。IQ 风格推理测试使用图形、矩阵、规律补全、空间关系和限时作答任务，帮助用户理解本次推理任务表现。

## 方法边界

FermatMind IQ V1 是非官方、非临床、非认证的在线推理任务。页面只解释本次 30 题任务里的原始分、正确率、完成时间和维度表现，不把结果扩展成人群排名、长期能力标签或任何诊断结论。

## 可见证据

公开页面必须让读者看见方法、题型、数据和隐私边界。内容不展示题目答案、评分规则、私人结果链接、订单链接、支付信息或恢复链接。测试材料保护是方法可信度的一部分。

## FAQ

### 这是正式智力测评吗？

不是。它是 IQ 风格的推理任务说明，用于理解本次答题表现。

### 页面能说明什么？

它能说明图形推理、模式识别和完成时间如何作为本次任务信号被解释。

### 页面不能说明什么？

它不能给出官方证明、临床判断、教育录取判断、用人判断或长期能力结论。
MARKDOWN;
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
    private function callGate(): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('articles:iq-method-pages-seo-geo-activation-gate', ['--json' => true], $output);
        $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return [$exit, $decoded];
    }
}
