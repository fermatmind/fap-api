<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scale_norm_stats')) {
            Schema::create('scale_norm_stats', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('norm_version_id');
                $table->string('metric_level', 16);
                $table->string('metric_code', 32);
                $table->decimal('mean', 8, 4);
                $table->decimal('sd', 8, 4);
                $table->integer('sample_n');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        Schema::table('scale_norm_stats', function (Blueprint $table) {
            if (!Schema::hasColumn('scale_norm_stats', 'id')) {
                $table->uuid('id')->nullable();
            }
            if (!Schema::hasColumn('scale_norm_stats', 'norm_version_id')) {
                $table->uuid('norm_version_id');
            }
            if (!Schema::hasColumn('scale_norm_stats', 'metric_level')) {
                $table->string('metric_level', 16)->nullable();
            }
            if (!Schema::hasColumn('scale_norm_stats', 'metric_code')) {
                $table->string('metric_code', 32)->nullable();
            }
            if (!Schema::hasColumn('scale_norm_stats', 'mean')) {
                $table->decimal('mean', 8, 4)->nullable();
            }
            if (!Schema::hasColumn('scale_norm_stats', 'sd')) {
                $table->decimal('sd', 8, 4)->nullable();
            }
            if (!Schema::hasColumn('scale_norm_stats', 'sample_n')) {
                $table->integer('sample_n')->nullable();
            }
            if (!Schema::hasColumn('scale_norm_stats', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('scale_norm_stats', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        $unique = 'scale_norm_stats_version_metric_uniq';
        if (!$this->indexExists('scale_norm_stats', $unique)) {
            Schema::table('scale_norm_stats', function (Blueprint $table) use ($unique) {
                $table->unique(['norm_version_id', 'metric_level', 'metric_code'], $unique);
            });
        }

        $index = 'scale_norm_stats_norm_version_id_idx';
        if (!$this->indexExists('scale_norm_stats', $index)) {
            Schema::table('scale_norm_stats', function (Blueprint $table) use ($index) {
                $table->index('norm_version_id', $index);
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
