<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODELS_TABLE = 'scoring_models';
    private const ROLLOUTS_TABLE = 'scoring_model_rollouts';

    public function up(): void
    {
        $this->createOrConvergeModelsTable();
        $this->createOrConvergeRolloutsTable();
        $this->ensureIndexes();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createOrConvergeModelsTable(): void
    {
        if (! Schema::hasTable(self::MODELS_TABLE)) {
            Schema::create(self::MODELS_TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('scale_code', 64);
                $table->string('model_key', 64);
                $table->string('driver_type', 32)->nullable();
                $table->string('scoring_spec_version', 64)->nullable();
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->json('config_json')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::MODELS_TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::MODELS_TABLE, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'scale_code')) {
                $table->string('scale_code', 64);
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'model_key')) {
                $table->string('model_key', 64);
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'driver_type')) {
                $table->string('driver_type', 32)->nullable();
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'scoring_spec_version')) {
                $table->string('scoring_spec_version', 64)->nullable();
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'priority')) {
                $table->integer('priority')->default(100);
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'config_json')) {
                $table->json('config_json')->nullable();
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::MODELS_TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function createOrConvergeRolloutsTable(): void
    {
        if (! Schema::hasTable(self::ROLLOUTS_TABLE)) {
            Schema::create(self::ROLLOUTS_TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('scale_code', 64);
                $table->string('model_key', 64);
                $table->string('experiment_key', 64)->nullable();
                $table->string('experiment_variant', 64)->nullable();
                $table->unsignedTinyInteger('rollout_percent')->default(100);
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::ROLLOUTS_TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'scale_code')) {
                $table->string('scale_code', 64);
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'model_key')) {
                $table->string('model_key', 64);
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'experiment_key')) {
                $table->string('experiment_key', 64)->nullable();
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'experiment_variant')) {
                $table->string('experiment_variant', 64)->nullable();
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'rollout_percent')) {
                $table->unsignedTinyInteger('rollout_percent')->default(100);
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'priority')) {
                $table->integer('priority')->default(100);
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'starts_at')) {
                $table->timestamp('starts_at')->nullable();
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'ends_at')) {
                $table->timestamp('ends_at')->nullable();
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::ROLLOUTS_TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function ensureIndexes(): void
    {
        $this->ensureUnique(
            self::MODELS_TABLE,
            ['org_id', 'scale_code', 'model_key'],
            'scoring_models_org_scale_model_unique'
        );
        $this->ensureIndex(
            self::MODELS_TABLE,
            ['org_id', 'scale_code', 'is_active', 'priority'],
            'scoring_models_org_scale_active_priority_idx'
        );

        $this->ensureIndex(
            self::ROLLOUTS_TABLE,
            ['org_id', 'scale_code', 'is_active', 'priority'],
            'scoring_model_rollouts_org_scale_active_priority_idx'
        );
        $this->ensureIndex(
            self::ROLLOUTS_TABLE,
            ['org_id', 'scale_code', 'experiment_key', 'experiment_variant'],
            'scoring_model_rollouts_org_scale_exp_idx'
        );
        $this->ensureIndex(
            self::ROLLOUTS_TABLE,
            ['org_id', 'scale_code', 'model_key'],
            'scoring_model_rollouts_org_scale_model_idx'
        );
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
};

