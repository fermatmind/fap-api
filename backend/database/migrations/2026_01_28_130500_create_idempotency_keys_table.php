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

        if (!Schema::hasTable('idempotency_keys')) {
            Schema::create('idempotency_keys', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('provider', 64);
                $table->string('external_id', 128);
                $table->timestamp('recorded_at');
                $table->string('hash', 64);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->uuid('ingest_batch_id')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('idempotency_keys', function (Blueprint $table) {
                if (!Schema::hasColumn('idempotency_keys', 'id')) {
                    $table->bigIncrements('id');
                }
                if (!Schema::hasColumn('idempotency_keys', 'provider')) {
                    $table->string('provider', 64);
                }
                if (!Schema::hasColumn('idempotency_keys', 'external_id')) {
                    $table->string('external_id', 128);
                }
                if (!Schema::hasColumn('idempotency_keys', 'recorded_at')) {
                    $table->timestamp('recorded_at');
                }
                if (!Schema::hasColumn('idempotency_keys', 'hash')) {
                    $table->string('hash', 64);
                }
                if (!Schema::hasColumn('idempotency_keys', 'first_seen_at')) {
                    $table->timestamp('first_seen_at')->nullable();
                }
                if (!Schema::hasColumn('idempotency_keys', 'last_seen_at')) {
                    $table->timestamp('last_seen_at')->nullable();
                }
                if (!Schema::hasColumn('idempotency_keys', 'ingest_batch_id')) {
                    $table->uuid('ingest_batch_id')->nullable();
                }
                if (!Schema::hasColumn('idempotency_keys', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('idempotency_keys', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $uniqueName = 'idempotency_keys_unique';
        if (
            Schema::hasTable('idempotency_keys')
            && Schema::hasColumn('idempotency_keys', 'provider')
            && Schema::hasColumn('idempotency_keys', 'external_id')
            && Schema::hasColumn('idempotency_keys', 'recorded_at')
            && Schema::hasColumn('idempotency_keys', 'hash')
            && !$this->indexExists('idempotency_keys', $uniqueName)
        ) {
            Schema::table('idempotency_keys', function (Blueprint $table) use ($uniqueName) {
                $table->unique(['provider', 'external_id', 'recorded_at', 'hash'], $uniqueName);
            });
        }

        $lookupName = 'idempotency_keys_lookup_idx';
        if (
            Schema::hasTable('idempotency_keys')
            && Schema::hasColumn('idempotency_keys', 'provider')
            && Schema::hasColumn('idempotency_keys', 'external_id')
            && !$this->indexExists('idempotency_keys', $lookupName)
        ) {
            Schema::table('idempotency_keys', function (Blueprint $table) use ($lookupName) {
                $table->index(['provider', 'external_id'], $lookupName);
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
