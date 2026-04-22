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
        Schema::table('articles', function (Blueprint $table) {
            if (! Schema::hasColumn('articles', 'translation_group_id')) {
                $table->string('translation_group_id', 64)->nullable()->after('locale');
            }
            if (! Schema::hasColumn('articles', 'source_locale')) {
                $table->string('source_locale', 16)->nullable()->after('translation_group_id');
            }
            if (! Schema::hasColumn('articles', 'translation_status')) {
                $table->string('translation_status', 32)->default('source')->after('source_locale');
            }
            if (! Schema::hasColumn('articles', 'translated_from_article_id')) {
                $table->unsignedBigInteger('translated_from_article_id')->nullable()->after('translation_status');
            }
            if (! Schema::hasColumn('articles', 'source_version_hash')) {
                $table->string('source_version_hash', 64)->nullable()->after('translated_from_article_id');
            }
            if (! Schema::hasColumn('articles', 'translated_from_version_hash')) {
                $table->string('translated_from_version_hash', 64)->nullable()->after('source_version_hash');
            }
        });

        $this->backfillExistingArticles();
        $this->addIndexes();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function backfillExistingArticles(): void
    {
        DB::table('articles')
            ->select([
                'id',
                'locale',
                'title',
                'excerpt',
                'content_md',
                'content_html',
                'cover_image_alt',
                'related_test_slug',
                'voice',
                'voice_order',
                'translation_group_id',
                'source_locale',
                'translation_status',
                'source_version_hash',
            ])
            ->where(function ($query): void {
                $query
                    ->whereNull('translation_group_id')
                    ->orWhereNull('source_locale')
                    ->orWhereNull('translation_status')
                    ->orWhereNull('source_version_hash');
            })
            ->orderBy('id')
            ->chunkById(100, function ($articles): void {
                foreach ($articles as $article) {
                    $locale = $this->normalizeLocale($article->locale ?? null);

                    DB::table('articles')
                        ->where('id', $article->id)
                        ->update([
                            'translation_group_id' => $article->translation_group_id ?: 'article-'.$article->id,
                            'source_locale' => $article->source_locale ?: $locale,
                            'translation_status' => $article->translation_status ?: 'source',
                            'translated_from_article_id' => null,
                            'source_version_hash' => $article->source_version_hash ?: $this->sourceVersionHash([
                                'locale' => $locale,
                                'title' => $article->title,
                                'excerpt' => $article->excerpt,
                                'content_md' => $article->content_md,
                                'content_html' => $article->content_html,
                                'cover_image_alt' => $article->cover_image_alt,
                                'related_test_slug' => $article->related_test_slug,
                                'voice' => $article->voice,
                                'voice_order' => $article->voice_order,
                            ]),
                        ]);
                }
            });
    }

    private function addIndexes(): void
    {
        if (Schema::hasColumn('articles', 'translation_group_id')
            && ! $this->indexExists('articles', 'articles_translation_group_idx')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->index('translation_group_id', 'articles_translation_group_idx');
            });
        }

        if (Schema::hasColumn('articles', 'translated_from_article_id')
            && ! $this->indexExists('articles', 'articles_translated_from_idx')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->index('translated_from_article_id', 'articles_translated_from_idx');
            });
        }

        if (Schema::hasColumn('articles', 'source_locale')
            && Schema::hasColumn('articles', 'translation_status')
            && ! $this->indexExists('articles', 'articles_translation_state_idx')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->index(['source_locale', 'translation_status'], 'articles_translation_state_idx');
            });
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sourceVersionHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeLocale(?string $locale): string
    {
        $normalized = trim((string) $locale);

        return $normalized !== '' ? $normalized : 'zh-CN';
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
