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

        if (!Schema::hasTable('agent_decisions')) {
            Schema::create('agent_decisions', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->uuid('trigger_id')->nullable();
                $table->string('decision', 32)->default('send');
                $table->string('reason', 128)->nullable();
                $table->string('idempotency_key', 128)->nullable();
                if ($isSqlite) {
                    $table->text('policy_json')->nullable();
                } else {
                    $table->json('policy_json')->nullable();
                }
                $table->timestamps();
            });
        } else {
            Schema::table('agent_decisions', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('agent_decisions', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (!Schema::hasColumn('agent_decisions', 'user_id')) {
                    $table->unsignedBigInteger('user_id');
                }
                if (!Schema::hasColumn('agent_decisions', 'trigger_id')) {
                    $table->uuid('trigger_id')->nullable();
                }
                if (!Schema::hasColumn('agent_decisions', 'decision')) {
                    $table->string('decision', 32)->default('send');
                }
                if (!Schema::hasColumn('agent_decisions', 'reason')) {
                    $table->string('reason', 128)->nullable();
                }
                if (!Schema::hasColumn('agent_decisions', 'idempotency_key')) {
                    $table->string('idempotency_key', 128)->nullable();
                }
                if (!Schema::hasColumn('agent_decisions', 'policy_json')) {
                    if ($isSqlite) {
                        $table->text('policy_json')->nullable();
                    } else {
                        $table->json('policy_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('agent_decisions', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('agent_decisions', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $userIdx = 'agent_decisions_user_idx';
        if (
            Schema::hasTable('agent_decisions')
            && Schema::hasColumn('agent_decisions', 'user_id')
            && !$this->indexExists('agent_decisions', $userIdx)
        ) {
            Schema::table('agent_decisions', function (Blueprint $table) use ($userIdx) {
                $table->index(['user_id'], $userIdx);
            });
        }

        $triggerIdx = 'agent_decisions_trigger_idx';
        if (
            Schema::hasTable('agent_decisions')
            && Schema::hasColumn('agent_decisions', 'trigger_id')
            && !$this->indexExists('agent_decisions', $triggerIdx)
        ) {
            Schema::table('agent_decisions', function (Blueprint $table) use ($triggerIdx) {
                $table->index(['trigger_id'], $triggerIdx);
            });
        }

        $idempotencyIdx = 'agent_decisions_idempotency_uq';
        if (
            Schema::hasTable('agent_decisions')
            && Schema::hasColumn('agent_decisions', 'idempotency_key')
            && !$this->indexExists('agent_decisions', $idempotencyIdx)
        ) {
            Schema::table('agent_decisions', function (Blueprint $table) use ($idempotencyIdx) {
                $table->unique(['idempotency_key'], $idempotencyIdx);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_decisions')) {
            return;
        }

        foreach (['agent_decisions_user_idx', 'agent_decisions_trigger_idx', 'agent_decisions_idempotency_uq'] as $indexName) {
            if ($this->indexExists('agent_decisions', $indexName)) {
                Schema::table('agent_decisions', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }

        Schema::dropIfExists('agent_decisions');
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
