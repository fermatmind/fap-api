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

        if (!Schema::hasTable('agent_feedback')) {
            Schema::create('agent_feedback', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->uuid('message_id');
                $table->string('rating', 32);
                $table->string('reason', 128)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('agent_feedback', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('agent_feedback', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (!Schema::hasColumn('agent_feedback', 'user_id')) {
                    $table->unsignedBigInteger('user_id');
                }
                if (!Schema::hasColumn('agent_feedback', 'message_id')) {
                    $table->uuid('message_id');
                }
                if (!Schema::hasColumn('agent_feedback', 'rating')) {
                    $table->string('rating', 32);
                }
                if (!Schema::hasColumn('agent_feedback', 'reason')) {
                    $table->string('reason', 128)->nullable();
                }
                if (!Schema::hasColumn('agent_feedback', 'notes')) {
                    $table->text('notes')->nullable();
                }
                if (!Schema::hasColumn('agent_feedback', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('agent_feedback', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $messageIdx = 'agent_feedback_message_idx';
        if (
            Schema::hasTable('agent_feedback')
            && Schema::hasColumn('agent_feedback', 'message_id')
            && !$this->indexExists('agent_feedback', $messageIdx)
        ) {
            Schema::table('agent_feedback', function (Blueprint $table) use ($messageIdx) {
                $table->index(['message_id'], $messageIdx);
            });
        }

        $userIdx = 'agent_feedback_user_idx';
        if (
            Schema::hasTable('agent_feedback')
            && Schema::hasColumn('agent_feedback', 'user_id')
            && !$this->indexExists('agent_feedback', $userIdx)
        ) {
            Schema::table('agent_feedback', function (Blueprint $table) use ($userIdx) {
                $table->index(['user_id'], $userIdx);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_feedback')) {
            return;
        }

        foreach (['agent_feedback_message_idx', 'agent_feedback_user_idx'] as $indexName) {
            if ($this->indexExists('agent_feedback', $indexName)) {
                Schema::table('agent_feedback', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }

        Schema::dropIfExists('agent_feedback');
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
