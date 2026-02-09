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
            Schema::create('payment_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('provider', 32);
                $table->string('provider_event_id', 128);
                $table->string('order_no', 64)->nullable();
                $table->json('payload_json');
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_event_id'], 'payment_events_provider_provider_event_id_unique');
                $table->index(['order_no', 'received_at'], 'payment_events_order_received_idx');
            });
            return;
        }

        Schema::table('payment_events', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_events', 'order_no')) {
                $table->string('order_no', 64)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'received_at')) {
                $table->timestamp('received_at')->nullable();
            }
        });

        if (!$this->indexExists('payment_events', 'payment_events_provider_provider_event_id_unique')
            && Schema::hasColumn('payment_events', 'provider')
            && Schema::hasColumn('payment_events', 'provider_event_id')) {
            $duplicates = DB::table('payment_events')
                ->select('provider', 'provider_event_id')
                ->whereNotNull('provider')
                ->whereNotNull('provider_event_id')
                ->groupBy('provider', 'provider_event_id')
                ->havingRaw('count(*) > 1')
                ->limit(1)
                ->get();

            if ($duplicates->count() === 0) {
                Schema::table('payment_events', function (Blueprint $table) {
                    $table->unique(['provider', 'provider_event_id'], 'payment_events_provider_provider_event_id_unique');
                });
            }
        }

        if (!$this->indexExists('payment_events', 'payment_events_order_received_idx')
            && Schema::hasColumn('payment_events', 'order_no')
            && Schema::hasColumn('payment_events', 'received_at')) {
            Schema::table('payment_events', function (Blueprint $table) {
                $table->index(['order_no', 'received_at'], 'payment_events_order_received_idx');
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
