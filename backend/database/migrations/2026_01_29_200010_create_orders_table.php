<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('order_no', 64);
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('user_id', 64)->nullable();
                $table->string('anon_id', 64)->nullable();
                $table->string('sku', 64);
                $table->integer('quantity')->default(1);
                $table->string('target_attempt_id', 64)->nullable();
                $table->integer('amount_cents')->default(0);
                $table->string('currency', 8)->default('USD');
                $table->string('status', 32)->default('created');
                $table->string('provider', 32)->default('stub');
                $table->string('external_trade_no', 128)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->unique('order_no', 'orders_order_no_unique');
                $table->index(['org_id', 'created_at'], 'orders_org_created_idx');
                $table->index(['user_id', 'created_at'], 'orders_user_created_idx');
                $table->index(['status', 'created_at'], 'orders_status_created_idx');
                $table->index('external_trade_no', 'orders_external_trade_no_idx');
            });
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'order_no')) {
                $table->string('order_no', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('orders', 'sku')) {
                $table->string('sku', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'quantity')) {
                $table->integer('quantity')->default(1);
            }
            if (!Schema::hasColumn('orders', 'target_attempt_id')) {
                $table->string('target_attempt_id', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'amount_cents')) {
                $table->integer('amount_cents')->default(0);
            }
            if (!Schema::hasColumn('orders', 'external_trade_no')) {
                $table->string('external_trade_no', 128)->nullable();
            }
        });

        if (!$this->indexExists('orders', 'orders_order_no_unique') && Schema::hasColumn('orders', 'order_no')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique('order_no', 'orders_order_no_unique');
            });
        }

        if (!$this->indexExists('orders', 'orders_org_created_idx')
            && Schema::hasColumn('orders', 'org_id')
            && Schema::hasColumn('orders', 'created_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['org_id', 'created_at'], 'orders_org_created_idx');
            });
        }

        if (!$this->indexExists('orders', 'orders_user_created_idx')
            && Schema::hasColumn('orders', 'user_id')
            && Schema::hasColumn('orders', 'created_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['user_id', 'created_at'], 'orders_user_created_idx');
            });
        }

        if (!$this->indexExists('orders', 'orders_status_created_idx')
            && Schema::hasColumn('orders', 'status')
            && Schema::hasColumn('orders', 'created_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['status', 'created_at'], 'orders_status_created_idx');
            });
        }

        if (!$this->indexExists('orders', 'orders_external_trade_no_idx') && Schema::hasColumn('orders', 'external_trade_no')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index('external_trade_no', 'orders_external_trade_no_idx');
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
