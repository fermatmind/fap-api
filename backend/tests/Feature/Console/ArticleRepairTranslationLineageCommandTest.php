<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ArticleRepairTranslationLineageCommandTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'tg-article-lineage-repair';

    public function test_dry_run_reports_exact_lineage_repair_without_writes(): void
    {
        [$source, $target, $sourceRevision, $publishedRevision, $workingRevision] = $this->seedSplitSourceGroup();

        $exit = Artisan::call('articles:repair-translation-lineage', $this->commandOptions(
            $source,
            $target,
            $sourceRevision,
            $publishedRevision,
            $workingRevision,
            ['--dry-run' => true, '--json' => true],
        ));

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertTrue($payload['ok']);
        $this->assertTrue(data_get($payload, 'plan.would_write'));
        $this->assertSame((int) $source->id, (int) data_get($payload, 'plan.source_article_id'));
        $this->assertSame((int) $target->id, (int) data_get($payload, 'plan.target_article_id'));

        $target->refresh();
        $workingRevision->refresh();
        $this->assertTrue($target->isSourceArticle());
        $this->assertSame((int) $target->id, (int) $workingRevision->source_article_id);
    }

    public function test_execute_repairs_articles_and_all_locked_revisions_idempotently(): void
    {
        [$source, $target, $sourceRevision, $publishedRevision, $workingRevision] = $this->seedSplitSourceGroup();
        $options = $this->commandOptions($source, $target, $sourceRevision, $publishedRevision, $workingRevision, [
            '--execute' => true,
            '--confirm' => "I explicitly approve article translation lineage repair from source {$source->id} to target {$target->id}.",
            '--json' => true,
        ]);

        $this->assertSame(0, Artisan::call('articles:repair-translation-lineage', $options));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('translation_lineage_repaired', $payload['action']);
        $this->assertFalse(data_get($payload, 'plan.would_write'));

        $source->refresh();
        $target->refresh();
        $sourceRevision->refresh();
        $publishedRevision->refresh();
        $workingRevision->refresh();

        $this->assertTrue($source->isSourceArticle());
        $this->assertFalse($target->isSourceArticle());
        $this->assertSame('zh-CN', (string) $target->source_locale);
        $this->assertSame((int) $source->id, (int) $target->source_article_id);
        $this->assertSame((int) $source->id, (int) $target->translated_from_article_id);
        $this->assertSame(Article::TRANSLATION_STATUS_HUMAN_REVIEW, (string) $target->translation_status);

        foreach ([$sourceRevision, $publishedRevision, $workingRevision] as $revision) {
            $this->assertSame((int) $source->id, (int) $revision->source_article_id);
            $this->assertSame('zh-CN', (string) $revision->source_locale);
            $this->assertSame((string) $sourceRevision->source_version_hash, (string) $revision->source_version_hash);
            $this->assertSame((string) $sourceRevision->source_version_hash, (string) $revision->translated_from_version_hash);
        }

        $this->assertSame(0, Artisan::call('articles:repair-translation-lineage', $options));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('translation_lineage_already_repaired', $payload['action']);
    }

    public function test_execute_fails_closed_when_a_body_hash_drifts(): void
    {
        [$source, $target, $sourceRevision, $publishedRevision, $workingRevision] = $this->seedSplitSourceGroup();
        $options = $this->commandOptions($source, $target, $sourceRevision, $publishedRevision, $workingRevision, [
            '--expected-target-working-body-sha256' => str_repeat('0', 64),
            '--execute' => true,
            '--confirm' => "I explicitly approve article translation lineage repair from source {$source->id} to target {$target->id}.",
            '--json' => true,
        ]);

        $this->assertSame(1, Artisan::call('articles:repair-translation-lineage', $options));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['ok']);
        $this->assertContains('body_hash_mismatch', array_column($payload['errors'], 'code'));
        $this->assertTrue($target->fresh()->isSourceArticle());
    }

    /** @return array{Article,Article,ArticleTranslationRevision,ArticleTranslationRevision,ArticleTranslationRevision} */
    private function seedSplitSourceGroup(): array
    {
        $source = Article::query()->create([
            'org_id' => 0,
            'slug' => 'source-article',
            'locale' => 'zh-CN',
            'translation_group_id' => self::GROUP,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'source_version_hash' => hash('sha256', 'source-version'),
            'title' => '源文章',
            'excerpt' => '源文章摘要',
            'content_md' => '## 源文章正文',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
        ]);
        $target = Article::query()->create([
            'org_id' => 0,
            'slug' => 'target-article',
            'locale' => 'en',
            'translation_group_id' => self::GROUP,
            'source_locale' => 'en',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'source_version_hash' => hash('sha256', 'old-target-version'),
            'title' => 'Target article',
            'excerpt' => 'Target article excerpt',
            'content_md' => '## Published target body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
        ]);

        $sourceRevision = $this->revision($source, 1, ArticleTranslationRevision::STATUS_PUBLISHED, '## 源文章正文');
        $publishedRevision = $this->revision($target, 1, ArticleTranslationRevision::STATUS_PUBLISHED, '## Published target body');
        $workingRevision = $this->revision($target, 2, ArticleTranslationRevision::STATUS_HUMAN_REVIEW, '## Working target body');

        $source->forceFill([
            'working_revision_id' => (int) $sourceRevision->id,
            'published_revision_id' => (int) $sourceRevision->id,
        ])->save();
        $target->forceFill([
            'working_revision_id' => (int) $workingRevision->id,
            'published_revision_id' => (int) $publishedRevision->id,
        ])->save();

        $this->seo($source, '/zh/articles/source-article');
        $this->seo($target, '/en/articles/target-article');

        return [$source, $target, $sourceRevision, $publishedRevision, $workingRevision];
    }

    private function revision(Article $article, int $number, string $status, string $body): ArticleTranslationRevision
    {
        return ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => self::GROUP,
            'locale' => (string) $article->locale,
            'source_locale' => (string) $article->locale,
            'revision_number' => $number,
            'revision_status' => $status,
            'source_version_hash' => $article->source_version_hash,
            'translated_from_version_hash' => $article->source_version_hash,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => $body,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
        ]);
    }

    private function seo(Article $article, string $canonical): void
    {
        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'canonical_url' => $canonical,
            'og_title' => (string) $article->title,
            'og_description' => (string) $article->excerpt,
            'og_image_url' => 'https://example.test/image.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function commandOptions(
        Article $source,
        Article $target,
        ArticleTranslationRevision $sourceRevision,
        ArticleTranslationRevision $publishedRevision,
        ArticleTranslationRevision $workingRevision,
        array $overrides,
    ): array {
        return array_replace([
            '--source-article-id' => (int) $source->id,
            '--target-article-id' => (int) $target->id,
            '--source-revision-id' => (int) $sourceRevision->id,
            '--target-published-revision-id' => (int) $publishedRevision->id,
            '--target-working-revision-id' => (int) $workingRevision->id,
            '--translation-group-id' => self::GROUP,
            '--source-locale' => 'zh-CN',
            '--target-locale' => 'en',
            '--expected-source-slug' => 'source-article',
            '--expected-target-slug' => 'target-article',
            '--expected-source-canonical' => '/zh/articles/source-article',
            '--expected-target-canonical' => '/en/articles/target-article',
            '--expected-source-body-sha256' => $this->bodyHash((string) $sourceRevision->content_md),
            '--expected-target-published-body-sha256' => $this->bodyHash((string) $publishedRevision->content_md),
            '--expected-target-working-body-sha256' => $this->bodyHash((string) $workingRevision->content_md),
        ], $overrides);
    }

    private function bodyHash(string $body): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
    }
}
