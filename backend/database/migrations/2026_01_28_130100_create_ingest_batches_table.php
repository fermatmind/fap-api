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

        if (!Schema::hasTable('ingest_batches')) {
            Schema::create('ingest_batches', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('id')->primary();
                $table->string('provider', 64);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('range_start')->nullable();
                $table->timestamp('range_end')->nullable();
                $table->string('raw_payload_hash', 64)->nullable();
                $table->string('status', 32)->default('received');
                $table->timestamp('created_at')->nullable();
            });
        } else {
            Schema::table('ingest_batches', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('ingest_batches', 'id')) {
                    $table->uuid('id')->primary();
                }
                if (!Schema::hasColumn('ingest_batches', 'provider')) {
                    $table->string('provider', 64);
                }
                if (!Schema::hasColumn('ingest_batches', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
                if (!Schema::hasColumn('ingest_batches', 'range_start')) {
                    $table->timestamp('range_start')->nullable();
                }
                if (!Schema::hasColumn('ingest_batches', 'range_end')) {
                    $table->timestamp('range_end')->nullable();
                }
                if (!Schema::hasColumn('ingest_batches', 'raw_payload_hash')) {
                    $table->string('raw_payload_hash', 64)->nullable();
                }
                if (!Schema::hasColumn('ingest_batches', 'status')) {
                    $table->string('status', 32)->default('received');
                }
                if (!Schema::hasColumn('ingest_batches', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
            });
        }

        $indexName = 'ingest_batches_provider_user_idx';
        if (
            Schema::hasTable('ingest_batches')
            && Schema::hasColumn('ingest_batches', 'provider')
            && Schema::hasColumn('ingest_batches', 'user_id')
            && !$this->indexExists('ingest_batches', $indexName)
        ) {
            Schema::table('ingest_batches', function (Blueprint $table) use ($indexName) {
                $table->index(['provider', 'user_id'], $indexName);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('ingest_batches')) {
            return;
        }

        $indexName = 'ingest_batches_provider_user_idx';
        if ($this->indexExists('ingest_batches', $indexName)) {
            Schema::table('ingest_batches', function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }

        Schema::dropIfExists('ingest_batches');
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
