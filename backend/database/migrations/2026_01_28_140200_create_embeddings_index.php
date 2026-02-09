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

        if (!Schema::hasTable('embeddings_index')) {
            Schema::create('embeddings_index', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->string('namespace', 64)->default('default');
                $table->string('owner_type', 64);
                $table->string('owner_id', 64);
                $table->string('model', 64);
                $table->unsignedInteger('dim')->default(0);
                $table->string('content_hash', 64)->nullable();
                $table->string('vectorstore', 32)->default('mysql_fallback');
                if ($isSqlite) {
                    $table->text('meta_json')->nullable();
                } else {
                    $table->json('meta_json')->nullable();
                }
                $table->timestamp('last_upserted_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('embeddings_index', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('embeddings_index', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (!Schema::hasColumn('embeddings_index', 'namespace')) {
                    $table->string('namespace', 64)->default('default');
                }
                if (!Schema::hasColumn('embeddings_index', 'owner_type')) {
                    $table->string('owner_type', 64);
                }
                if (!Schema::hasColumn('embeddings_index', 'owner_id')) {
                    $table->string('owner_id', 64);
                }
                if (!Schema::hasColumn('embeddings_index', 'model')) {
                    $table->string('model', 64);
                }
                if (!Schema::hasColumn('embeddings_index', 'dim')) {
                    $table->unsignedInteger('dim')->default(0);
                }
                if (!Schema::hasColumn('embeddings_index', 'content_hash')) {
                    $table->string('content_hash', 64)->nullable();
                }
                if (!Schema::hasColumn('embeddings_index', 'vectorstore')) {
                    $table->string('vectorstore', 32)->default('mysql_fallback');
                }
                if (!Schema::hasColumn('embeddings_index', 'meta_json')) {
                    if ($isSqlite) {
                        $table->text('meta_json')->nullable();
                    } else {
                        $table->json('meta_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('embeddings_index', 'last_upserted_at')) {
                    $table->timestamp('last_upserted_at')->nullable();
                }
                if (!Schema::hasColumn('embeddings_index', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('embeddings_index', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $ownerIdx = 'embeddings_index_owner_idx';
        if (
            Schema::hasTable('embeddings_index')
            && Schema::hasColumn('embeddings_index', 'owner_type')
            && Schema::hasColumn('embeddings_index', 'owner_id')
            && !$this->indexExists('embeddings_index', $ownerIdx)
        ) {
            Schema::table('embeddings_index', function (Blueprint $table) use ($ownerIdx) {
                $table->index(['owner_type', 'owner_id'], $ownerIdx);
            });
        }

        $namespaceIdx = 'embeddings_index_namespace_idx';
        if (
            Schema::hasTable('embeddings_index')
            && Schema::hasColumn('embeddings_index', 'namespace')
            && !$this->indexExists('embeddings_index', $namespaceIdx)
        ) {
            Schema::table('embeddings_index', function (Blueprint $table) use ($namespaceIdx) {
                $table->index(['namespace'], $namespaceIdx);
            });
        }

        $contentHashIdx = 'embeddings_index_content_hash_idx';
        if (
            Schema::hasTable('embeddings_index')
            && Schema::hasColumn('embeddings_index', 'content_hash')
            && !$this->indexExists('embeddings_index', $contentHashIdx)
        ) {
            Schema::table('embeddings_index', function (Blueprint $table) use ($contentHashIdx) {
                $table->index(['content_hash'], $contentHashIdx);
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
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
