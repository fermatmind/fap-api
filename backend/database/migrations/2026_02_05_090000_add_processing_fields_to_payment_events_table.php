<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_events')) {
            return;
        }

        Schema::table('payment_events', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_events', 'status')) {
                $table->string('status', 24)->default('received');
            }
            if (!Schema::hasColumn('payment_events', 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'attempts')) {
                $table->integer('attempts')->default(0);
            }
            if (!Schema::hasColumn('payment_events', 'last_error_code')) {
                $table->string('last_error_code', 64)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'last_error_message')) {
                $table->string('last_error_message', 255)->nullable();
            }
        });

        if (!$this->indexExists('payment_events', 'payment_events_provider_order_idx')
            && Schema::hasColumn('payment_events', 'provider')
            && Schema::hasColumn('payment_events', 'order_no')) {
            Schema::table('payment_events', function (Blueprint $table) {
                $table->index(['provider', 'order_no'], 'payment_events_provider_order_idx');
            });
        }

        if (!$this->indexExists('payment_events', 'payment_events_status_idx')
            && Schema::hasColumn('payment_events', 'status')) {
            Schema::table('payment_events', function (Blueprint $table) {
                $table->index('status', 'payment_events_status_idx');
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
