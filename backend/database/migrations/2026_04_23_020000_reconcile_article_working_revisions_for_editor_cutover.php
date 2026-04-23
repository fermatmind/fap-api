<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('articles') || ! Schema::hasTable('article_translation_revisions')) {
            return;
        }

        DB::table('articles')
            ->select([
                'id',
                'org_id',
                'locale',
                'translation_group_id',
                'source_locale',
                'translation_status',
                'translated_from_article_id',
                'source_article_id',
                'source_version_hash',
                'translated_from_version_hash',
                'working_revision_id',
                'title',
                'excerpt',
                'content_md',
                'content_html',
                'cover_image_alt',
                'related_test_slug',
                'voice',
                'voice_order',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($articles): void {
                foreach ($articles as $article) {
                    $this->reconcileArticle($article);
                }
            });
    }

    public function down(): void
    {
        // forward-only data reconciliation: rollback would risk discarding editor-visible content.
    }

    private function reconcileArticle(object $article): void
    {
        $revisionId = (int) ($article->working_revision_id ?? 0);
        $revision = $revisionId > 0 ? $this->findRevision($revisionId) : null;
        $revisionNumber = $revision ? (int) $revision->revision_number : $this->nextRevisionNumber((int) $article->id);
        $seoMeta = $this->findSeoMeta((int) $article->id, (string) ($article->locale ?? ''));
        $ownHash = $this->sourceVersionHashFromArticle($article);
        $sourceArticleId = $this->sourceArticleIdFor($article);
        $isSource = (int) $article->id === $sourceArticleId;
        $sourceHash = $isSource ? $ownHash : $this->currentSourceHash($sourceArticleId);
        $now = now();

        $payload = [
            'org_id' => (int) ($article->org_id ?? 0),
            'article_id' => (int) $article->id,
            'source_article_id' => $sourceArticleId,
            'translation_group_id' => (string) ($article->translation_group_id ?: 'article-'.$article->id),
            'locale' => (string) ($article->locale ?: 'zh-CN'),
            'source_locale' => (string) ($article->source_locale ?: $article->locale ?: 'zh-CN'),
            'revision_number' => $revisionNumber,
            'revision_status' => $this->normalizeRevisionStatus($article->translation_status ?? null, $isSource),
            'source_version_hash' => $sourceHash,
            'translated_from_version_hash' => $isSource
                ? $ownHash
                : ($article->translated_from_version_hash ?: $sourceHash),
            'title' => (string) ($article->title ?? ''),
            'excerpt' => $article->excerpt,
            'content_md' => (string) ($article->content_md ?? ''),
            'seo_title' => $seoMeta?->seo_title,
            'seo_description' => $seoMeta?->seo_description,
            'updated_at' => $now,
        ];

        if ($revision) {
            DB::table('article_translation_revisions')
                ->where('id', $revision->id)
                ->update($payload);
            $revisionId = (int) $revision->id;
        } else {
            $revisionId = (int) DB::table('article_translation_revisions')->insertGetId(array_merge($payload, [
                'supersedes_revision_id' => null,
                'created_by' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'approved_at' => null,
                'published_at' => null,
                'created_at' => $now,
            ]));
        }

        $articleUpdates = [
            'working_revision_id' => $revisionId,
        ];

        if ($isSource) {
            $articleUpdates['source_version_hash'] = $ownHash;
        }

        DB::table('articles')
            ->where('id', $article->id)
            ->update($articleUpdates);
    }

    private function findRevision(int $revisionId): ?object
    {
        return DB::table('article_translation_revisions')
            ->where('id', $revisionId)
            ->first(['id', 'revision_number']);
    }

    private function nextRevisionNumber(int $articleId): int
    {
        return ((int) DB::table('article_translation_revisions')
            ->where('article_id', $articleId)
            ->max('revision_number')) + 1;
    }

    private function sourceArticleIdFor(object $article): int
    {
        $sourceArticleId = (int) ($article->source_article_id ?? 0);
        if ($sourceArticleId > 0) {
            return $sourceArticleId;
        }

        $translatedFromArticleId = (int) ($article->translated_from_article_id ?? 0);
        if ($translatedFromArticleId > 0) {
            return $translatedFromArticleId;
        }

        return (int) $article->id;
    }

    private function currentSourceHash(int $sourceArticleId): ?string
    {
        $workingRevisionId = DB::table('articles')
            ->where('id', $sourceArticleId)
            ->value('working_revision_id');

        if ($workingRevisionId) {
            $revisionHash = DB::table('article_translation_revisions')
                ->where('id', $workingRevisionId)
                ->value('source_version_hash');

            if (filled($revisionHash)) {
                return (string) $revisionHash;
            }
        }

        $sourceArticle = DB::table('articles')
            ->where('id', $sourceArticleId)
            ->first([
                'locale',
                'title',
                'excerpt',
                'content_md',
                'content_html',
                'cover_image_alt',
                'related_test_slug',
                'voice',
                'voice_order',
                'source_version_hash',
            ]);

        if (! $sourceArticle) {
            return null;
        }

        return filled($sourceArticle->source_version_hash)
            ? (string) $sourceArticle->source_version_hash
            : $this->sourceVersionHashFromArticle($sourceArticle);
    }

    private function sourceVersionHashFromArticle(object $article): string
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

    private function findSeoMeta(int $articleId, string $locale): ?object
    {
        return DB::table('article_seo_meta')
            ->where('article_id', $articleId)
            ->where('locale', $locale)
            ->first(['seo_title', 'seo_description']);
    }

    private function normalizeRevisionStatus(?string $status, bool $isSource): string
    {
        $status = trim((string) $status);
        $allowed = ['source', 'machine_draft', 'human_review', 'approved', 'published', 'stale', 'archived'];

        if (in_array($status, $allowed, true)) {
            return $status;
        }

        return $isSource ? 'source' : 'machine_draft';
    }
};
