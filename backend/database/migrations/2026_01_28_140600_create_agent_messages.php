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

        if (!Schema::hasTable('agent_messages')) {
            Schema::create('agent_messages', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->uuid('decision_id')->nullable();
                $table->string('channel', 32)->default('in_app');
                $table->string('status', 32)->default('queued');
                $table->string('title', 128)->nullable();
                $table->text('body');
                $table->string('template_key', 64)->nullable();
                $table->string('content_hash', 64)->nullable();
                $table->string('idempotency_key', 128)->nullable();
                if ($isSqlite) {
                    $table->text('why_json');
                    $table->text('evidence_json');
                } else {
                    $table->json('why_json');
                    $table->json('evidence_json');
                }
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('acked_at')->nullable();
                $table->timestamp('feedback_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('agent_messages', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('agent_messages', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (!Schema::hasColumn('agent_messages', 'user_id')) {
                    $table->unsignedBigInteger('user_id');
                }
                if (!Schema::hasColumn('agent_messages', 'decision_id')) {
                    $table->uuid('decision_id')->nullable();
                }
                if (!Schema::hasColumn('agent_messages', 'channel')) {
                    $table->string('channel', 32)->default('in_app');
                }
                if (!Schema::hasColumn('agent_messages', 'status')) {
                    $table->string('status', 32)->default('queued');
                }
                if (!Schema::hasColumn('agent_messages', 'title')) {
                    $table->string('title', 128)->nullable();
                }
                if (!Schema::hasColumn('agent_messages', 'body')) {
                    $table->text('body');
                }
                if (!Schema::hasColumn('agent_messages', 'template_key')) {
                    $table->string('template_key', 64)->nullable();
                }
                if (!Schema::hasColumn('agent_messages', 'content_hash')) {
                    $table->string('content_hash', 64)->nullable();
                }
                if (!Schema::hasColumn('agent_messages', 'idempotency_key')) {
                    $table->string('idempotency_key', 128)->nullable();
                }
                if (!Schema::hasColumn('agent_messages', 'why_json')) {
                    if ($isSqlite) {
                        $table->text('why_json');
                    } else {
                        $table->json('why_json');
                    }
                }
                if (!Schema::hasColumn('agent_messages', 'evidence_json')) {
                    if ($isSqlite) {
                        $table->text('evidence_json');
                    } else {
                        $table->json('evidence_json');
                    }
                }
                if (!Schema::hasColumn('agent_messages', 'sent_at')) {
                    $table->timestamp('sent_at')->nullable();
                }
                if (!Schema::hasColumn('agent_messages', 'acked_at')) {
                    $table->timestamp('acked_at')->nullable();
                }
                if (!Schema::hasColumn('agent_messages', 'feedback_at')) {
                    $table->timestamp('feedback_at')->nullable();
                }
                if (!Schema::hasColumn('agent_messages', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('agent_messages', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $userSentIdx = 'agent_messages_user_sent_idx';
        if (
            Schema::hasTable('agent_messages')
            && Schema::hasColumn('agent_messages', 'user_id')
            && Schema::hasColumn('agent_messages', 'sent_at')
            && !$this->indexExists('agent_messages', $userSentIdx)
        ) {
            Schema::table('agent_messages', function (Blueprint $table) use ($userSentIdx) {
                $table->index(['user_id', 'sent_at'], $userSentIdx);
            });
        }

        $statusIdx = 'agent_messages_status_idx';
        if (
            Schema::hasTable('agent_messages')
            && Schema::hasColumn('agent_messages', 'status')
            && !$this->indexExists('agent_messages', $statusIdx)
        ) {
            Schema::table('agent_messages', function (Blueprint $table) use ($statusIdx) {
                $table->index(['status'], $statusIdx);
            });
        }

        $contentHashIdx = 'agent_messages_content_hash_idx';
        if (
            Schema::hasTable('agent_messages')
            && Schema::hasColumn('agent_messages', 'content_hash')
            && !$this->indexExists('agent_messages', $contentHashIdx)
        ) {
            Schema::table('agent_messages', function (Blueprint $table) use ($contentHashIdx) {
                $table->index(['content_hash'], $contentHashIdx);
            });
        }

        $idempotencyIdx = 'agent_messages_idempotency_uq';
        if (
            Schema::hasTable('agent_messages')
            && Schema::hasColumn('agent_messages', 'idempotency_key')
            && !$this->indexExists('agent_messages', $idempotencyIdx)
        ) {
            Schema::table('agent_messages', function (Blueprint $table) use ($idempotencyIdx) {
                $table->unique(['idempotency_key'], $idempotencyIdx);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_messages')) {
            return;
        }

        foreach (['agent_messages_user_sent_idx', 'agent_messages_status_idx', 'agent_messages_content_hash_idx', 'agent_messages_idempotency_uq'] as $indexName) {
            if ($this->indexExists('agent_messages', $indexName)) {
                Schema::table('agent_messages', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }

        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('agent_messages');
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
