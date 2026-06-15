<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Services\Cms\ArticleSeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ArticleSeoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_seo_meta_persists_localized_frontend_canonical_url(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'mbti-basics',
            'locale' => 'zh-CN',
            'title' => 'MBTI 基础',
            'excerpt' => '了解 MBTI 的核心概念。',
            'content_md' => '# MBTI 基础',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 12, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'created_at' => Carbon::create(2026, 3, 12, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 12, 9, 0, 0, 'UTC'),
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => 'MBTI 基础',
            'seo_description' => '旧的 seo 描述',
            'canonical_url' => 'https://api.staging.fermatmind.com/articles/mbti-basics',
            'og_title' => 'MBTI 基础',
            'og_description' => '旧的 seo 描述',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $seoMeta = app(ArticleSeoService::class)->generateSeoMeta((int) $article->id);

        $this->assertSame('https://staging.fermatmind.com/zh/articles/mbti-basics', $seoMeta->canonical_url);
        $this->assertDatabaseHas('article_seo_meta', [
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'canonical_url' => 'https://staging.fermatmind.com/zh/articles/mbti-basics',
        ]);
    }

    public function test_build_seo_payload_converges_fermat_www_to_apex(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);

        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'mbti-basics',
            'locale' => 'en',
            'title' => 'MBTI Basics',
            'excerpt' => 'Learn the core concepts behind MBTI.',
            'content_md' => '# MBTI Basics',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 12, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'created_at' => Carbon::create(2026, 3, 12, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 12, 9, 0, 0, 'UTC'),
        ]);

        $payload = app(ArticleSeoService::class)->buildSeoPayload($article);
        $jsonLd = app(ArticleSeoService::class)->generateJsonLd($article);

        $this->assertSame('https://fermatmind.com/en/articles/mbti-basics', $payload['canonical']);
        $this->assertSame('https://fermatmind.com/en/articles/mbti-basics', data_get($jsonLd, 'url'));
        $this->assertStringNotContainsString('www.fermatmind.com', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('www.fermatmind.com', json_encode($jsonLd, JSON_THROW_ON_ERROR));
    }

    public function test_generate_json_ld_filters_visible_faq_when_faq_schema_gate_is_held(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'enneagram-personality-test-explained',
            'locale' => 'zh-CN',
            'title' => '九型人格测试准吗？',
            'excerpt' => '了解九型人格测试的边界。',
            'content_md' => '# 九型人格测试准吗？',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 6, 14, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'created_at' => Carbon::create(2026, 6, 14, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 6, 14, 9, 0, 0, 'UTC'),
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => '九型人格测试准吗？',
            'seo_description' => '了解九型人格测试的边界。',
            'canonical_url' => 'https://fermatmind.com/zh/articles/enneagram-personality-test-explained',
            'og_title' => '九型人格测试准吗？',
            'og_description' => '了解九型人格测试的边界。',
            'robots' => 'index,follow',
            'is_indexable' => true,
            'schema_json' => [
                'editorial_package_v1' => [
                    'faq_schema_enabled' => false,
                    'answer_surface_policy' => 'editor_supplied',
                    'answer_surface_visibility' => 'below_intro',
                    'answer_surface_v1' => [
                        'faq_items' => [
                            ['question' => '九型人格能诊断人格吗？', 'answer' => '不能，它只能用于自我理解。'],
                        ],
                    ],
                ],
            ],
        ]);

        $service = app(ArticleSeoService::class);
        $heldJsonLd = $service->generateJsonLd($article);
        $enabledJsonLd = $service->generateJsonLd($article, null, true);

        $this->assertStringNotContainsString('FAQPage', json_encode($heldJsonLd, JSON_THROW_ON_ERROR));
        $this->assertSame('FAQPage', data_get($enabledJsonLd, 'hasPart.0.@type'));
    }
}
