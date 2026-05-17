<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ArticleCoverPropagationSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_passes_when_cover_propagates_to_detail_list_seo_and_jsonld(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $article = $this->createArticleWithCover();

        $exitCode = Artisan::call('articles:cover-smoke', [
            '--article' => [(string) $article->id],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame($article->id, data_get($payload, 'articles.0.article_id'));
        $this->assertTrue((bool) data_get($payload, 'articles.0.list_found'));
        $this->assertSame([], data_get($payload, 'articles.0.errors'));
    }

    public function test_command_fails_closed_when_required_cover_variant_is_missing(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $article = $this->createArticleWithCover([
            'cover_image_variants' => [
                'hero' => $this->variant('hero_1600x900.jpg', 1600, 900),
                'card' => $this->variant('card_800x450.jpg', 800, 450),
                'thumbnail' => $this->variant('thumbnail_400x225.jpg', 400, 225),
                'og' => $this->variant('og_1200x630.jpg', 1200, 630),
            ],
        ]);

        $exitCode = Artisan::call('articles:cover-smoke', [
            '--article' => [(string) $article->id],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $codes = collect((array) data_get($payload, 'articles.0.errors', []))
            ->pluck('code')
            ->all();

        $this->assertSame(1, $exitCode);
        $this->assertFalse((bool) ($payload['ok'] ?? true));
        $this->assertContains('cover_variant_missing', $codes);
    }

    public function test_command_requires_explicit_article_or_slug_target(): void
    {
        $exitCode = Artisan::call('articles:cover-smoke', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse((bool) ($payload['ok'] ?? true));
        $this->assertSame('target_missing', data_get($payload, 'errors.0.code'));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createArticleWithCover(array $overrides = []): Article
    {
        $category = ArticleCategory::query()->create([
            'org_id' => 0,
            'slug' => 'personality',
            'name' => 'Personality',
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $coverUrl = 'https://api.fermatmind.com/storage/media-library/articles/big-five-cover.jpg';
        $article = Article::query()->create(array_merge([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'author_name' => 'Fermat Institute',
            'slug' => 'big-five-cover-smoke',
            'locale' => 'zh-CN',
            'title' => 'Big Five cover smoke',
            'excerpt' => 'Cover smoke excerpt.',
            'content_md' => 'Cover smoke body.',
            'cover_image_url' => $coverUrl,
            'cover_image_alt' => 'Abstract five-axis Big Five cover',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'cover_image_variants' => [
                'hero' => $this->variant('hero_1600x900.jpg', 1600, 900),
                'card' => $this->variant('card_800x450.jpg', 800, 450),
                'thumbnail' => $this->variant('thumbnail_400x225.jpg', 400, 225),
                'og' => $this->variant('og_1200x630.jpg', 1200, 630),
                'preload' => $this->variant('preload_64x36.jpg', 64, 36),
            ],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 5, 17, 8, 0, 0, 'UTC'),
        ], $overrides));

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'title' => 'Big Five cover smoke',
            'excerpt' => 'Cover smoke excerpt.',
            'content_md' => 'Cover smoke body.',
            'seo_title' => 'Big Five cover smoke SEO',
            'seo_description' => 'Cover smoke SEO description.',
            'published_at' => Carbon::create(2026, 5, 17, 8, 0, 0, 'UTC'),
        ]);

        $article->forceFill(['published_revision_id' => $revision->id])->save();

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => 'Big Five cover smoke SEO',
            'seo_description' => 'Cover smoke SEO description.',
            'canonical_url' => 'https://fermatmind.com/zh/articles/big-five-cover-smoke',
            'og_title' => 'Big Five cover smoke SEO',
            'og_description' => 'Cover smoke SEO description.',
            'og_image_url' => $coverUrl,
            'robots' => 'index,follow',
            'schema_json' => [
                'image' => $coverUrl,
            ],
            'is_indexable' => true,
        ]);

        return $article->fresh(['publishedRevision']) ?? $article;
    }

    /**
     * @return array{url:string,width:int,height:int,mime_type:string}
     */
    private function variant(string $file, int $width, int $height): array
    {
        return [
            'url' => 'https://api.fermatmind.com/storage/media-library/variants/big-five-cover/'.$file,
            'width' => $width,
            'height' => $height,
            'mime_type' => 'image/jpeg',
        ];
    }
}
