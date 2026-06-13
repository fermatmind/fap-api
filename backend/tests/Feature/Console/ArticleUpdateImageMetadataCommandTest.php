<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArticleUpdateImageMetadataCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATION_GROUP_ID = 'tg_article_image_metadata_update_2026v1';

    public function test_default_invocation_is_dry_run_and_does_not_write(): void
    {
        $articles = $this->createPublishedPair();
        $metadata = $this->writeResolvedMetadata();

        $exitCode = Artisan::call('articles:update-image-metadata', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--resolved-metadata' => $metadata,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_update_image_metadata', $payload['action']);
        $this->assertFalse($payload['would_write']);

        foreach ($articles as $article) {
            $fresh = Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id);
            $this->assertSame('https://assets.fermatmind.com/articles/old/hero.jpg', (string) $fresh->cover_image_url);
            $this->assertSame('https://assets.fermatmind.com/articles/old/og.jpg', (string) $fresh->seoMeta?->og_image_url);
        }
    }

    public function test_execute_updates_only_image_metadata_and_does_not_create_revision_or_change_holds(): void
    {
        $articles = $this->createPublishedPair();
        $metadata = $this->writeResolvedMetadata();
        $before = $this->protectedSnapshots($articles);
        $revisionCountBefore = ArticleTranslationRevision::query()->withoutGlobalScopes()->count();

        $exitCode = Artisan::call('articles:update-image-metadata', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--resolved-metadata' => $metadata,
            '--execute' => true,
            '--json' => true,
            '--no-publish' => true,
            '--no-schema' => true,
            '--no-hreflang' => true,
            '--no-search' => true,
            '--no-sitemap-llms-change' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('updated_image_metadata', $payload['action']);
        $this->assertSame($revisionCountBefore, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());

        foreach ($articles as $article) {
            $fresh = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail((int) $article->id);
            $this->assertSame('https://assets.fermatmind.com/articles/career-map/hero_1600x900.jpg', (string) $fresh->cover_image_url);
            $this->assertSame('Career exploration map showing interest, personality, and real-world validation steps', (string) $fresh->cover_image_alt);
            $this->assertSame(1600, (int) $fresh->cover_image_width);
            $this->assertSame(900, (int) $fresh->cover_image_height);
            $this->assertSame('https://assets.fermatmind.com/articles/career-map/og_1200x630.jpg', (string) $fresh->seoMeta?->og_image_url);
            $this->assertSame('article.career.exploration.map.cover.v1', (string) data_get($fresh->cover_image_variants, 'editorial_package_v1.cover_media_asset_key'));

            $this->assertSame($before[(int) $article->id], $this->protectedSnapshot($fresh));
        }
    }

    public function test_execute_requires_all_no_side_effect_flags(): void
    {
        $articles = $this->createPublishedPair();
        $metadata = $this->writeResolvedMetadata();

        $exitCode = Artisan::call('articles:update-image-metadata', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--resolved-metadata' => $metadata,
            '--execute' => true,
            '--json' => true,
            '--no-publish' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'required_safety_flag_missing');
        foreach ($articles as $article) {
            $fresh = Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id);
            $this->assertSame('https://assets.fermatmind.com/articles/old/hero.jpg', (string) $fresh->cover_image_url);
        }
    }

    public function test_translation_group_lock_rejects_wrong_articles(): void
    {
        $articles = $this->createPublishedPair();
        $metadata = $this->writeResolvedMetadata();

        $exitCode = Artisan::call('articles:update-image-metadata', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => 'tg_wrong',
            '--resolved-metadata' => $metadata,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'translation_group_id_mismatch');
    }

    public function test_rejects_missing_resolved_metadata_file(): void
    {
        $articles = $this->createPublishedPair();

        $exitCode = Artisan::call('articles:update-image-metadata', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--resolved-metadata' => sys_get_temp_dir().'/missing-'.Str::random(8).'.json',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'resolved_metadata_file_missing');
    }

    public function test_rejects_placeholder_or_private_image_url(): void
    {
        $articles = $this->createPublishedPair();
        $metadata = $this->writeResolvedMetadata(static function (array &$payload): void {
            $payload['cover_image_url'] = '__CMS_MEDIA_LIBRARY_PLACEHOLDER__';
            $payload['og_image_url'] = 'https://example.com/private/og.jpg';
        });

        $exitCode = Artisan::call('articles:update-image-metadata', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--resolved-metadata' => $metadata,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'placeholder_not_allowed');
        $this->assertErrorCode($payload, 'public_media_url_invalid');
    }

    public function test_json_output_includes_before_after_snapshots(): void
    {
        $articles = $this->createPublishedPair();
        $metadata = $this->writeResolvedMetadata();

        $exitCode = Artisan::call('articles:update-image-metadata', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--resolved-metadata' => $metadata,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertCount(2, $payload['before']);
        $this->assertCount(2, $payload['after']);
        $this->assertSame('https://assets.fermatmind.com/articles/old/hero.jpg', $payload['before'][0]['cover_image_url']);
        $this->assertSame('https://assets.fermatmind.com/articles/career-map/hero_1600x900.jpg', $payload['after'][0]['cover_image_url']);
        $this->assertSame($payload['before'][0]['schema_json_sha256'], $payload['after'][0]['schema_json_sha256']);
    }

    /**
     * @return list<Article>
     */
    private function createPublishedPair(): array
    {
        return [
            $this->createPublishedArticle(48, 'zh-CN', 'career-confusion-test-map', '/zh/articles/career-confusion-test-map'),
            $this->createPublishedArticle(49, 'en', 'choose-career-using-personality-tests', '/en/articles/choose-career-using-personality-tests'),
        ];
    }

    private function createPublishedArticle(int $id, string $locale, string $slug, string $canonical): Article
    {
        /** @var Article $article */
        $article = Article::query()->withoutGlobalScopes()->create([
            'id' => $id,
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'source_locale' => 'zh-CN',
            'translation_status' => $locale === 'zh-CN' ? Article::TRANSLATION_STATUS_SOURCE : Article::TRANSLATION_STATUS_PUBLISHED,
            'title' => $locale === 'zh-CN' ? '不知道自己适合什么职业怎么办？' : 'How to Choose a Career Using Personality Tests',
            'excerpt' => 'A career exploration guide.',
            'content_md' => '# Body',
            'content_html' => '<h1>Body</h1>',
            'cover_image_url' => 'https://assets.fermatmind.com/articles/old/hero.jpg',
            'cover_image_alt' => 'Old cover',
            'cover_image_width' => 1200,
            'cover_image_height' => 630,
            'cover_image_variants' => [
                'hero' => [
                    'url' => 'https://assets.fermatmind.com/articles/old/hero.jpg',
                    'width' => 1200,
                    'height' => 630,
                ],
            ],
            'related_test_slug' => 'holland-career-interest-test-riasec',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now(),
        ]);

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale' => $locale,
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'published_at' => now(),
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->saveQuietly();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'canonical_url' => $canonical,
            'og_title' => (string) $article->title,
            'og_description' => (string) $article->excerpt,
            'og_image_url' => 'https://assets.fermatmind.com/articles/old/og.jpg',
            'robots' => 'index,follow',
            'schema_json' => [
                'editorial_package_v1' => [
                    'article_schema_enabled' => true,
                    'breadcrumb_schema_enabled' => true,
                    'faq_schema_enabled' => false,
                    'hreflang_gate_v1' => [
                        'enabled' => true,
                    ],
                ],
            ],
            'is_indexable' => true,
        ]);

        return $article->refresh();
    }

    /**
     * @param  callable(array<string,mixed>&):void|null  $mutate
     */
    private function writeResolvedMetadata(?callable $mutate = null): string
    {
        $path = sys_get_temp_dir().'/fm-resolved-image-metadata-'.Str::random(12).'.json';
        $payload = [
            'cover_media_asset_key' => 'article.career.exploration.map.cover.v1',
            'cover_image_url' => 'https://assets.fermatmind.com/articles/career-map/hero_1600x900.jpg',
            'cover_image_alt' => 'Career exploration map showing interest, personality, and real-world validation steps',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'cover_image_variants' => [
                'hero' => [
                    'url' => 'https://assets.fermatmind.com/articles/career-map/hero_1600x900.jpg',
                    'width' => 1600,
                    'height' => 900,
                    'mime_type' => 'image/jpeg',
                ],
                'card' => [
                    'url' => 'https://assets.fermatmind.com/articles/career-map/card_800x450.jpg',
                    'width' => 800,
                    'height' => 450,
                    'mime_type' => 'image/jpeg',
                ],
                'thumbnail' => [
                    'url' => 'https://assets.fermatmind.com/articles/career-map/thumbnail_400x225.jpg',
                    'width' => 400,
                    'height' => 225,
                    'mime_type' => 'image/jpeg',
                ],
                'og' => [
                    'url' => 'https://assets.fermatmind.com/articles/career-map/og_1200x630.jpg',
                    'width' => 1200,
                    'height' => 630,
                    'mime_type' => 'image/jpeg',
                ],
                'preload' => [
                    'url' => 'https://assets.fermatmind.com/articles/career-map/preload_64x36.jpg',
                    'width' => 64,
                    'height' => 36,
                    'mime_type' => 'image/jpeg',
                ],
            ],
            'og_image_url' => 'https://assets.fermatmind.com/articles/career-map/og_1200x630.jpg',
            'twitter_image_url' => 'https://assets.fermatmind.com/articles/career-map/og_1200x630.jpg',
            'social_image_metadata' => [
                'media_library_asset_key' => 'article.career.exploration.map.cover.v1',
                'media_library_status' => 'published',
                'media_library_is_public' => true,
                'asset_provenance' => 'gpt_generated_for_fermatmind',
            ],
            'body_visual_asset_key' => 'article.career.exploration.map.body.v1',
            'body_visual_image_url' => 'https://assets.fermatmind.com/articles/career-map/body_1600x900.jpg',
            'body_visual_fallback_authorized' => false,
        ];

        if ($mutate !== null) {
            $mutate($payload);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @param  list<Article>  $articles
     */
    private function articleIds(array $articles): string
    {
        return implode(',', array_map(static fn (Article $article): string => (string) $article->id, $articles));
    }

    /**
     * @param  list<Article>  $articles
     * @return array<int,array<string,mixed>>
     */
    private function protectedSnapshots(array $articles): array
    {
        $snapshots = [];
        foreach ($articles as $article) {
            $snapshots[(int) $article->id] = $this->protectedSnapshot($article);
        }

        return $snapshots;
    }

    /**
     * @return array<string,mixed>
     */
    private function protectedSnapshot(Article $article): array
    {
        $article->refresh();
        $article->load('seoMeta');

        return [
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'translation_group_id' => (string) $article->translation_group_id,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'content_html' => (string) $article->content_html,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'working_revision_id' => (int) $article->working_revision_id,
            'published_revision_id' => (int) $article->published_revision_id,
            'source_version_hash' => (string) $article->source_version_hash,
            'canonical_url' => (string) $article->seoMeta?->canonical_url,
            'robots' => (string) $article->seoMeta?->robots,
            'seo_is_indexable' => (bool) $article->seoMeta?->is_indexable,
            'schema_json' => $article->seoMeta?->schema_json,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertErrorCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
    }
}
