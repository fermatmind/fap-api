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

        if (!Schema::hasTable('embeddings')) {
            Schema::create('embeddings', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->string('namespace', 64)->default('default');
                $table->string('owner_type', 64);
                $table->string('owner_id', 64);
                $table->string('model', 64);
                $table->unsignedInteger('dim')->default(0);
                $table->string('content_hash', 64)->nullable();
                if ($isSqlite) {
                    $table->text('vector_json');
                    $table->text('meta_json')->nullable();
                } else {
                    $table->json('vector_json');
                    $table->json('meta_json')->nullable();
                }
                $table->text('content')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('embeddings', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('embeddings', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (!Schema::hasColumn('embeddings', 'namespace')) {
                    $table->string('namespace', 64)->default('default');
                }
                if (!Schema::hasColumn('embeddings', 'owner_type')) {
                    $table->string('owner_type', 64);
                }
                if (!Schema::hasColumn('embeddings', 'owner_id')) {
                    $table->string('owner_id', 64);
                }
                if (!Schema::hasColumn('embeddings', 'model')) {
                    $table->string('model', 64);
                }
                if (!Schema::hasColumn('embeddings', 'dim')) {
                    $table->unsignedInteger('dim')->default(0);
                }
                if (!Schema::hasColumn('embeddings', 'content_hash')) {
                    $table->string('content_hash', 64)->nullable();
                }
                if (!Schema::hasColumn('embeddings', 'vector_json')) {
                    if ($isSqlite) {
                        $table->text('vector_json');
                    } else {
                        $table->json('vector_json');
                    }
                }
                if (!Schema::hasColumn('embeddings', 'meta_json')) {
                    if ($isSqlite) {
                        $table->text('meta_json')->nullable();
                    } else {
                        $table->json('meta_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('embeddings', 'content')) {
                    $table->text('content')->nullable();
                }
                if (!Schema::hasColumn('embeddings', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('embeddings', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $ownerIdx = 'embeddings_owner_idx';
        if (
            Schema::hasTable('embeddings')
            && Schema::hasColumn('embeddings', 'owner_type')
            && Schema::hasColumn('embeddings', 'owner_id')
            && !$this->indexExists('embeddings', $ownerIdx)
        ) {
            Schema::table('embeddings', function (Blueprint $table) use ($ownerIdx) {
                $table->index(['owner_type', 'owner_id'], $ownerIdx);
            });
        }

        $namespaceIdx = 'embeddings_namespace_idx';
        if (
            Schema::hasTable('embeddings')
            && Schema::hasColumn('embeddings', 'namespace')
            && !$this->indexExists('embeddings', $namespaceIdx)
        ) {
            Schema::table('embeddings', function (Blueprint $table) use ($namespaceIdx) {
                $table->index(['namespace'], $namespaceIdx);
            });
        }

        $contentHashIdx = 'embeddings_content_hash_idx';
        if (
            Schema::hasTable('embeddings')
            && Schema::hasColumn('embeddings', 'content_hash')
            && !$this->indexExists('embeddings', $contentHashIdx)
        ) {
            Schema::table('embeddings', function (Blueprint $table) use ($contentHashIdx) {
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
