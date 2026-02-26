<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TASKS_TABLE = 'dsar_request_tasks';

    private const AUDIT_TABLE = 'dsar_audit_logs';

    public function up(): void
    {
        $this->createOrConvergeTasksTable();
        $this->createOrConvergeAuditTable();
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function createOrConvergeTasksTable(): void
    {
        if (! Schema::hasTable(self::TASKS_TABLE)) {
            Schema::create(self::TASKS_TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('request_id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->unsignedBigInteger('subject_user_id')->nullable();
                $table->string('domain', 64);
                $table->string('action', 64)->default('pseudonymize');
                $table->string('status', 24)->default('pending');
                $table->string('error_code', 64)->nullable();
                $table->json('stats_json')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        } else {
            Schema::table(self::TASKS_TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::TASKS_TABLE, 'id')) {
                    $table->uuid('id')->nullable();
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'request_id')) {
                    $table->uuid('request_id')->nullable();
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'subject_user_id')) {
                    $table->unsignedBigInteger('subject_user_id')->nullable();
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'domain')) {
                    $table->string('domain', 64)->nullable();
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'action')) {
                    $table->string('action', 64)->default('pseudonymize');
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'status')) {
                    $table->string('status', 24)->default('pending');
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'error_code')) {
                    $table->string('error_code', 64)->nullable();
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'stats_json')) {
                    $table->json('stats_json')->nullable();
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'started_at')) {
                    $table->timestamp('started_at')->nullable();
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'finished_at')) {
                    $table->timestamp('finished_at')->nullable();
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::TASKS_TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureIndex(self::TASKS_TABLE, ['request_id', 'status'], 'dsar_tasks_request_status_idx');
        $this->ensureIndex(self::TASKS_TABLE, ['org_id', 'subject_user_id'], 'dsar_tasks_org_subject_idx');
        $this->ensureIndex(self::TASKS_TABLE, ['domain', 'status'], 'dsar_tasks_domain_status_idx');
    }

    private function createOrConvergeAuditTable(): void
    {
        if (! Schema::hasTable(self::AUDIT_TABLE)) {
            Schema::create(self::AUDIT_TABLE, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->uuid('request_id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->unsignedBigInteger('subject_user_id')->nullable();
                $table->string('event_type', 64);
                $table->string('level', 16)->default('info');
                $table->string('message', 255)->nullable();
                $table->json('context_json')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        } else {
            Schema::table(self::AUDIT_TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'id')) {
                    $table->bigIncrements('id');
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'request_id')) {
                    $table->uuid('request_id')->nullable();
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'subject_user_id')) {
                    $table->unsignedBigInteger('subject_user_id')->nullable();
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'event_type')) {
                    $table->string('event_type', 64)->nullable();
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'level')) {
                    $table->string('level', 16)->default('info');
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'message')) {
                    $table->string('message', 255)->nullable();
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'context_json')) {
                    $table->json('context_json')->nullable();
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'occurred_at')) {
                    $table->timestamp('occurred_at')->nullable();
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::AUDIT_TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureIndex(self::AUDIT_TABLE, ['request_id', 'occurred_at'], 'dsar_audit_request_time_idx');
        $this->ensureIndex(self::AUDIT_TABLE, ['org_id', 'subject_user_id'], 'dsar_audit_org_subject_idx');
        $this->ensureIndex(self::AUDIT_TABLE, ['event_type', 'occurred_at'], 'dsar_audit_event_time_idx');
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

