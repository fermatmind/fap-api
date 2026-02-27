<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDITS_TABLE = 'experiment_rollout_audits';

    private const GUARDRAILS_TABLE = 'experiment_guardrails';

    public function up(): void
    {
        $this->createOrConvergeAuditsTable();
        $this->createOrConvergeGuardrailsTable();
        $this->ensureIndexes();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createOrConvergeAuditsTable(): void
    {
        if (! Schema::hasTable(self::AUDITS_TABLE)) {
            Schema::create(self::AUDITS_TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->uuid('rollout_id')->nullable();
                $table->string('experiment_key', 64)->nullable();
                $table->string('action', 32);
                $table->string('status', 24)->default('ok');
                $table->string('reason', 191)->nullable();
                $table->json('meta_json')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::AUDITS_TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'rollout_id')) {
                $table->uuid('rollout_id')->nullable();
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'experiment_key')) {
                $table->string('experiment_key', 64)->nullable();
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'action')) {
                $table->string('action', 32)->nullable();
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'status')) {
                $table->string('status', 24)->default('ok');
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'reason')) {
                $table->string('reason', 191)->nullable();
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'meta_json')) {
                $table->json('meta_json')->nullable();
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'actor_user_id')) {
                $table->unsignedBigInteger('actor_user_id')->nullable();
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::AUDITS_TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function createOrConvergeGuardrailsTable(): void
    {
        if (! Schema::hasTable(self::GUARDRAILS_TABLE)) {
            Schema::create(self::GUARDRAILS_TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->uuid('rollout_id');
                $table->string('experiment_key', 64)->nullable();
                $table->string('metric_key', 64);
                $table->string('operator', 16)->default('gte');
                $table->decimal('threshold', 14, 6)->default(0);
                $table->unsignedInteger('window_minutes')->default(60);
                $table->unsignedInteger('min_sample_size')->default(30);
                $table->boolean('auto_rollback')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_evaluated_at')->nullable();
                $table->timestamp('last_triggered_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table(self::GUARDRAILS_TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'id')) {
                $table->uuid('id')->nullable();
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'rollout_id')) {
                $table->uuid('rollout_id')->nullable();
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'experiment_key')) {
                $table->string('experiment_key', 64)->nullable();
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'metric_key')) {
                $table->string('metric_key', 64)->nullable();
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'operator')) {
                $table->string('operator', 16)->default('gte');
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'threshold')) {
                $table->decimal('threshold', 14, 6)->default(0);
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'window_minutes')) {
                $table->unsignedInteger('window_minutes')->default(60);
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'min_sample_size')) {
                $table->unsignedInteger('min_sample_size')->default(30);
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'auto_rollback')) {
                $table->boolean('auto_rollback')->default(true);
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'last_evaluated_at')) {
                $table->timestamp('last_evaluated_at')->nullable();
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'last_triggered_at')) {
                $table->timestamp('last_triggered_at')->nullable();
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn(self::GUARDRAILS_TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function ensureIndexes(): void
    {
        $this->ensureIndex(
            self::AUDITS_TABLE,
            ['org_id', 'rollout_id', 'created_at'],
            'exp_rollout_audits_org_rollout_created_idx'
        );
        $this->ensureIndex(
            self::AUDITS_TABLE,
            ['org_id', 'experiment_key', 'created_at'],
            'exp_rollout_audits_org_exp_created_idx'
        );
        $this->ensureIndex(
            self::AUDITS_TABLE,
            ['org_id', 'action', 'created_at'],
            'exp_rollout_audits_org_action_created_idx'
        );

        $this->ensureUnique(
            self::GUARDRAILS_TABLE,
            ['org_id', 'rollout_id', 'metric_key'],
            'exp_guardrails_org_rollout_metric_unique'
        );
        $this->ensureIndex(
            self::GUARDRAILS_TABLE,
            ['org_id', 'rollout_id', 'is_active'],
            'exp_guardrails_org_rollout_active_idx'
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
