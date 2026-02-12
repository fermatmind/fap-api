<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scale_slugs')) {
            Schema::create('scale_slugs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('slug', 127);
                $table->string('scale_code', 64);
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->unique(['org_id', 'slug'], 'scale_slugs_org_slug_unique');
                $table->index('scale_code', 'scale_slugs_scale_code_idx');
                $table->index('is_primary', 'scale_slugs_is_primary_idx');
                $table->index('org_id', 'scale_slugs_org_id_idx');
            });

            return;
        }

        Schema::table('scale_slugs', function (Blueprint $table) {
            if (!Schema::hasColumn('scale_slugs', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('scale_slugs', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('scale_slugs', 'slug')) {
                $table->string('slug', 127);
            }
            if (!Schema::hasColumn('scale_slugs', 'scale_code')) {
                $table->string('scale_code', 64);
            }
            if (!Schema::hasColumn('scale_slugs', 'is_primary')) {
                $table->boolean('is_primary')->default(false);
            }
            if (!Schema::hasColumn('scale_slugs', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('scale_slugs', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        $uniqueName = 'scale_slugs_org_slug_unique';
        if (
            Schema::hasColumn('scale_slugs', 'org_id')
            && Schema::hasColumn('scale_slugs', 'slug')
            && !$this->indexExists('scale_slugs', $uniqueName)
        ) {
            Schema::table('scale_slugs', function (Blueprint $table) use ($uniqueName) {
                $table->unique(['org_id', 'slug'], $uniqueName);
            });
        }

        $scaleIdx = 'scale_slugs_scale_code_idx';
        if (Schema::hasColumn('scale_slugs', 'scale_code') && !$this->indexExists('scale_slugs', $scaleIdx)) {
            Schema::table('scale_slugs', function (Blueprint $table) use ($scaleIdx) {
                $table->index('scale_code', $scaleIdx);
            });
        }

        $primaryIdx = 'scale_slugs_is_primary_idx';
        if (Schema::hasColumn('scale_slugs', 'is_primary') && !$this->indexExists('scale_slugs', $primaryIdx)) {
            Schema::table('scale_slugs', function (Blueprint $table) use ($primaryIdx) {
                $table->index('is_primary', $primaryIdx);
            });
        }

        $orgIdx = 'scale_slugs_org_id_idx';
        if (Schema::hasColumn('scale_slugs', 'org_id') && !$this->indexExists('scale_slugs', $orgIdx)) {
            Schema::table('scale_slugs', function (Blueprint $table) use ($orgIdx) {
                $table->index('org_id', $orgIdx);
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
