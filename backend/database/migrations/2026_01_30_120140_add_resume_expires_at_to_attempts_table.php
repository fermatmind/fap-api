<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attempts')) {
            return;
        }

        Schema::table('attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('attempts', 'resume_expires_at')) {
                $table->timestamp('resume_expires_at')->nullable();
            }
        });

        if (!$this->indexExists('attempts', 'attempts_resume_expires_idx')) {
            Schema::table('attempts', function (Blueprint $table) {
                $table->index(['resume_expires_at'], 'attempts_resume_expires_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('attempts')) {
            return;
        }

        if ($this->indexExists('attempts', 'attempts_resume_expires_idx')) {
            Schema::table('attempts', function (Blueprint $table) {
                $table->dropIndex('attempts_resume_expires_idx');
            });
        }

        Schema::table('attempts', function (Blueprint $table) {
            if (Schema::hasColumn('attempts', 'resume_expires_at')) {
                $table->dropColumn('resume_expires_at');
            }
        });
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
