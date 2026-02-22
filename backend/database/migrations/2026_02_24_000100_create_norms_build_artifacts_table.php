<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'norms_build_artifacts';

    private const IDX_GROUP = 'norms_build_artifacts_group_idx';

    private const IDX_VERSION = 'norms_build_artifacts_version_idx';

    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) use ($isSqlite): void {
                $table->uuid('id')->primary();
                $table->string('scale_code', 32)->default('BIG5_OCEAN');
                $table->string('norms_version', 128);
                $table->string('source_id', 128);
                $table->string('source_type', 32)->default('open_dataset');
                $table->string('pack_locale', 16)->default('en');
                $table->string('group_id', 128);
                $table->unsignedInteger('sample_n_raw')->default(0);
                $table->unsignedInteger('sample_n_kept')->default(0);
                if ($isSqlite) {
                    $table->text('filters_applied')->nullable();
                } else {
                    $table->json('filters_applied')->nullable();
                }
                $table->string('compute_spec_hash', 64)->nullable();
                $table->string('output_csv_sha256', 64)->nullable();
                $table->text('output_csv_path')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($isSqlite): void {
            if (!Schema::hasColumn(self::TABLE, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'scale_code')) {
                $table->string('scale_code', 32)->default('BIG5_OCEAN');
            }
            if (!Schema::hasColumn(self::TABLE, 'norms_version')) {
                $table->string('norms_version', 128)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'source_id')) {
                $table->string('source_id', 128)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'source_type')) {
                $table->string('source_type', 32)->default('open_dataset');
            }
            if (!Schema::hasColumn(self::TABLE, 'pack_locale')) {
                $table->string('pack_locale', 16)->default('en');
            }
            if (!Schema::hasColumn(self::TABLE, 'group_id')) {
                $table->string('group_id', 128)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'sample_n_raw')) {
                $table->unsignedInteger('sample_n_raw')->default(0);
            }
            if (!Schema::hasColumn(self::TABLE, 'sample_n_kept')) {
                $table->unsignedInteger('sample_n_kept')->default(0);
            }
            if (!Schema::hasColumn(self::TABLE, 'filters_applied')) {
                if ($isSqlite) {
                    $table->text('filters_applied')->nullable();
                } else {
                    $table->json('filters_applied')->nullable();
                }
            }
            if (!Schema::hasColumn(self::TABLE, 'compute_spec_hash')) {
                $table->string('compute_spec_hash', 64)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'output_csv_sha256')) {
                $table->string('output_csv_sha256', 64)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'output_csv_path')) {
                $table->text('output_csv_path')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (!SchemaIndex::indexExists(self::TABLE, self::IDX_GROUP)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['scale_code', 'pack_locale', 'group_id'], self::IDX_GROUP);
            });
        }

        if (!SchemaIndex::indexExists(self::TABLE, self::IDX_VERSION)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['scale_code', 'norms_version'], self::IDX_VERSION);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
