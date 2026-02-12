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

        if (!Schema::hasTable('memories')) {
            Schema::create('memories', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('status', 32)->default('proposed');
                $table->string('kind', 64)->default('note');
                $table->string('title', 128)->nullable();
                $table->text('content');
                $table->string('content_hash', 64)->nullable();
                if ($isSqlite) {
                    $table->text('tags_json')->nullable();
                    $table->text('evidence_json')->nullable();
                    $table->text('source_refs_json')->nullable();
                } else {
                    $table->json('tags_json')->nullable();
                    $table->json('evidence_json')->nullable();
                    $table->json('source_refs_json')->nullable();
                }
                $table->string('consent_version', 64)->nullable();
                $table->timestamp('proposed_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('memories', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('memories', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (!Schema::hasColumn('memories', 'user_id')) {
                    $table->unsignedBigInteger('user_id');
                }
                if (!Schema::hasColumn('memories', 'status')) {
                    $table->string('status', 32)->default('proposed');
                }
                if (!Schema::hasColumn('memories', 'kind')) {
                    $table->string('kind', 64)->default('note');
                }
                if (!Schema::hasColumn('memories', 'title')) {
                    $table->string('title', 128)->nullable();
                }
                if (!Schema::hasColumn('memories', 'content')) {
                    $table->text('content');
                }
                if (!Schema::hasColumn('memories', 'content_hash')) {
                    $table->string('content_hash', 64)->nullable();
                }
                if (!Schema::hasColumn('memories', 'tags_json')) {
                    if ($isSqlite) {
                        $table->text('tags_json')->nullable();
                    } else {
                        $table->json('tags_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('memories', 'evidence_json')) {
                    if ($isSqlite) {
                        $table->text('evidence_json')->nullable();
                    } else {
                        $table->json('evidence_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('memories', 'source_refs_json')) {
                    if ($isSqlite) {
                        $table->text('source_refs_json')->nullable();
                    } else {
                        $table->json('source_refs_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('memories', 'consent_version')) {
                    $table->string('consent_version', 64)->nullable();
                }
                if (!Schema::hasColumn('memories', 'proposed_at')) {
                    $table->timestamp('proposed_at')->nullable();
                }
                if (!Schema::hasColumn('memories', 'confirmed_at')) {
                    $table->timestamp('confirmed_at')->nullable();
                }
                if (!Schema::hasColumn('memories', 'deleted_at')) {
                    $table->timestamp('deleted_at')->nullable();
                }
                if (!Schema::hasColumn('memories', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('memories', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $userStatusIdx = 'memories_user_status_idx';
        if (
            Schema::hasTable('memories')
            && Schema::hasColumn('memories', 'user_id')
            && Schema::hasColumn('memories', 'status')
            && !$this->indexExists('memories', $userStatusIdx)
        ) {
            Schema::table('memories', function (Blueprint $table) use ($userStatusIdx) {
                $table->index(['user_id', 'status'], $userStatusIdx);
            });
        }

        $userKindIdx = 'memories_user_kind_created_idx';
        if (
            Schema::hasTable('memories')
            && Schema::hasColumn('memories', 'user_id')
            && Schema::hasColumn('memories', 'kind')
            && Schema::hasColumn('memories', 'created_at')
            && !$this->indexExists('memories', $userKindIdx)
        ) {
            Schema::table('memories', function (Blueprint $table) use ($userKindIdx) {
                $table->index(['user_id', 'kind', 'created_at'], $userKindIdx);
            });
        }

        $contentHashIdx = 'memories_content_hash_idx';
        if (
            Schema::hasTable('memories')
            && Schema::hasColumn('memories', 'content_hash')
            && !$this->indexExists('memories', $contentHashIdx)
        ) {
            Schema::table('memories', function (Blueprint $table) use ($contentHashIdx) {
                $table->index(['content_hash'], $contentHashIdx);
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
