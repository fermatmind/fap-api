<?php

declare(strict_types=1);

use App\Models\ArticleTranslationRevision;
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
                'status',
                'is_public',
                'published_at',
                'working_revision_id',
                'published_revision_id',
            ])
            ->where('status', 'published')
            ->where('is_public', true)
            ->orderBy('id')
            ->chunkById(100, function ($articles): void {
                foreach ($articles as $article) {
                    $this->repairArticle($article);
                }
            });
    }

    public function down(): void
    {
        // Forward-only data repair. Rollback would risk disconnecting public article reads
        // from their published revision source of truth.
    }

    private function repairArticle(object $article): void
    {
        $revision = $this->resolveRevision($article);
        if (! $revision) {
            return;
        }

        $publishedAt = $revision->published_at ?? $article->published_at ?? now();

        DB::table('article_translation_revisions')
            ->where('id', (int) $revision->id)
            ->update([
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'published_at' => $publishedAt,
                'updated_at' => now(),
            ]);

        DB::table('articles')
            ->where('id', (int) $article->id)
            ->update([
                'published_revision_id' => (int) $revision->id,
                'updated_at' => now(),
            ]);
    }

    private function resolveRevision(object $article): ?object
    {
        $candidateIds = array_values(array_filter([
            (int) ($article->published_revision_id ?? 0),
            (int) ($article->working_revision_id ?? 0),
        ]));

        if ($candidateIds !== []) {
            $revision = DB::table('article_translation_revisions')
                ->whereIn('id', $candidateIds)
                ->where('article_id', (int) $article->id)
                ->where('org_id', (int) ($article->org_id ?? 0))
                ->where('locale', (string) ($article->locale ?? ''))
                ->where($this->publishableRevisionConstraint())
                ->orderByRaw('case when id = ? then 0 else 1 end', [(int) ($article->published_revision_id ?? 0)])
                ->first();

            if ($revision) {
                return $revision;
            }
        }

        return DB::table('article_translation_revisions')
            ->where('article_id', (int) $article->id)
            ->where('org_id', (int) ($article->org_id ?? 0))
            ->where('locale', (string) ($article->locale ?? ''))
            ->where($this->publishableRevisionConstraint())
            ->orderBy('revision_number')
            ->first();
    }

    private function publishableRevisionConstraint(): \Closure
    {
        return static function ($query): void {
            $query
                ->whereIn('revision_status', [
                    ArticleTranslationRevision::STATUS_APPROVED,
                    ArticleTranslationRevision::STATUS_PUBLISHED,
                ])
                ->orWhere(static function ($sourceQuery): void {
                    $sourceQuery
                        ->where('revision_status', ArticleTranslationRevision::STATUS_SOURCE)
                        ->whereColumn('article_id', 'source_article_id');
                });
        };
    }
};
