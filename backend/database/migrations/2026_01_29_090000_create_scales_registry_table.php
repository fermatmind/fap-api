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

        if (!Schema::hasTable('scales_registry')) {
            Schema::create('scales_registry', function (Blueprint $table) use ($isSqlite) {
                $table->string('code', 64);
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('primary_slug', 127);
                if ($isSqlite) {
                    $table->text('slugs_json');
                } else {
                    $table->json('slugs_json');
                }
                $table->string('driver_type', 32);
                $table->string('default_pack_id', 64)->nullable();
                $table->string('default_region', 32)->nullable();
                $table->string('default_locale', 32)->nullable();
                $table->string('default_dir_version', 128)->nullable();
                if ($isSqlite) {
                    $table->text('capabilities_json')->nullable();
                } else {
                    $table->json('capabilities_json')->nullable();
                }
                if ($isSqlite) {
                    $table->text('view_policy_json')->nullable();
                } else {
                    $table->json('view_policy_json')->nullable();
                }
                if ($isSqlite) {
                    $table->text('commercial_json')->nullable();
                } else {
                    $table->json('commercial_json')->nullable();
                }
                if ($isSqlite) {
                    $table->text('seo_schema_json')->nullable();
                } else {
                    $table->json('seo_schema_json')->nullable();
                }
                $table->boolean('is_public')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->primary('code', 'scales_registry_code_pk');
                $table->unique(['org_id', 'primary_slug'], 'scales_registry_org_primary_slug_unique');
                $table->index('org_id', 'scales_registry_org_id_idx');
                $table->index('driver_type', 'scales_registry_driver_type_idx');
                $table->index('is_public', 'scales_registry_is_public_idx');
                $table->index('is_active', 'scales_registry_is_active_idx');
            });

            return;
        }

        Schema::table('scales_registry', function (Blueprint $table) use ($isSqlite) {
            if (!Schema::hasColumn('scales_registry', 'code')) {
                $table->string('code', 64);
            }
            if (!Schema::hasColumn('scales_registry', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('scales_registry', 'primary_slug')) {
                $table->string('primary_slug', 127);
            }
            if (!Schema::hasColumn('scales_registry', 'slugs_json')) {
                if ($isSqlite) {
                    $table->text('slugs_json');
                } else {
                    $table->json('slugs_json');
                }
            }
            if (!Schema::hasColumn('scales_registry', 'driver_type')) {
                $table->string('driver_type', 32);
            }
            if (!Schema::hasColumn('scales_registry', 'default_pack_id')) {
                $table->string('default_pack_id', 64)->nullable();
            }
            if (!Schema::hasColumn('scales_registry', 'default_region')) {
                $table->string('default_region', 32)->nullable();
            }
            if (!Schema::hasColumn('scales_registry', 'default_locale')) {
                $table->string('default_locale', 32)->nullable();
            }
            if (!Schema::hasColumn('scales_registry', 'default_dir_version')) {
                $table->string('default_dir_version', 128)->nullable();
            }
            if (!Schema::hasColumn('scales_registry', 'capabilities_json')) {
                if ($isSqlite) {
                    $table->text('capabilities_json')->nullable();
                } else {
                    $table->json('capabilities_json')->nullable();
                }
            }
            if (!Schema::hasColumn('scales_registry', 'view_policy_json')) {
                if ($isSqlite) {
                    $table->text('view_policy_json')->nullable();
                } else {
                    $table->json('view_policy_json')->nullable();
                }
            }
            if (!Schema::hasColumn('scales_registry', 'commercial_json')) {
                if ($isSqlite) {
                    $table->text('commercial_json')->nullable();
                } else {
                    $table->json('commercial_json')->nullable();
                }
            }
            if (!Schema::hasColumn('scales_registry', 'seo_schema_json')) {
                if ($isSqlite) {
                    $table->text('seo_schema_json')->nullable();
                } else {
                    $table->json('seo_schema_json')->nullable();
                }
            }
            if (!Schema::hasColumn('scales_registry', 'is_public')) {
                $table->boolean('is_public')->default(false);
            }
            if (!Schema::hasColumn('scales_registry', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (!Schema::hasColumn('scales_registry', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('scales_registry', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        $uniqueName = 'scales_registry_org_primary_slug_unique';
        if (
            Schema::hasColumn('scales_registry', 'org_id')
            && Schema::hasColumn('scales_registry', 'primary_slug')
            && !$this->indexExists('scales_registry', $uniqueName)
        ) {
            Schema::table('scales_registry', function (Blueprint $table) use ($uniqueName) {
                $table->unique(['org_id', 'primary_slug'], $uniqueName);
            });
        }

        $orgIdx = 'scales_registry_org_id_idx';
        if (Schema::hasColumn('scales_registry', 'org_id') && !$this->indexExists('scales_registry', $orgIdx)) {
            Schema::table('scales_registry', function (Blueprint $table) use ($orgIdx) {
                $table->index('org_id', $orgIdx);
            });
        }

        $driverIdx = 'scales_registry_driver_type_idx';
        if (Schema::hasColumn('scales_registry', 'driver_type') && !$this->indexExists('scales_registry', $driverIdx)) {
            Schema::table('scales_registry', function (Blueprint $table) use ($driverIdx) {
                $table->index('driver_type', $driverIdx);
            });
        }

        $publicIdx = 'scales_registry_is_public_idx';
        if (Schema::hasColumn('scales_registry', 'is_public') && !$this->indexExists('scales_registry', $publicIdx)) {
            Schema::table('scales_registry', function (Blueprint $table) use ($publicIdx) {
                $table->index('is_public', $publicIdx);
            });
        }

        $activeIdx = 'scales_registry_is_active_idx';
        if (Schema::hasColumn('scales_registry', 'is_active') && !$this->indexExists('scales_registry', $activeIdx)) {
            Schema::table('scales_registry', function (Blueprint $table) use ($activeIdx) {
                $table->index('is_active', $activeIdx);
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
