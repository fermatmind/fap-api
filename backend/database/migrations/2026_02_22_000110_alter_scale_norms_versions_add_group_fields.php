<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scale_norms_versions')) {
            return;
        }

        Schema::table('scale_norms_versions', function (Blueprint $table) {
            if (!Schema::hasColumn('scale_norms_versions', 'group_id')) {
                $table->string('group_id', 128)->nullable()->after('version');
            }
            if (!Schema::hasColumn('scale_norms_versions', 'gender')) {
                $table->string('gender', 16)->nullable()->after('group_id');
            }
            if (!Schema::hasColumn('scale_norms_versions', 'age_min')) {
                $table->integer('age_min')->nullable()->after('gender');
            }
            if (!Schema::hasColumn('scale_norms_versions', 'age_max')) {
                $table->integer('age_max')->nullable()->after('age_min');
            }
            if (!Schema::hasColumn('scale_norms_versions', 'source_id')) {
                $table->string('source_id', 128)->nullable()->after('age_max');
            }
            if (!Schema::hasColumn('scale_norms_versions', 'source_type')) {
                $table->string('source_type', 32)->nullable()->after('source_id');
            }
            if (!Schema::hasColumn('scale_norms_versions', 'status')) {
                $table->string('status', 32)->nullable()->after('source_type');
            }
            if (!Schema::hasColumn('scale_norms_versions', 'is_active')) {
                $table->boolean('is_active')->default(false)->after('status');
            }
            if (!Schema::hasColumn('scale_norms_versions', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('scale_norms_versions', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });

        $uniq = 'scale_norms_versions_scale_locale_region_group_version_uniq';
        if (!$this->indexExists('scale_norms_versions', $uniq)) {
            Schema::table('scale_norms_versions', function (Blueprint $table) use ($uniq) {
                $table->unique(['scale_code', 'locale', 'region', 'group_id', 'version'], $uniq);
            });
        }

        $idx = 'scale_norms_versions_scale_locale_region_group_active_idx';
        if (!$this->indexExists('scale_norms_versions', $idx)) {
            Schema::table('scale_norms_versions', function (Blueprint $table) use ($idx) {
                $table->index(['scale_code', 'locale', 'region', 'group_id', 'is_active'], $idx);
            });
        }

        $sourceIdx = 'scale_norms_versions_source_id_idx';
        if (!$this->indexExists('scale_norms_versions', $sourceIdx)) {
            Schema::table('scale_norms_versions', function (Blueprint $table) use ($sourceIdx) {
                $table->index('source_id', $sourceIdx);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $rows = DB::select("SHOW INDEX FROM `{$table}`");
            foreach ($rows as $row) {
                if ((string) ($row->Key_name ?? '') === $indexName) {
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

        return false;
    }
};
