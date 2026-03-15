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
}
