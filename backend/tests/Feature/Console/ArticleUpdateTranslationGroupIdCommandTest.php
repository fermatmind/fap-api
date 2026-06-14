<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ArticleUpdateTranslationGroupIdCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_GROUP_ID = 'unknown_until_cms_import';

    private const NEW_GROUP_ID = 'tg_article_iq_test_score_and_limits_explained_2026v1';

    public function test_default_invocation_is_dry_run_and_does_not_write(): void
    {
        $article = $this->createPublishedArticle();

        $exitCode = Artisan::call('articles:update-translation-group-id', [
            '--article-id' => (int) $article->id,
            '--expected-slug' => 'iq-test-score-and-limits-explained',
            '--current-translation-group-id' => self::CURRENT_GROUP_ID,
            '--new-translation-group-id' => self::NEW_GROUP_ID,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_update_translation_group_id', $payload['action']);
        $this->assertFalse($payload['would_write']);
        $this->assertSame(self::CURRENT_GROUP_ID, $payload['before']['translation_group_id']);
        $this->assertSame(self::NEW_GROUP_ID, $payload['after']['translation_group_id']);

        $fresh = Article::query()->withoutGlobalScopes()->with([
            'workingRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
        ])->findOrFail((int) $article->id);
        $this->assertSame(self::CURRENT_GROUP_ID, (string) $fresh->translation_group_id);
        $this->assertSame(self::CURRENT_GROUP_ID, (string) $fresh->workingRevision?->translation_group_id);
        $this->assertSame(self::CURRENT_GROUP_ID, (string) $fresh->publishedRevision?->translation_group_id);
    }

    public function test_execute_updates_article_and_current_revision_translation_group_ids_only(): void
    {
        $article = $this->createPublishedArticle();
        $before = $this->protectedSnapshot($article);
        $revisionCountBefore = ArticleTranslationRevision::query()->withoutGlobalScopes()->count();

        $exitCode = Artisan::call('articles:update-translation-group-id', [
            '--article-id' => (int) $article->id,
            '--expected-slug' => 'iq-test-score-and-limits-explained',
            '--current-translation-group-id' => self::CURRENT_GROUP_ID,
            '--new-translation-group-id' => self::NEW_GROUP_ID,
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
        $this->assertSame('updated_translation_group_id', $payload['action']);
        $this->assertSame($revisionCountBefore, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());

        $fresh = Article::query()->withoutGlobalScopes()->with([
            'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'workingRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
        ])->findOrFail((int) $article->id);

        $this->assertSame(self::NEW_GROUP_ID, (string) $fresh->translation_group_id);
        $this->assertSame(self::NEW_GROUP_ID, (string) $fresh->workingRevision?->translation_group_id);
        $this->assertSame(self::NEW_GROUP_ID, (string) $fresh->publishedRevision?->translation_group_id);

        $after = $this->protectedSnapshot($fresh);
        $before['translation_group_id'] = self::NEW_GROUP_ID;
        $before['working_revision_translation_group_id'] = self::NEW_GROUP_ID;
        $before['published_revision_translation_group_id'] = self::NEW_GROUP_ID;
        $this->assertSame($before, $after);
    }

    public function test_execute_requires_all_no_side_effect_flags(): void
    {
        $article = $this->createPublishedArticle();

        $exitCode = Artisan::call('articles:update-translation-group-id', [
            '--article-id' => (int) $article->id,
            '--expected-slug' => 'iq-test-score-and-limits-explained',
            '--current-translation-group-id' => self::CURRENT_GROUP_ID,
            '--new-translation-group-id' => self::NEW_GROUP_ID,
            '--execute' => true,
            '--json' => true,
            '--no-publish' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'required_safety_flag_missing');
        $this->assertSame(self::CURRENT_GROUP_ID, (string) Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id)->translation_group_id);
    }

    public function test_locks_reject_wrong_slug_current_group_or_collision(): void
    {
        $article = $this->createPublishedArticle();
        $this->createPublishedArticle(id: 51, slug: 'enneagram-personality-test-explained', translationGroupId: self::NEW_GROUP_ID);

        $exitCode = Artisan::call('articles:update-translation-group-id', [
            '--article-id' => (int) $article->id,
            '--expected-slug' => 'wrong-slug',
            '--current-translation-group-id' => 'wrong-current',
            '--new-translation-group-id' => self::NEW_GROUP_ID,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'slug_mismatch');
        $this->assertErrorCode($payload, 'translation_group_id_mismatch');
        $this->assertErrorCode($payload, 'translation_group_id_collision');
    }

    public function test_rejects_invalid_or_same_new_translation_group_id(): void
    {
        $article = $this->createPublishedArticle();

        $exitCode = Artisan::call('articles:update-translation-group-id', [
            '--article-id' => (int) $article->id,
            '--expected-slug' => 'iq-test-score-and-limits-explained',
            '--current-translation-group-id' => self::CURRENT_GROUP_ID,
            '--new-translation-group-id' => self::CURRENT_GROUP_ID,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'new_translation_group_id_same_as_current');

        $exitCode = Artisan::call('articles:update-translation-group-id', [
            '--article-id' => (int) $article->id,
            '--expected-slug' => 'iq-test-score-and-limits-explained',
            '--current-translation-group-id' => self::CURRENT_GROUP_ID,
            '--new-translation-group-id' => str_repeat('x', 65),
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'new_translation_group_id_invalid');
    }

    private function createPublishedArticle(int $id = 50, string $slug = 'iq-test-score-and-limits-explained', string $translationGroupId = self::CURRENT_GROUP_ID): Article
    {
        /** @var Article $article */
        $article = Article::query()->withoutGlobalScopes()->create([
            'id' => $id,
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'translation_group_id' => $translationGroupId,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '在线 IQ 测试准吗？',
            'excerpt' => 'A safe IQ score interpretation guide.',
            'content_md' => '# Body',
            'content_html' => '<h1>Body</h1>',
            'cover_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/articleiqscorelimitspillarcoverv1/hero_1600x900.jpg',
            'cover_image_alt' => '在线 IQ 测试中矩阵推理、模式识别与抽象问题解决示意图',
            'related_test_slug' => 'iq-test',
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
            'translation_group_id' => $translationGroupId,
            'locale' => 'zh-CN',
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
            'locale' => 'zh-CN',
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'canonical_url' => 'https://fermatmind.com/zh/articles/'.$slug,
            'og_title' => (string) $article->title,
            'og_description' => (string) $article->excerpt,
            'og_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/articleiqscorelimitspillarcoverv1/og_1200x630.jpg',
            'robots' => 'index,follow',
            'schema_json' => [
                'editorial_package_v1' => [
                    'article_schema_enabled' => true,
                    'breadcrumb_schema_enabled' => true,
                    'faq_schema_enabled' => false,
                    'hreflang_gate_v1' => [
                        'enabled' => false,
                    ],
                ],
            ],
            'is_indexable' => true,
        ]);

        return $article->refresh();
    }

    /**
     * @return array<string,mixed>
     */
    private function protectedSnapshot(Article $article): array
    {
        $article->refresh();
        $article->load([
            'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'workingRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
            'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
        ]);

        return [
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'translation_group_id' => (string) $article->translation_group_id,
            'working_revision_translation_group_id' => (string) $article->workingRevision?->translation_group_id,
            'published_revision_translation_group_id' => (string) $article->publishedRevision?->translation_group_id,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'content_html' => (string) $article->content_html,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => $article->translated_from_version_hash,
            'cover_image_url' => (string) $article->cover_image_url,
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
