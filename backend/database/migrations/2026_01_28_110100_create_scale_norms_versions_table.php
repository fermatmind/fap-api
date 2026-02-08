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

        if (!Schema::hasTable('scale_norms_versions')) {
            Schema::create('scale_norms_versions', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->string('scale_code', 32);
                $table->string('norm_id', 64);
                $table->string('region', 32)->nullable();
                $table->string('locale', 16)->nullable();
                $table->string('version', 32);
                $table->string('checksum', 128)->nullable();
                if ($isSqlite) {
                    $table->text('meta_json')->nullable();
                } else {
                    $table->json('meta_json')->nullable();
                }
                $table->timestamp('created_at')->nullable();

                $table->index('scale_code', 'scale_norms_versions_scale_code_idx');
                $table->index('region', 'scale_norms_versions_region_idx');
                $table->index('locale', 'scale_norms_versions_locale_idx');
                $table->index('version', 'scale_norms_versions_version_idx');
                $table->unique(
                    ['scale_code', 'norm_id', 'region', 'locale', 'version'],
                    'scale_norms_versions_unique'
                );
            });

            return;
        }

        Schema::table('scale_norms_versions', function (Blueprint $table) use ($isSqlite) {
            if (!Schema::hasColumn('scale_norms_versions', 'id')) {
                $table->uuid('id')->primary();
            }
            if (!Schema::hasColumn('scale_norms_versions', 'scale_code')) {
                $table->string('scale_code', 32);
            }
            if (!Schema::hasColumn('scale_norms_versions', 'norm_id')) {
                $table->string('norm_id', 64);
            }
            if (!Schema::hasColumn('scale_norms_versions', 'region')) {
                $table->string('region', 32)->nullable();
            }
            if (!Schema::hasColumn('scale_norms_versions', 'locale')) {
                $table->string('locale', 16)->nullable();
            }
            if (!Schema::hasColumn('scale_norms_versions', 'version')) {
                $table->string('version', 32);
            }
            if (!Schema::hasColumn('scale_norms_versions', 'checksum')) {
                $table->string('checksum', 128)->nullable();
            }
            if (!Schema::hasColumn('scale_norms_versions', 'meta_json')) {
                if ($isSqlite) {
                    $table->text('meta_json')->nullable();
                } else {
                    $table->json('meta_json')->nullable();
                }
            }
            if (!Schema::hasColumn('scale_norms_versions', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
        });

        $indexes = [
            'scale_norms_versions_scale_code_idx' => ['scale_code'],
            'scale_norms_versions_region_idx' => ['region'],
            'scale_norms_versions_locale_idx' => ['locale'],
            'scale_norms_versions_version_idx' => ['version'],
        ];

        foreach ($indexes as $name => $columns) {
            if (!$this->indexExists('scale_norms_versions', $name)) {
                Schema::table('scale_norms_versions', function (Blueprint $table) use ($columns, $name) {
                    $table->index($columns, $name);
                });
            }
        }

        $uniqueName = 'scale_norms_versions_unique';
        if (!$this->indexExists('scale_norms_versions', $uniqueName)) {
            Schema::table('scale_norms_versions', function (Blueprint $table) use ($uniqueName) {
                $table->unique(
                    ['scale_code', 'norm_id', 'region', 'locale', 'version'],
                    $uniqueName
                );
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('scale_norms_versions');
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
