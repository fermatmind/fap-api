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

        if (!Schema::hasTable('health_samples')) {
            Schema::create('health_samples', function (Blueprint $table) use ($isSqlite) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('source', 64)->default('ingestion');
                $table->string('domain', 64)->default('unknown');
                $table->timestamp('recorded_at');
                if ($isSqlite) {
                    $table->text('value_json');
                } else {
                    $table->json('value_json');
                }
                $table->decimal('confidence', 4, 2)->default(1.0);
                $table->string('raw_payload_hash', 64)->nullable();
                $table->uuid('ingest_batch_id')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('health_samples', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('health_samples', 'id')) {
                    $table->bigIncrements('id');
                }
                if (!Schema::hasColumn('health_samples', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
                if (!Schema::hasColumn('health_samples', 'source')) {
                    $table->string('source', 64)->default('ingestion');
                }
                if (!Schema::hasColumn('health_samples', 'domain')) {
                    $table->string('domain', 64)->default('unknown');
                }
                if (!Schema::hasColumn('health_samples', 'recorded_at')) {
                    $table->timestamp('recorded_at');
                }
                if (!Schema::hasColumn('health_samples', 'value_json')) {
                    if ($isSqlite) {
                        $table->text('value_json');
                    } else {
                        $table->json('value_json');
                    }
                }
                if (!Schema::hasColumn('health_samples', 'confidence')) {
                    $table->decimal('confidence', 4, 2)->default(1.0);
                }
                if (!Schema::hasColumn('health_samples', 'raw_payload_hash')) {
                    $table->string('raw_payload_hash', 64)->nullable();
                }
                if (!Schema::hasColumn('health_samples', 'ingest_batch_id')) {
                    $table->uuid('ingest_batch_id')->nullable();
                }
                if (!Schema::hasColumn('health_samples', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('health_samples', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $indexName = 'health_samples_user_recorded_idx';
        if (
            Schema::hasTable('health_samples')
            && Schema::hasColumn('health_samples', 'user_id')
            && Schema::hasColumn('health_samples', 'recorded_at')
            && !$this->indexExists('health_samples', $indexName)
        ) {
            Schema::table('health_samples', function (Blueprint $table) use ($indexName) {
                $table->index(['user_id', 'recorded_at'], $indexName);
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
