<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('user_id', 64)->nullable()->index();
                $table->string('anon_id', 64)->nullable()->index();
                $table->string('device_id', 128)->nullable();
                $table->string('provider', 32)->default('internal');
                $table->string('provider_order_id', 128)->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->string('currency', 8);
                $table->integer('amount_total');
                $table->integer('amount_refunded')->default(0);
                $table->string('item_sku', 64);
                $table->string('request_id', 128)->nullable();
                $table->string('created_ip', 45)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('fulfilled_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamps();
            });
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'id')) {
                $table->uuid('id')->primary();
            }
            if (!Schema::hasColumn('orders', 'user_id')) {
                $table->string('user_id', 64)->nullable()->index();
            }
            if (!Schema::hasColumn('orders', 'anon_id')) {
                $table->string('anon_id', 64)->nullable()->index();
            }
            if (!Schema::hasColumn('orders', 'device_id')) {
                $table->string('device_id', 128)->nullable();
            }
            if (!Schema::hasColumn('orders', 'provider')) {
                $table->string('provider', 32)->default('internal');
            }
            if (!Schema::hasColumn('orders', 'provider_order_id')) {
                $table->string('provider_order_id', 128)->nullable();
            }
            if (!Schema::hasColumn('orders', 'status')) {
                $table->string('status', 32)->default('pending')->index();
            }
            if (!Schema::hasColumn('orders', 'currency')) {
                $table->string('currency', 8)->nullable();
            }
            if (!Schema::hasColumn('orders', 'amount_total')) {
                $table->integer('amount_total')->default(0);
            }
            if (!Schema::hasColumn('orders', 'amount_refunded')) {
                $table->integer('amount_refunded')->default(0);
            }
            if (!Schema::hasColumn('orders', 'item_sku')) {
                $table->string('item_sku', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'request_id')) {
                $table->string('request_id', 128)->nullable();
            }
            if (!Schema::hasColumn('orders', 'created_ip')) {
                $table->string('created_ip', 45)->nullable();
            }
            if (!Schema::hasColumn('orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'fulfilled_at')) {
                $table->timestamp('fulfilled_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
    }
};
