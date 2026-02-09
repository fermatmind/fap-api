<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_events')) {
            Schema::create('payment_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('provider', 32);
                $table->string('provider_event_id', 128)->unique();
                $table->uuid('order_id');
                $table->string('event_type', 64);
                $table->json('payload_json');
                $table->boolean('signature_ok')->default(false);
                $table->timestamp('handled_at')->nullable();
                $table->string('handle_status', 32)->nullable();
                $table->string('request_id', 128)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('headers_digest', 64)->nullable();
                $table->timestamps();

                $table->index('order_id');
            });
            return;
        }

        Schema::table('payment_events', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_events', 'id')) {
                $table->uuid('id')->primary();
            }
            if (!Schema::hasColumn('payment_events', 'provider')) {
                $table->string('provider', 32)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'provider_event_id')) {
                $table->string('provider_event_id', 128)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'order_id')) {
                $table->uuid('order_id')->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'event_type')) {
                $table->string('event_type', 64)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'payload_json')) {
                $table->json('payload_json')->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'signature_ok')) {
                $table->boolean('signature_ok')->default(false);
            }
            if (!Schema::hasColumn('payment_events', 'handled_at')) {
                $table->timestamp('handled_at')->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'handle_status')) {
                $table->string('handle_status', 32)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'request_id')) {
                $table->string('request_id', 128)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'ip')) {
                $table->string('ip', 45)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'headers_digest')) {
                $table->string('headers_digest', 64)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'updated_at')) {
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
