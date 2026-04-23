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
        $this->ensureColumns('support_articles');
        $this->ensureColumns('interpretation_guides');
        $this->ensureColumns('content_pages');

        $this->backfillTable('support_articles', 'support');
        $this->backfillTable('interpretation_guides', 'interpretation');
        $this->backfillTable('content_pages', 'content_page');
    }

    public function down(): void
    {
        // forward-only migration by repository policy
    }

    private function ensureColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (! Schema::hasColumn($table, 'translation_group_id')) {
                $blueprint->string('translation_group_id', 64)->nullable()->after('locale');
            }
            if (! Schema::hasColumn($table, 'source_locale')) {
                $blueprint->string('source_locale', 16)->nullable()->after('translation_group_id');
            }
            if (! Schema::hasColumn($table, 'translation_status')) {
                $blueprint->string('translation_status', 32)->default('source')->after('source_locale');
            }
            if (! Schema::hasColumn($table, 'source_content_id')) {
                $blueprint->unsignedBigInteger('source_content_id')->nullable()->after('translation_status');
            }
            if (! Schema::hasColumn($table, 'source_version_hash')) {
                $blueprint->string('source_version_hash', 64)->nullable()->after('source_content_id');
            }
            if (! Schema::hasColumn($table, 'translated_from_version_hash')) {
                $blueprint->string('translated_from_version_hash', 64)->nullable()->after('source_version_hash');
            }
        });

        $this->ensureIndex($table, "{$table}_translation_group_idx", function (Blueprint $blueprint) use ($table): void {
            $blueprint->index('translation_group_id', "{$table}_translation_group_idx");
        });
        $this->ensureIndex($table, "{$table}_source_content_idx", function (Blueprint $blueprint) use ($table): void {
            $blueprint->index('source_content_id', "{$table}_source_content_idx");
        });
        $this->ensureIndex($table, "{$table}_translation_state_idx", function (Blueprint $blueprint) use ($table): void {
            $blueprint->index(['source_locale', 'translation_status'], "{$table}_translation_state_idx");
        });
    }

    /**
     * @param  \Closure(\Illuminate\Database\Schema\Blueprint):void  $callback
     */
    private function ensureIndex(string $table, string $indexName, \Closure $callback): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, $callback);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => ! empty(DB::select(
                'select name from sqlite_master where type = ? and tbl_name = ? and name = ? limit 1',
                ['index', $table, $indexName],
            )),
            'mysql', 'mariadb' => ! empty(DB::select(
                sprintf('show index from `%s` where Key_name = ?', str_replace('`', '``', $table)),
                [$indexName],
            )),
            'pgsql' => ! empty(DB::select(
                'select indexname from pg_indexes where schemaname = current_schema() and tablename = ? and indexname = ? limit 1',
                [$table, $indexName],
            )),
            default => false,
        };
    }

    private function backfillTable(string $table, string $prefix): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $rows = DB::table($table)
            ->select(['id', 'slug', 'locale', 'status', 'review_state', 'title', 'summary', 'translation_group_id', 'source_locale', 'translation_status', 'source_content_id', 'source_version_hash', 'translated_from_version_hash'])
            ->orderBy('slug')
            ->orderBy('locale')
            ->orderBy('id')
            ->get()
            ->groupBy('slug');

        foreach ($rows as $slugRows) {
            $sourceRow = $slugRows->firstWhere('locale', 'zh-CN')
                ?? $slugRows->firstWhere('locale', 'en')
                ?? $slugRows->sortBy('id')->first();

            if (! $sourceRow) {
                continue;
            }

            $groupId = filled($sourceRow->translation_group_id)
                ? (string) $sourceRow->translation_group_id
                : sprintf('%s-%d', $prefix, (int) $sourceRow->id);
            $sourceLocale = (string) ($sourceRow->locale ?: 'en');
            $sourceHash = $this->sourceHash([
                'slug' => (string) ($sourceRow->slug ?? ''),
                'locale' => $sourceLocale,
                'title' => (string) ($sourceRow->title ?? ''),
                'summary' => (string) ($sourceRow->summary ?? ''),
                'status' => (string) ($sourceRow->status ?? ''),
                'review_state' => (string) ($sourceRow->review_state ?? ''),
            ]);

            foreach ($slugRows as $row) {
                $isSource = (int) $row->id === (int) $sourceRow->id;

                DB::table($table)
                    ->where('id', (int) $row->id)
                    ->update([
                        'translation_group_id' => $groupId,
                        'source_locale' => $sourceLocale,
                        'translation_status' => $isSource
                            ? 'source'
                            : $this->translationStatusFor(
                                (string) ($row->status ?? ''),
                                (string) ($row->review_state ?? '')
                            ),
                        'source_content_id' => $isSource ? null : (int) $sourceRow->id,
                        'source_version_hash' => $sourceHash,
                        'translated_from_version_hash' => $isSource ? null : ($row->translated_from_version_hash ?: $sourceHash),
                    ]);
            }
        }
    }

    private function translationStatusFor(string $status, string $reviewState): string
    {
        $status = strtolower(trim($status));
        $reviewState = strtolower(trim($reviewState));

        return match (true) {
            $status === 'published' => 'published',
            str_contains($reviewState, 'approved') => 'approved',
            $reviewState !== '' && $reviewState !== 'draft' => 'human_review',
            $status === 'archived' => 'archived',
            default => 'draft',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sourceHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
};
