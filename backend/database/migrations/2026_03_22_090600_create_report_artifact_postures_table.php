<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_artifact_postures')) {
            return;
        }

        Schema::create('report_artifact_postures', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('attempt_id', 64);
            $table->string('slot_code', 64);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('active_job_id')->nullable();
            $table->string('render_state', 64)->default('pending');
            $table->string('delivery_state', 64)->default('unavailable');
            $table->string('access_state', 64)->default('locked');
            $table->string('integrity_state', 64)->default('unknown');
            $table->string('attention_state', 64)->default('normal');
            $table->string('blocked_reason_code', 128)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('projection_fresh_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'slot_code'], 'report_artifact_postures_attempt_slot_unique');
            $table->index('attempt_id', 'report_artifact_postures_attempt_id_idx');
            $table->index('current_version_id', 'report_artifact_postures_current_version_id_idx');
            $table->index('active_job_id', 'report_artifact_postures_active_job_id_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
