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

        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        Schema::table('attempts', function (Blueprint $table) use ($isSqlite) {
            if (!Schema::hasColumn('attempts', 'pack_id')) {
                $table->string('pack_id', 128)->nullable();
            }
            if (!Schema::hasColumn('attempts', 'dir_version')) {
                $table->string('dir_version', 128)->nullable();
            }
            if (!Schema::hasColumn('attempts', 'scoring_spec_version')) {
                $table->string('scoring_spec_version', 64)->nullable();
            }
            if (!Schema::hasColumn('attempts', 'norm_version')) {
                $table->string('norm_version', 64)->nullable();
            }
            if (!Schema::hasColumn('attempts', 'calculation_snapshot_json')) {
                if ($isSqlite) {
                    $table->text('calculation_snapshot_json')->nullable();
                } else {
                    $table->json('calculation_snapshot_json')->nullable();
                }
            }
        });

        $indexName = 'attempts_pack_norm_idx';
        if (!$this->indexExists('attempts', $indexName)) {
            Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                $table->index(['pack_id', 'norm_version'], $indexName);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('attempts')) {
            return;
        }

        $indexName = 'attempts_pack_norm_idx';
        if ($this->indexExists('attempts', $indexName)) {
            Schema::table('attempts', function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }

        Schema::table('attempts', function (Blueprint $table) {
            if (Schema::hasColumn('attempts', 'calculation_snapshot_json')) {
                $table->dropColumn('calculation_snapshot_json');
            }
            if (Schema::hasColumn('attempts', 'norm_version')) {
                $table->dropColumn('norm_version');
            }
            if (Schema::hasColumn('attempts', 'scoring_spec_version')) {
                $table->dropColumn('scoring_spec_version');
            }
            if (Schema::hasColumn('attempts', 'dir_version')) {
                $table->dropColumn('dir_version');
            }
            if (Schema::hasColumn('attempts', 'pack_id')) {
                $table->dropColumn('pack_id');
            }
        });
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
