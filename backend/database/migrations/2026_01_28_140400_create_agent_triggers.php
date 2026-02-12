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

        if (!Schema::hasTable('agent_triggers')) {
            Schema::create('agent_triggers', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('trigger_type', 64);
                $table->string('status', 32)->default('fired');
                $table->timestamp('fired_at')->nullable();
                $table->string('idempotency_key', 128)->nullable();
                $table->string('suppressed_reason', 128)->nullable();
                if ($isSqlite) {
                    $table->text('payload_json')->nullable();
                } else {
                    $table->json('payload_json')->nullable();
                }
                $table->timestamps();
            });
        } else {
            Schema::table('agent_triggers', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('agent_triggers', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (!Schema::hasColumn('agent_triggers', 'user_id')) {
                    $table->unsignedBigInteger('user_id');
                }
                if (!Schema::hasColumn('agent_triggers', 'trigger_type')) {
                    $table->string('trigger_type', 64);
                }
                if (!Schema::hasColumn('agent_triggers', 'status')) {
                    $table->string('status', 32)->default('fired');
                }
                if (!Schema::hasColumn('agent_triggers', 'fired_at')) {
                    $table->timestamp('fired_at')->nullable();
                }
                if (!Schema::hasColumn('agent_triggers', 'idempotency_key')) {
                    $table->string('idempotency_key', 128)->nullable();
                }
                if (!Schema::hasColumn('agent_triggers', 'suppressed_reason')) {
                    $table->string('suppressed_reason', 128)->nullable();
                }
                if (!Schema::hasColumn('agent_triggers', 'payload_json')) {
                    if ($isSqlite) {
                        $table->text('payload_json')->nullable();
                    } else {
                        $table->json('payload_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('agent_triggers', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('agent_triggers', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $userTriggerIdx = 'agent_triggers_user_type_fired_idx';
        if (
            Schema::hasTable('agent_triggers')
            && Schema::hasColumn('agent_triggers', 'user_id')
            && Schema::hasColumn('agent_triggers', 'trigger_type')
            && Schema::hasColumn('agent_triggers', 'fired_at')
            && !$this->indexExists('agent_triggers', $userTriggerIdx)
        ) {
            Schema::table('agent_triggers', function (Blueprint $table) use ($userTriggerIdx) {
                $table->index(['user_id', 'trigger_type', 'fired_at'], $userTriggerIdx);
            });
        }

        $idempotencyIdx = 'agent_triggers_idempotency_uq';
        if (
            Schema::hasTable('agent_triggers')
            && Schema::hasColumn('agent_triggers', 'idempotency_key')
            && !$this->indexExists('agent_triggers', $idempotencyIdx)
        ) {
            Schema::table('agent_triggers', function (Blueprint $table) use ($idempotencyIdx) {
                $table->unique(['idempotency_key'], $idempotencyIdx);
            });
        }

        $statusIdx = 'agent_triggers_status_idx';
        if (
            Schema::hasTable('agent_triggers')
            && Schema::hasColumn('agent_triggers', 'status')
            && !$this->indexExists('agent_triggers', $statusIdx)
        ) {
            Schema::table('agent_triggers', function (Blueprint $table) use ($statusIdx) {
                $table->index(['status'], $statusIdx);
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
