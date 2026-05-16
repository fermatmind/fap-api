<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Services\Cms\ArticleSeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ArticleSeoServiceMediaGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_json_ld_sanitizes_image_fields_from_seo_schema_json(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);

        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'jsonld-media-guard',
            'locale' => 'en',
            'title' => 'JSON-LD media guard',
            'excerpt' => 'Guard article media URLs.',
            'content_md' => '# JSON-LD media guard',
            'cover_image_url' => 'https://169.254.169.254/latest/meta-data',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 5, 16, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'JSON-LD media guard | FermatMind',
            'seo_description' => 'Guard article media URLs.',
            'canonical_url' => 'https://www.fermatmind.com/en/articles/jsonld-media-guard',
            'og_title' => 'JSON-LD media guard',
            'og_description' => 'Guard article media URLs.',
            'og_image_url' => 'https://127.0.0.1/internal.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
            'schema_json' => [
                '@type' => 'Article',
                'url' => 'https://www.fermatmind.com/en/articles/jsonld-media-guard',
                'image' => [
                    'https://cdn.example.test/blocked.png',
                    [
                        '@type' => 'ImageObject',
                        'url' => 'https://api.fermatmind.com/static/articles/cover.png',
                    ],
                ],
            ],
        ]);

        $jsonLd = app(ArticleSeoService::class)->generateJsonLd($article);
        $encoded = json_encode($jsonLd, JSON_THROW_ON_ERROR);

        $this->assertSame('https://fermatmind.com/en/articles/jsonld-media-guard', data_get($jsonLd, 'url'));
        $this->assertSame('https://api.fermatmind.com/static/articles/cover.png', data_get($jsonLd, 'image.0.url'));
        $this->assertStringNotContainsString('169.254.169.254', $encoded);
        $this->assertStringNotContainsString('127.0.0.1', $encoded);
        $this->assertStringNotContainsString('cdn.example.test', $encoded);
    }
}
