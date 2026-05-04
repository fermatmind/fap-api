<?php

declare(strict_types=1);

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, array{slug:string, public_id:int, duplicate_source_id:int, en_id:int}>
     */
    private array $fixtures = [
        [
            'slug' => 'how-personality-shapes-attitude-toward-ai',
            'public_id' => 15,
            'duplicate_source_id' => 17,
            'en_id' => 24,
        ],
        [
            'slug' => 'which-love-script-fits-you-best',
            'public_id' => 16,
            'duplicate_source_id' => 18,
            'en_id' => 25,
        ],
        [
            'slug' => 'are-infj-men-rare-or-socially-silenced',
            'public_id' => 11,
            'duplicate_source_id' => 19,
            'en_id' => 26,
        ],
        [
            'slug' => 'best-valentines-date-by-personality-and-relationship-science',
            'public_id' => 12,
            'duplicate_source_id' => 20,
            'en_id' => 27,
        ],
        [
            'slug' => 'how-16-personality-types-talk-to-an-ai-coach',
            'public_id' => 14,
            'duplicate_source_id' => 21,
            'en_id' => 28,
        ],
        [
            'slug' => 'childhood-dream-job-still-shapes-career-choice',
            'public_id' => 13,
            'duplicate_source_id' => 22,
            'en_id' => 29,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('articles') || ! Schema::hasTable('article_translation_revisions')) {
            return;
        }

        DB::transaction(function (): void {
            foreach ($this->fixtures as $fixture) {
                $this->consolidateFixture($fixture);
            }
        });
    }

    public function down(): void
    {
        // Forward-only ownership repair. Restoring the previous linkage would
        // reintroduce split canonical article owners for public zh and EN drafts.
    }

    /**
     * @param  array{slug:string, public_id:int, duplicate_source_id:int, en_id:int}  $fixture
     */
    private function consolidateFixture(array $fixture): void
    {
        $public = $this->findArticle($fixture['public_id'], $fixture['slug'], 'zh-CN');
        $duplicateSource = $this->findArticle($fixture['duplicate_source_id'], $fixture['slug'], 'zh-CN');
        $englishDraft = $this->findArticle($fixture['en_id'], $fixture['slug'], 'en');

        if (! $public || ! $duplicateSource || ! $englishDraft) {
            return;
        }

        if ((string) $public->status !== 'published' || ! (bool) $public->is_public || ! $public->published_revision_id) {
            return;
        }

        if ((string) $englishDraft->status === 'published' || (bool) $englishDraft->is_public || $englishDraft->published_revision_id) {
            return;
        }

        $publicGroupId = (string) ($public->translation_group_id ?: 'article-'.$public->id);
        $sourceHash = $this->sourceHash($public);
        $now = now();

        if ($this->alreadyConsolidated($public, $duplicateSource, $englishDraft, $publicGroupId)) {
            return;
        }

        DB::table('articles')
            ->where('id', (int) $public->id)
            ->update([
                'translation_group_id' => $publicGroupId,
                'source_locale' => 'zh-CN',
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'translated_from_article_id' => null,
                'source_article_id' => null,
                'translated_from_version_hash' => null,
                'source_version_hash' => $sourceHash,
                'updated_at' => $now,
            ]);

        DB::table('article_translation_revisions')
            ->where('article_id', (int) $public->id)
            ->update([
                'source_article_id' => (int) $public->id,
                'translation_group_id' => $publicGroupId,
                'source_locale' => 'zh-CN',
                'source_version_hash' => $sourceHash,
                'translated_from_version_hash' => $sourceHash,
                'updated_at' => $now,
            ]);

        DB::table('articles')
            ->where('id', (int) $englishDraft->id)
            ->where('status', '<>', 'published')
            ->where('is_public', false)
            ->whereNull('published_revision_id')
            ->update([
                'translation_group_id' => $publicGroupId,
                'source_locale' => 'zh-CN',
                'translated_from_article_id' => (int) $public->id,
                'source_article_id' => (int) $public->id,
                'translated_from_version_hash' => $sourceHash,
                'updated_at' => $now,
            ]);

        DB::table('article_translation_revisions')
            ->where('article_id', (int) $englishDraft->id)
            ->update([
                'source_article_id' => (int) $public->id,
                'translation_group_id' => $publicGroupId,
                'source_locale' => 'zh-CN',
                'source_version_hash' => $sourceHash,
                'translated_from_version_hash' => $sourceHash,
                'updated_at' => $now,
            ]);

        DB::table('articles')
            ->where('id', (int) $duplicateSource->id)
            ->where('status', '<>', 'published')
            ->where('is_public', false)
            ->whereNull('published_revision_id')
            ->update([
                'translation_group_id' => $publicGroupId,
                'source_locale' => 'zh-CN',
                'translation_status' => Article::TRANSLATION_STATUS_ARCHIVED,
                'translated_from_article_id' => (int) $public->id,
                'source_article_id' => (int) $public->id,
                'translated_from_version_hash' => $sourceHash,
                'updated_at' => $now,
            ]);

        DB::table('article_translation_revisions')
            ->where('article_id', (int) $duplicateSource->id)
            ->update([
                'source_article_id' => (int) $public->id,
                'translation_group_id' => $publicGroupId,
                'source_locale' => 'zh-CN',
                'revision_status' => ArticleTranslationRevision::STATUS_ARCHIVED,
                'source_version_hash' => $sourceHash,
                'translated_from_version_hash' => $sourceHash,
                'updated_at' => $now,
            ]);
    }

    private function alreadyConsolidated(
        object $public,
        object $duplicateSource,
        object $englishDraft,
        string $publicGroupId
    ): bool {
        return (string) ($public->translation_group_id ?? '') === $publicGroupId
            && $public->source_article_id === null
            && (string) ($duplicateSource->translation_status ?? '') === Article::TRANSLATION_STATUS_ARCHIVED
            && (int) ($duplicateSource->source_article_id ?? 0) === (int) $public->id
            && (string) ($duplicateSource->translation_group_id ?? '') === $publicGroupId
            && (int) ($englishDraft->source_article_id ?? 0) === (int) $public->id
            && (string) ($englishDraft->translation_group_id ?? '') === $publicGroupId;
    }

    private function findArticle(int $id, string $slug, string $locale): ?object
    {
        return DB::table('articles')
            ->where('id', $id)
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->first([
                'id',
                'slug',
                'locale',
                'title',
                'excerpt',
                'content_md',
                'content_html',
                'cover_image_alt',
                'related_test_slug',
                'voice',
                'voice_order',
                'status',
                'is_public',
                'translation_group_id',
                'source_article_id',
                'published_revision_id',
            ]);
    }

    private function sourceHash(object $article): string
    {
        $payload = [
            'locale' => $article->locale,
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => $article->content_md,
            'content_html' => $article->content_html,
            'cover_image_alt' => $article->cover_image_alt,
            'related_test_slug' => $article->related_test_slug,
            'voice' => $article->voice,
            'voice_order' => $article->voice_order,
        ];
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
};
