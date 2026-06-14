<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ArticleReplaceInlineImageUrlCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATION_GROUP_ID = 'tg_article_career_exploration_map_2026v1';

    private const OLD_FRAGMENT = 'articleriasecexplanationcoverv1/hero_1600x900.jpg';

    private const NEW_FRAGMENT = 'articlecareerconfusiontestmapbody-visualv1/hero_1600x900.jpg';

    private const OLD_URL = 'https://api.fermatmind.com/storage/media-library/variants/'.self::OLD_FRAGMENT;

    private const NEW_URL = 'https://api.fermatmind.com/storage/media-library/variants/'.self::NEW_FRAGMENT;

    public function test_default_invocation_is_dry_run_and_does_not_write(): void
    {
        $articles = $this->createPublishedPair();
        $beforeHashes = $this->bodyHashes($articles);

        $exitCode = Artisan::call('articles:replace-inline-image-url', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--old-url' => self::OLD_FRAGMENT,
            '--new-url' => self::NEW_FRAGMENT,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_replace_inline_image_url', $payload['action']);
        $this->assertFalse($payload['would_write']);

        foreach ($articles as $article) {
            $fresh = Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id);
            $this->assertSame($beforeHashes[(int) $article->id], hash('sha256', (string) $fresh->content_md));
            $this->assertStringContainsString(self::OLD_FRAGMENT, (string) $fresh->content_md);
            $this->assertStringNotContainsString(self::NEW_FRAGMENT, (string) $fresh->content_md);
        }
    }

    public function test_execute_replaces_one_inline_image_url_without_changing_protected_fields_or_creating_revision(): void
    {
        $articles = $this->createPublishedPair();
        $before = $this->protectedSnapshots($articles);
        $revisionCountBefore = ArticleTranslationRevision::query()->withoutGlobalScopes()->count();

        $exitCode = Artisan::call('articles:replace-inline-image-url', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--old-url' => self::OLD_FRAGMENT,
            '--new-url' => self::NEW_FRAGMENT,
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
        $this->assertSame('replaced_inline_image_url', $payload['action']);
        $this->assertSame($revisionCountBefore, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());

        foreach ($articles as $article) {
            $fresh = Article::query()
                ->withoutGlobalScopes()
                ->with([
                    'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
                    'workingRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
                    'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
                ])
                ->findOrFail((int) $article->id);

            $this->assertStringContainsString(self::NEW_FRAGMENT, (string) $fresh->content_md);
            $this->assertStringNotContainsString(self::OLD_FRAGMENT, (string) $fresh->content_md);
            $this->assertSame($this->bodyWithImage(self::NEW_URL, (string) $fresh->locale), (string) $fresh->content_md);
            $this->assertSame($this->bodyWithImage(self::NEW_URL, (string) $fresh->locale), (string) $fresh->workingRevision?->content_md);
            $this->assertSame($this->bodyWithImage(self::NEW_URL, (string) $fresh->locale), (string) $fresh->publishedRevision?->content_md);
            $this->assertSame($payload['after'][array_search((int) $article->id, array_column($payload['after'], 'article_id'), true)]['source_version_hash'], (string) $fresh->source_version_hash);
            $this->assertSame((string) $fresh->source_version_hash, (string) $fresh->workingRevision?->source_version_hash);
            $this->assertSame((string) $fresh->source_version_hash, (string) $fresh->publishedRevision?->source_version_hash);
            $this->assertSame($before[(int) $article->id], $this->protectedSnapshot($fresh));
        }
    }

    public function test_execute_requires_all_no_side_effect_flags(): void
    {
        $articles = $this->createPublishedPair();

        $exitCode = Artisan::call('articles:replace-inline-image-url', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--old-url' => self::OLD_FRAGMENT,
            '--new-url' => self::NEW_FRAGMENT,
            '--execute' => true,
            '--json' => true,
            '--no-publish' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'required_safety_flag_missing');
        foreach ($articles as $article) {
            $fresh = Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id);
            $this->assertStringContainsString(self::OLD_FRAGMENT, (string) $fresh->content_md);
            $this->assertStringNotContainsString(self::NEW_FRAGMENT, (string) $fresh->content_md);
        }
    }

    public function test_translation_group_lock_rejects_wrong_articles(): void
    {
        $articles = $this->createPublishedPair();

        $exitCode = Artisan::call('articles:replace-inline-image-url', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => 'tg_wrong',
            '--old-url' => self::OLD_FRAGMENT,
            '--new-url' => self::NEW_FRAGMENT,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'translation_group_id_mismatch');
    }

    public function test_rejects_missing_old_image_occurrence(): void
    {
        $missing = $this->createPublishedPair(contentUrl: self::NEW_URL);

        $exitCode = Artisan::call('articles:replace-inline-image-url', [
            '--article-ids' => $this->articleIds($missing),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--old-url' => self::OLD_FRAGMENT,
            '--new-url' => self::NEW_FRAGMENT,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'inline_image_replacement_count_not_one');
    }

    public function test_rejects_duplicate_old_image_occurrences(): void
    {
        $duplicate = $this->createPublishedPair(contentUrl: self::OLD_URL."\n\n".self::OLD_URL);

        $exitCode = Artisan::call('articles:replace-inline-image-url', [
            '--article-ids' => $this->articleIds($duplicate),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--old-url' => self::OLD_FRAGMENT,
            '--new-url' => self::NEW_FRAGMENT,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'inline_image_replacement_count_not_one');
    }

    public function test_rejects_placeholder_private_or_legacy_replacement_url(): void
    {
        $articles = $this->createPublishedPair();

        $exitCode = Artisan::call('articles:replace-inline-image-url', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--old-url' => '__CMS_MEDIA_LIBRARY_PLACEHOLDER__',
            '--new-url' => 'https://example.com/private/result_id/image.jpg',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'placeholder_not_allowed');
        $this->assertErrorCode($payload, 'private_url_marker_not_allowed');
        $this->assertErrorCode($payload, 'public_media_url_invalid');

        $exitCode = Artisan::call('articles:replace-inline-image-url', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--old-url' => self::OLD_FRAGMENT,
            '--new-url' => self::OLD_FRAGMENT,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'legacy_image_url_not_allowed_for_replacement');
        $this->assertErrorCode($payload, 'replacement_url_same_as_old');
    }

    public function test_json_output_includes_hashes_diff_and_replacement_counts(): void
    {
        $articles = $this->createPublishedPair();

        $exitCode = Artisan::call('articles:replace-inline-image-url', [
            '--article-ids' => $this->articleIds($articles),
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--old-url' => self::OLD_FRAGMENT,
            '--new-url' => self::NEW_FRAGMENT,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertCount(2, $payload['before']);
        $this->assertCount(2, $payload['after']);
        $this->assertNotSame($payload['before'][0]['content_md_sha256'], $payload['after'][0]['content_md_sha256']);
        $this->assertSame(1, $payload['replacement_plan'][0]['surfaces']['article.content_md']['replacement_count']);
        $this->assertStringContainsString(self::OLD_FRAGMENT, $payload['replacement_plan'][0]['surfaces']['article.content_md']['diff']['before_line']);
        $this->assertStringContainsString(self::NEW_FRAGMENT, $payload['replacement_plan'][0]['surfaces']['article.content_md']['diff']['after_line']);
    }

    /**
     * @return list<Article>
     */
    private function createPublishedPair(?string $contentUrl = null): array
    {
        return [
            $this->createPublishedArticle(48, 'zh-CN', 'career-confusion-test-map', '/zh/articles/career-confusion-test-map', $contentUrl ?? self::OLD_URL),
            $this->createPublishedArticle(49, 'en', 'choose-career-using-personality-tests', '/en/articles/choose-career-using-personality-tests', $contentUrl ?? self::OLD_URL),
        ];
    }

    private function createPublishedArticle(int $id, string $locale, string $slug, string $canonical, string $contentUrl): Article
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
            'content_md' => $this->bodyWithImage($contentUrl, $locale),
            'content_html' => '<h1>Body</h1>',
            'cover_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/articlecareerconfusiontestmapcoverv1/hero_1600x900.jpg',
            'cover_image_alt' => 'Career exploration map showing interest, personality, and real-world validation steps',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'cover_image_variants' => [
                'hero' => [
                    'url' => 'https://api.fermatmind.com/storage/media-library/variants/articlecareerconfusiontestmapcoverv1/hero_1600x900.jpg',
                    'width' => 1600,
                    'height' => 900,
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
            'og_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/articlecareerconfusiontestmapcoverv1/og_1200x630.jpg',
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

    private function bodyWithImage(string $url, string $locale): string
    {
        $lead = $locale === 'zh-CN'
            ? '先用职业兴趣缩小方向，再用性格偏好理解工作方式。'
            : 'Start with career interests, then use personality preferences to understand work style.';

        return <<<MD
# Body

{$lead}

![Career exploration map]({$url})

Keep the article body text unchanged.
MD;
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
     * @return array<int,string>
     */
    private function bodyHashes(array $articles): array
    {
        $hashes = [];
        foreach ($articles as $article) {
            $hashes[(int) $article->id] = hash('sha256', (string) $article->content_md);
        }

        return $hashes;
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
        $article->load([
            'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
        ]);

        return [
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'translation_group_id' => (string) $article->translation_group_id,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_html' => (string) $article->content_html,
            'cover_image_url' => (string) $article->cover_image_url,
            'cover_image_variants' => $article->cover_image_variants,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'working_revision_id' => (int) $article->working_revision_id,
            'published_revision_id' => (int) $article->published_revision_id,
            'canonical_url' => (string) $article->seoMeta?->canonical_url,
            'robots' => (string) $article->seoMeta?->robots,
            'seo_is_indexable' => (bool) $article->seoMeta?->is_indexable,
            'schema_json' => $article->seoMeta?->schema_json,
            'og_image_url' => (string) $article->seoMeta?->og_image_url,
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
