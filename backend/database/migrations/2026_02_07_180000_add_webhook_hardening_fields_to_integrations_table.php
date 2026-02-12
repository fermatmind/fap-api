<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('integrations')) {
            return;
        }

        Schema::table('integrations', function (Blueprint $table) {
            if (!Schema::hasColumn('integrations', 'webhook_last_event_id')) {
                $table->string('webhook_last_event_id', 128)->nullable();
            }
            if (!Schema::hasColumn('integrations', 'webhook_last_timestamp')) {
                $table->unsignedBigInteger('webhook_last_timestamp')->nullable();
            }
            if (!Schema::hasColumn('integrations', 'webhook_last_received_at')) {
                $table->timestamp('webhook_last_received_at')->nullable();
            }
        });

        if (
            Schema::hasColumn('integrations', 'provider')
            && Schema::hasColumn('integrations', 'external_user_id')
            && !$this->indexExists('integrations', 'integrations_provider_external_user_idx')
        ) {
            Schema::table('integrations', function (Blueprint $table) {
                $table->index(['provider', 'external_user_id'], 'integrations_provider_external_user_idx');
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
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
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

        $db = DB::getDatabaseName();
        $rows = DB::select(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1",
            [$db, $table, $indexName]
        );
        return !empty($rows);
    }
};
