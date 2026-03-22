<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('artifact_lifecycle_jobs')) {
            return;
        }

        Schema::create('artifact_lifecycle_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('attempt_id', 64)->nullable();
            $table->unsignedBigInteger('artifact_slot_id')->nullable();
            $table->string('job_type', 64);
            $table->string('state', 64)->default('queued');
            $table->string('reason_code', 128)->nullable();
            $table->string('blocked_reason_code', 128)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->json('request_payload_json')->nullable();
            $table->json('result_payload_json')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('attempt_id', 'artifact_lifecycle_jobs_attempt_id_idx');
            $table->index('artifact_slot_id', 'artifact_lifecycle_jobs_artifact_slot_id_idx');
            $table->index('job_type', 'artifact_lifecycle_jobs_job_type_idx');
            $table->index('state', 'artifact_lifecycle_jobs_state_idx');
            $table->index('idempotency_key', 'artifact_lifecycle_jobs_idempotency_key_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
