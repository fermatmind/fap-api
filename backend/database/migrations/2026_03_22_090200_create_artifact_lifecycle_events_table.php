<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('artifact_lifecycle_events')) {
            return;
        }

        Schema::create('artifact_lifecycle_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('attempt_id', 64)->nullable();
            $table->unsignedBigInteger('artifact_slot_id')->nullable();
            $table->string('event_type', 64);
            $table->string('from_state', 64)->nullable();
            $table->string('to_state', 64)->nullable();
            $table->string('reason_code', 128)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index('job_id', 'artifact_lifecycle_events_job_id_idx');
            $table->index('attempt_id', 'artifact_lifecycle_events_attempt_id_idx');
            $table->index('artifact_slot_id', 'artifact_lifecycle_events_artifact_slot_id_idx');
            $table->index('event_type', 'artifact_lifecycle_events_event_type_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
