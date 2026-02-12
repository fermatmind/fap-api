<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'requested_sku')) {
                    $table->string('requested_sku', 64)->nullable();
                }
                if (!Schema::hasColumn('orders', 'effective_sku')) {
                    $table->string('effective_sku', 64)->nullable();
                }
                if (!Schema::hasColumn('orders', 'entitlement_id')) {
                    $table->string('entitlement_id', 64)->nullable();
                }
            });
        }

        if (Schema::hasTable('payment_events')) {
            Schema::table('payment_events', function (Blueprint $table) {
                if (!Schema::hasColumn('payment_events', 'requested_sku')) {
                    $table->string('requested_sku', 64)->nullable();
                }
                if (!Schema::hasColumn('payment_events', 'effective_sku')) {
                    $table->string('effective_sku', 64)->nullable();
                }
                if (!Schema::hasColumn('payment_events', 'entitlement_id')) {
                    $table->string('entitlement_id', 64)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
