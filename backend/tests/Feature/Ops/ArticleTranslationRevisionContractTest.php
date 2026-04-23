<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArticleTranslationRevisionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_revision_backfill_creates_source_and_human_review_revisions(): void
    {
        $source = $this->createArticle(1, 'zh-CN', '中文源文');
        ArticleSeoMeta::query()->create([
            'article_id' => $source->id,
            'seo_title' => '中文 SEO 标题',
            'seo_description' => '中文 SEO 描述',
        ]);

        $translation = $this->createArticle(1, 'en', 'Reviewed English draft', [
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => $source->source_locale,
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ]);

        $this->runRevisionBackfill();

        $source->refresh();
        $translation->refresh();

        $this->assertNull($source->source_article_id);
        $this->assertNotNull($source->working_revision_id);
        $this->assertNull($source->published_revision_id);
        $this->assertSame($source->id, $translation->source_article_id);
        $this->assertNotNull($translation->working_revision_id);
        $this->assertNull($translation->published_revision_id);

        $sourceRevision = $source->workingRevision;
        $translationRevision = $translation->workingRevision;

        $this->assertInstanceOf(ArticleTranslationRevision::class, $sourceRevision);
        $this->assertSame($source->id, $sourceRevision->article_id);
        $this->assertSame($source->id, $sourceRevision->source_article_id);
        $this->assertSame(1, $sourceRevision->revision_number);
        $this->assertSame(ArticleTranslationRevision::STATUS_SOURCE, $sourceRevision->revision_status);
        $this->assertSame($source->source_version_hash, $sourceRevision->source_version_hash);
        $this->assertSame($source->source_version_hash, $sourceRevision->translated_from_version_hash);
        $this->assertSame('中文 SEO 标题', $sourceRevision->seo_title);
        $this->assertSame('中文 SEO 描述', $sourceRevision->seo_description);

        $this->assertInstanceOf(ArticleTranslationRevision::class, $translationRevision);
        $this->assertSame($translation->id, $translationRevision->article_id);
        $this->assertSame($source->id, $translationRevision->source_article_id);
        $this->assertSame($source->translation_group_id, $translationRevision->translation_group_id);
        $this->assertSame('en', $translationRevision->locale);
        $this->assertSame('zh-CN', $translationRevision->source_locale);
        $this->assertSame(1, $translationRevision->revision_number);
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $translationRevision->revision_status);
        $this->assertSame($source->source_version_hash, $translationRevision->source_version_hash);
        $this->assertSame($source->source_version_hash, $translationRevision->translated_from_version_hash);
        $this->assertNull($translationRevision->reviewed_at);
        $this->assertNull($translationRevision->approved_at);
        $this->assertFalse($translationRevision->isStale($source));
    }

    public function test_published_articles_backfill_published_revision_pointer(): void
    {
        $published = $this->createArticle(1, 'en', 'Published source', [
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->runRevisionBackfill();

        $published->refresh();

        $this->assertNotNull($published->working_revision_id);
        $this->assertSame($published->working_revision_id, $published->published_revision_id);
        $this->assertNotNull($published->publishedRevision?->published_at);
    }

    public function test_same_locale_can_create_new_revision_without_new_article_row(): void
    {
        $source = $this->createArticle(1, 'zh-CN', '中文源文');
        $translation = $this->createArticle(1, 'en', 'Reviewed English draft', [
            'slug' => 'stable-article-slug',
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => $source->source_locale,
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ]);

        $this->runRevisionBackfill();
        $translation->refresh();

        ArticleTranslationRevision::query()->create([
            'org_id' => $translation->org_id,
            'article_id' => $translation->id,
            'source_article_id' => $source->id,
            'translation_group_id' => $translation->translation_group_id,
            'locale' => $translation->locale,
            'source_locale' => $translation->source_locale,
            'revision_number' => 2,
            'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'source_version_hash' => $source->source_version_hash,
            'translated_from_version_hash' => $source->source_version_hash,
            'supersedes_revision_id' => $translation->working_revision_id,
            'title' => 'Second English machine draft',
            'excerpt' => 'Second draft excerpt',
            'content_md' => 'Second draft body',
        ]);

        $this->assertSame(1, Article::query()
            ->where('org_id', 1)
            ->where('locale', 'en')
            ->where('slug', 'stable-article-slug')
            ->count());
        $this->assertSame(2, $translation->translationRevisions()->count());
    }

    public function test_backfill_smoke_covers_current_source_and_en_translation_id_window(): void
    {
        $slugs = [
            17 => 'how-personality-shapes-attitude-toward-ai',
            18 => 'which-love-script-fits-you-best',
            19 => 'are-infj-men-rare-or-socially-silenced',
            20 => 'best-valentines-date-by-personality-and-relationship-science',
            21 => 'how-16-personality-types-talk-to-an-ai-coach',
            22 => 'childhood-dream-job-still-shapes-career-choice',
        ];

        Article::unguarded(function () use ($slugs): void {
            foreach ($slugs as $id => $slug) {
                $source = $this->createArticle(1, 'zh-CN', '中文源文 '.$id, [
                    'id' => $id,
                    'slug' => $slug,
                    'translation_group_id' => 'article-group-'.$id,
                ]);

                $this->createArticle(1, 'en', 'Reviewed English draft '.$id, [
                    'id' => $id + 7,
                    'slug' => $slug,
                    'translation_group_id' => $source->translation_group_id,
                    'source_locale' => $source->source_locale,
                    'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
                    'translated_from_article_id' => $source->id,
                    'translated_from_version_hash' => $source->source_version_hash,
                ]);
            }
        });

        $this->runRevisionBackfill();

        $articleIds = [17, 18, 19, 20, 21, 22, 24, 25, 26, 27, 28, 29];

        $this->assertSame(12, Article::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $articleIds)
            ->whereNotNull('working_revision_id')
            ->count());
        $this->assertSame(12, ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereIn('article_id', $articleIds)
            ->where('revision_number', 1)
            ->count());
        $this->assertSame(6, ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereIn('article_id', [17, 18, 19, 20, 21, 22])
            ->where('revision_status', ArticleTranslationRevision::STATUS_SOURCE)
            ->count());
        $this->assertSame(6, ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereIn('article_id', [24, 25, 26, 27, 28, 29])
            ->where('revision_status', ArticleTranslationRevision::STATUS_HUMAN_REVIEW)
            ->count());
    }

    public function test_revision_stale_and_supersedes_relationship_are_available(): void
    {
        $source = $this->createArticle(1, 'zh-CN', '中文源文');
        $translation = $this->createArticle(1, 'en', 'Reviewed English draft', [
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => $source->source_locale,
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ]);

        $this->runRevisionBackfill();

        $source->forceFill(['title' => '中文源文已更新'])->save();
        $translation->refresh();
        $oldRevision = $translation->workingRevision;

        $this->assertTrue($oldRevision->isStale($source));

        $newRevision = ArticleTranslationRevision::query()->create([
            'org_id' => $translation->org_id,
            'article_id' => $translation->id,
            'source_article_id' => $source->id,
            'translation_group_id' => $translation->translation_group_id,
            'locale' => $translation->locale,
            'source_locale' => $translation->source_locale,
            'revision_number' => 2,
            'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'source_version_hash' => $source->source_version_hash,
            'translated_from_version_hash' => $source->source_version_hash,
            'supersedes_revision_id' => $oldRevision->id,
            'title' => 'Resynced English machine draft',
            'excerpt' => 'Resync excerpt',
            'content_md' => 'Resync body',
        ]);

        $translation->forceFill(['working_revision_id' => $newRevision->id])->save();

        $this->assertFalse($newRevision->isStale($source));
        $this->assertTrue($newRevision->supersedes->is($oldRevision));
        $this->assertTrue($oldRevision->supersededBy->contains($newRevision));
        $this->assertSame(Article::TRANSLATION_STATUS_HUMAN_REVIEW, $translation->refresh()->translation_status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(int $orgId, string $locale, string $title, array $overrides = []): Article
    {
        return Article::query()->create(array_merge([
            'org_id' => $orgId,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'locale' => $locale,
            'title' => $title,
            'excerpt' => 'Translation revision excerpt',
            'content_md' => 'Translation revision body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ], $overrides));
    }

    private function runRevisionBackfill(): void
    {
        $migration = require database_path('migrations/2026_04_23_010000_create_article_translation_revisions_table.php');
        $migration->up();
    }
}
