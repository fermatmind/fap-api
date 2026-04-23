<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addArticleRevisionPointers();
        $this->createTranslationRevisionTable();
        $this->backfillExistingArticles();
        $this->addArticlePointerIndexes();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent translation revision history loss.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function addArticleRevisionPointers(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            if (! Schema::hasColumn('articles', 'source_article_id')) {
                $table->unsignedBigInteger('source_article_id')->nullable()->after('translated_from_article_id');
            }
            if (! Schema::hasColumn('articles', 'working_revision_id')) {
                $table->unsignedBigInteger('working_revision_id')->nullable()->after('translated_from_version_hash');
            }
            if (! Schema::hasColumn('articles', 'published_revision_id')) {
                $table->unsignedBigInteger('published_revision_id')->nullable()->after('working_revision_id');
            }
        });
    }

    private function createTranslationRevisionTable(): void
    {
        if (Schema::hasTable('article_translation_revisions')) {
            return;
        }

        Schema::create('article_translation_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('source_article_id')->nullable();
            $table->string('translation_group_id', 64);
            $table->string('locale', 16);
            $table->string('source_locale', 16)->nullable();
            $table->unsignedInteger('revision_number');
            $table->string('revision_status', 32)->default('machine_draft');
            $table->string('source_version_hash', 64)->nullable();
            $table->string('translated_from_version_hash', 64)->nullable();
            $table->unsignedBigInteger('supersedes_revision_id')->nullable();
            $table->string('title', 255);
            $table->text('excerpt')->nullable();
            $table->longText('content_md');
            $table->string('seo_title', 255)->nullable();
            $table->text('seo_description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'revision_number'], 'article_translation_revisions_article_revision_unique');
            $table->index('article_id', 'article_translation_revisions_article_idx');
            $table->index('source_article_id', 'article_translation_revisions_source_article_idx');
            $table->index('translation_group_id', 'article_translation_revisions_group_idx');
            $table->index(['source_locale', 'revision_status'], 'article_translation_revisions_state_idx');
            $table->index('supersedes_revision_id', 'article_translation_revisions_supersedes_idx');
        });
    }

    private function backfillExistingArticles(): void
    {
        DB::table('articles')
            ->select([
                'id',
                'org_id',
                'slug',
                'locale',
                'translation_group_id',
                'source_locale',
                'translation_status',
                'translated_from_article_id',
                'source_article_id',
                'source_version_hash',
                'translated_from_version_hash',
                'title',
                'excerpt',
                'content_md',
                'status',
                'is_public',
                'published_at',
                'working_revision_id',
                'published_revision_id',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($articles): void {
                foreach ($articles as $article) {
                    $this->backfillArticleRevision($article);
                }
            });
    }

    private function backfillArticleRevision(object $article): void
    {
        $sourceArticleId = $this->resolveSourceArticleId($article);
        $sourceArticle = $this->findArticle($sourceArticleId);
        $sourceVersionHash = $this->resolveCurrentSourceVersionHash($article, $sourceArticle);
        $translationGroupId = $this->resolveTranslationGroupId($article, $sourceArticle);
        $sourceLocale = $this->resolveSourceLocale($article, $sourceArticle);
        $revisionStatus = $this->normalizeRevisionStatus($article->translation_status ?? null, $article, $sourceArticleId);
        $basisHash = $this->resolveTranslatedFromVersionHash($article, $sourceVersionHash, $sourceArticleId);
        $seoMeta = $this->findSeoMeta((int) $article->id, (string) ($article->locale ?? ''));
        $now = now();

        $revisionId = DB::table('article_translation_revisions')
            ->where('article_id', $article->id)
            ->where('revision_number', 1)
            ->value('id');

        if (! $revisionId) {
            $revisionId = DB::table('article_translation_revisions')->insertGetId([
                'org_id' => (int) ($article->org_id ?? 0),
                'article_id' => (int) $article->id,
                'source_article_id' => $sourceArticleId,
                'translation_group_id' => $translationGroupId,
                'locale' => $this->normalizeLocale($article->locale ?? null),
                'source_locale' => $sourceLocale,
                'revision_number' => 1,
                'revision_status' => $revisionStatus,
                'source_version_hash' => $sourceVersionHash,
                'translated_from_version_hash' => $basisHash,
                'supersedes_revision_id' => null,
                'title' => (string) ($article->title ?? ''),
                'excerpt' => $article->excerpt,
                'content_md' => (string) ($article->content_md ?? ''),
                'seo_title' => $seoMeta?->seo_title,
                'seo_description' => $seoMeta?->seo_description,
                'created_by' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'approved_at' => null,
                'published_at' => $this->isPublished($article) ? $article->published_at : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('articles')
            ->where('id', $article->id)
            ->update([
                'source_article_id' => $this->isSourceArticle($article, $sourceArticleId) ? null : $sourceArticleId,
                'working_revision_id' => $article->working_revision_id ?: $revisionId,
                'published_revision_id' => $article->published_revision_id ?: ($this->isPublished($article) ? $revisionId : null),
            ]);
    }

    private function resolveSourceArticleId(object $article): int
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

    private function findArticle(int $articleId): ?object
    {
        if ($articleId <= 0) {
            return null;
        }

        return DB::table('articles')
            ->where('id', $articleId)
            ->first([
                'id',
                'locale',
                'translation_group_id',
                'source_locale',
                'source_version_hash',
            ]);
    }

    private function resolveCurrentSourceVersionHash(object $article, ?object $sourceArticle): ?string
    {
        if ($sourceArticle && filled($sourceArticle->source_version_hash ?? null)) {
            return (string) $sourceArticle->source_version_hash;
        }

        if (filled($article->source_version_hash ?? null)) {
            return (string) $article->source_version_hash;
        }

        return null;
    }

    private function resolveTranslationGroupId(object $article, ?object $sourceArticle): string
    {
        if (filled($article->translation_group_id ?? null)) {
            return (string) $article->translation_group_id;
        }

        if ($sourceArticle && filled($sourceArticle->translation_group_id ?? null)) {
            return (string) $sourceArticle->translation_group_id;
        }

        return 'article-'.$article->id;
    }

    private function resolveSourceLocale(object $article, ?object $sourceArticle): string
    {
        if (filled($article->source_locale ?? null)) {
            return (string) $article->source_locale;
        }

        if ($sourceArticle && filled($sourceArticle->source_locale ?? null)) {
            return (string) $sourceArticle->source_locale;
        }

        if ($sourceArticle && filled($sourceArticle->locale ?? null)) {
            return (string) $sourceArticle->locale;
        }

        return $this->normalizeLocale($article->locale ?? null);
    }

    private function normalizeRevisionStatus(?string $status, object $article, int $sourceArticleId): string
    {
        $status = trim((string) $status);
        $allowed = ['source', 'machine_draft', 'human_review', 'approved', 'published', 'stale', 'archived'];

        if (in_array($status, $allowed, true)) {
            return $status;
        }

        return $this->isSourceArticle($article, $sourceArticleId) ? 'source' : 'machine_draft';
    }

    private function resolveTranslatedFromVersionHash(object $article, ?string $sourceVersionHash, int $sourceArticleId): ?string
    {
        if ($this->isSourceArticle($article, $sourceArticleId)) {
            return $sourceVersionHash;
        }

        if (filled($article->translated_from_version_hash ?? null)) {
            return (string) $article->translated_from_version_hash;
        }

        return $sourceVersionHash;
    }

    private function isSourceArticle(object $article, int $sourceArticleId): bool
    {
        return (int) $article->id === $sourceArticleId;
    }

    private function isPublished(object $article): bool
    {
        return (string) ($article->status ?? '') === 'published'
            && (bool) ($article->is_public ?? false)
            && filled($article->published_at ?? null);
    }

    private function findSeoMeta(int $articleId, string $locale): ?object
    {
        return DB::table('article_seo_meta')
            ->where('article_id', $articleId)
            ->where('locale', $locale)
            ->first(['seo_title', 'seo_description']);
    }

    private function normalizeLocale(?string $locale): string
    {
        $normalized = trim((string) $locale);

        return $normalized !== '' ? $normalized : 'zh-CN';
    }

    private function addArticlePointerIndexes(): void
    {
        if (Schema::hasColumn('articles', 'source_article_id')
            && ! $this->indexExists('articles', 'articles_source_article_idx')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->index('source_article_id', 'articles_source_article_idx');
            });
        }

        if (Schema::hasColumn('articles', 'working_revision_id')
            && ! $this->indexExists('articles', 'articles_working_revision_idx')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->index('working_revision_id', 'articles_working_revision_idx');
            });
        }

        if (Schema::hasColumn('articles', 'published_revision_id')
            && ! $this->indexExists('articles', 'articles_published_revision_idx')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->index('published_revision_id', 'articles_published_revision_idx');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]);
            foreach ($rows as $row) {
                if ((string) ($row->indexname ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [DB::getDatabaseName(), $table, $indexName]
        );

        return ! empty($rows);
    }
};
