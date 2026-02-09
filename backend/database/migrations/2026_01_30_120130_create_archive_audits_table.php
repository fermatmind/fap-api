<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('archive_audits')) {
            Schema::create('archive_audits', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('table_name', 64);
                $table->string('range_start', 32)->nullable();
                $table->string('range_end', 32)->nullable();
                $table->string('object_uri', 255)->nullable();
                $table->integer('row_count')->default(0);
                $table->string('checksum', 64)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['table_name'], 'archive_audits_table_idx');
                $table->index(['created_at'], 'archive_audits_created_idx');
            });

            return;
        }

        Schema::table('archive_audits', function (Blueprint $table) {
            if (!Schema::hasColumn('archive_audits', 'table_name')) {
                $table->string('table_name', 64);
            }
            if (!Schema::hasColumn('archive_audits', 'range_start')) {
                $table->string('range_start', 32)->nullable();
            }
            if (!Schema::hasColumn('archive_audits', 'range_end')) {
                $table->string('range_end', 32)->nullable();
            }
            if (!Schema::hasColumn('archive_audits', 'object_uri')) {
                $table->string('object_uri', 255)->nullable();
            }
            if (!Schema::hasColumn('archive_audits', 'row_count')) {
                $table->integer('row_count')->default(0);
            }
            if (!Schema::hasColumn('archive_audits', 'checksum')) {
                $table->string('checksum', 64)->nullable();
            }
            if (!Schema::hasColumn('archive_audits', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
        });

        if (!$this->indexExists('archive_audits', 'archive_audits_table_idx')) {
            Schema::table('archive_audits', function (Blueprint $table) {
                $table->index(['table_name'], 'archive_audits_table_idx');
            });
        }
        if (!$this->indexExists('archive_audits', 'archive_audits_created_idx')) {
            Schema::table('archive_audits', function (Blueprint $table) {
                $table->index(['created_at'], 'archive_audits_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
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

        $db = DB::getDatabaseName();
        $rows = DB::select(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1",
            [$db, $table, $indexName]
        );

        return !empty($rows);
    }
};
