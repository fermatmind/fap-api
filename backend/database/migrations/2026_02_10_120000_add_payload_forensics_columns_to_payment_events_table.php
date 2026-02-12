<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_events')) {
            return;
        }

        Schema::table('payment_events', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_events', 'payload_size_bytes')) {
                $table->unsignedInteger('payload_size_bytes')->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'payload_sha256')) {
                $table->char('payload_sha256', 64)->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'payload_s3_key')) {
                $table->string('payload_s3_key')->nullable();
            }
            if (!Schema::hasColumn('payment_events', 'payload_excerpt')) {
                $table->text('payload_excerpt')->nullable();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
