<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attempt_receipts')) {
            return;
        }

        Schema::create('attempt_receipts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('attempt_id', 64);
            $table->unsignedInteger('seq');
            $table->string('receipt_type', 64);
            $table->string('source_system', 64);
            $table->string('source_ref', 255)->nullable();
            $table->string('actor_type', 64)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'seq'], 'attempt_receipts_attempt_seq_unique');
            $table->index('receipt_type', 'attempt_receipts_receipt_type_idx');
            $table->index('idempotency_key', 'attempt_receipts_idempotency_key_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
