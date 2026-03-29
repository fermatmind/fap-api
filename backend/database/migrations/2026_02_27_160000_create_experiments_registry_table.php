<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'experiments_registry';

    public function up(): void
    {
        $this->createOrConvergeRegistryTable();
        $this->ensureIndexes();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createOrConvergeRegistryTable(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('experiment_key', 64);
                $table->string('stage', 32)->default('prod');
                $table->string('version', 32)->default('v1');
                $table->json('variants_json');
                $table->boolean('is_active')->default(true);
                $table->timestamp('active_from')->nullable();
                $table->timestamp('active_to')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::TABLE, 'experiment_key')) {
                $table->string('experiment_key', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'stage')) {
                $table->string('stage', 32)->default('prod');
            }
            if (! Schema::hasColumn(self::TABLE, 'version')) {
                $table->string('version', 32)->default('v1');
            }
            if (! Schema::hasColumn(self::TABLE, 'variants_json')) {
                $table->json('variants_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn(self::TABLE, 'active_from')) {
                $table->timestamp('active_from')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'active_to')) {
                $table->timestamp('active_to')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function ensureIndexes(): void
    {
        $this->ensureUnique(
            self::TABLE,
            ['org_id', 'experiment_key', 'stage', 'version'],
            'exp_registry_org_key_stage_version_uq'
        );
        $this->ensureIndex(
            self::TABLE,
            ['org_id', 'is_active', 'active_from', 'active_to'],
            'exp_registry_org_active_window_idx'
        );
        $this->ensureIndex(
            self::TABLE,
            ['org_id', 'experiment_key', 'is_active'],
            'exp_registry_org_key_active_idx'
        );
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || SchemaIndex::indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureUnique(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || SchemaIndex::indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->unique($columns, $indexName);
        });
    }
};
