<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        if (!Schema::hasTable('attempt_quality')) {
            Schema::create('attempt_quality', function (Blueprint $table) use ($isSqlite) {
                $table->bigIncrements('id');
                $table->string('attempt_id', 64);
                if ($isSqlite) {
                    $table->text('checks_json');
                } else {
                    $table->json('checks_json');
                }
                $table->string('grade', 4);
                $table->timestamp('created_at')->nullable();

                $table->unique('attempt_id', 'attempt_quality_attempt_id_unique');
            });

            return;
        }

        Schema::table('attempt_quality', function (Blueprint $table) use ($isSqlite) {
            if (!Schema::hasColumn('attempt_quality', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('attempt_quality', 'attempt_id')) {
                $table->string('attempt_id', 64);
            }
            if (!Schema::hasColumn('attempt_quality', 'checks_json')) {
                if ($isSqlite) {
                    $table->text('checks_json');
                } else {
                    $table->json('checks_json');
                }
            }
            if (!Schema::hasColumn('attempt_quality', 'grade')) {
                $table->string('grade', 4);
            }
            if (!Schema::hasColumn('attempt_quality', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
        });

        $uniqueName = 'attempt_quality_attempt_id_unique';
        if (!$this->indexExists('attempt_quality', $uniqueName)) {
            Schema::table('attempt_quality', function (Blueprint $table) use ($uniqueName) {
                $table->unique('attempt_id', $uniqueName);
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
