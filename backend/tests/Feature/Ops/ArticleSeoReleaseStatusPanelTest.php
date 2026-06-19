<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Support\ArticleSeoReleaseStatus;
use App\Models\Article;
use App\Services\Cms\ArticleReleaseCloseoutService;
use RuntimeException;
use Tests\TestCase;

final class ArticleSeoReleaseStatusPanelTest extends TestCase
{
    public function test_panel_renders_read_only_closeout_projection(): void
    {
        $this->app->instance(ArticleReleaseCloseoutService::class, new class
        {
            /**
             * @return array<string,mixed>
             */
            public function inspect(int $articleId, string $expectedSlug, ?array $publicSmoke = null): array
            {
                return [
                    'ok' => true,
                    'decision' => ArticleReleaseCloseoutService::COMPLETE_SEARCH_OBSERVATION_PENDING,
                    'canonical_url' => 'https://fermatmind.com/zh/articles/'.$expectedSlug,
                    'checks' => [
                        'article' => ['ok' => true, 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'issues' => []],
                        'seo_meta' => ['ok' => true, 'canonical_path' => '/zh/articles/'.$expectedSlug, 'robots' => 'index,follow', 'issues' => []],
                        'media' => ['ok' => true, 'body_visual_urls' => ['https://api.fermatmind.com/body.png'], 'issues' => []],
                        'taxonomy' => ['ok' => true, 'category' => ['name' => '职业决策'], 'issues' => []],
                        'discoverability' => ['ok' => true, 'sitemap_eligible' => true, 'llms_eligible' => true, 'llms_full_source_eligible' => true, 'issues' => []],
                        'url_truth' => ['ok' => true, 'present' => true, 'issues' => []],
                        'search_channel' => [
                            'ok' => true,
                            'channels' => [
                                'indexnow' => ['queue_item_id' => 58, 'execution_state' => 'accepted'],
                                'baidu_push' => ['queue_item_id' => 59, 'execution_state' => 'accepted'],
                            ],
                            'issues' => [],
                        ],
                        'schema_hreflang' => [
                            'ok' => true,
                            'article_schema_enabled' => true,
                            'breadcrumb_schema_enabled' => true,
                            'faq_schema_enabled' => false,
                            'issues' => [],
                        ],
                        'public_html_smoke' => ['ok' => null, 'state' => 'not_provided', 'issues' => []],
                        'gsc_manual' => ['ok' => null, 'state' => 'operator_record_required', 'issues' => []],
                        'observation' => ['ok' => null, 'state' => 'd1_d7_d14_queue_record_required', 'issues' => []],
                    ],
                    'issues' => [],
                ];
            }
        });

        $html = (string) ArticleSeoReleaseStatus::render($this->article());

        $this->assertStringContainsString(ArticleReleaseCloseoutService::COMPLETE_SEARCH_OBSERVATION_PENDING, $html);
        $this->assertStringContainsString('Title, meta, canonical, robots', $html);
        $this->assertStringContainsString('IndexNow and Baidu queue', $html);
        $this->assertStringContainsString('indexnow#58:accepted / baidu_push#59:accepted', $html);
        $this->assertStringContainsString('php artisan articles:release-closeout --article-id=53 --expected-slug=', $html);
        $this->assertStringNotContainsString('production_write_attempted', $html);
    }

    public function test_panel_falls_back_to_blocked_status_when_status_source_is_unavailable(): void
    {
        $this->app->instance(ArticleReleaseCloseoutService::class, new class
        {
            public function inspect(int $articleId, string $expectedSlug, ?array $publicSmoke = null): array
            {
                throw new RuntimeException('seo intel unavailable');
            }
        });

        $html = (string) ArticleSeoReleaseStatus::render($this->article());

        $this->assertStringContainsString(ArticleReleaseCloseoutService::BLOCKED_OPERATOR_INPUT, $html);
        $this->assertStringContainsString('release_closeout_unavailable', $html);
        $this->assertStringContainsString('Article release closeout service could not read all required status sources.', $html);
    }

    public function test_article_resource_mounts_seo_release_status_panel(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Ops/Resources/ArticleResource.php'));

        $this->assertStringContainsString('SEO Release Status', $source);
        $this->assertStringContainsString('ArticleSeoReleaseStatus::render($record)', $source);
    }

    private function article(): Article
    {
        $article = new Article;
        $article->forceFill([
            'id' => 53,
            'slug' => 'gaokao-score-major-shortlist-riasec-checklist',
        ]);
        $article->exists = true;

        return $article;
    }
}
