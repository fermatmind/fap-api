<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eq_journey_states')) {
            return;
        }

        Schema::create('eq_journey_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('user_id', 64)->nullable();
            $table->string('anon_id', 128)->nullable();
            $table->uuid('attempt_id');
            $table->string('scale_code', 32)->default('EQ_60');
            $table->string('eq_report_mode', 32)->default('self_report');
            $table->string('core_formulation_id', 96)->nullable();
            $table->string('route_id', 96)->nullable();
            $table->string('quality_level', 8)->nullable();
            $table->string('confidence_label', 32)->nullable();
            $table->string('status', 64)->default('initial_result');
            $table->string('read_depth', 32)->nullable();
            $table->string('result_resonance', 32)->nullable();
            $table->string('action_completion', 32)->nullable();
            $table->string('retest_intent', 32)->nullable();
            $table->boolean('consent_to_store')->default(false);
            $table->timestamp('resonance_feedback_submitted_at')->nullable();
            $table->timestamp('action_completed_at')->nullable();
            $table->timestamp('retest_intent_recorded_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'attempt_id'], 'eq_journey_states_org_attempt_unique');
            $table->index(['org_id', 'user_id'], 'eq_journey_states_org_user_idx');
            $table->index(['org_id', 'anon_id'], 'eq_journey_states_org_anon_idx');
            $table->index(['org_id', 'status'], 'eq_journey_states_org_status_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent loss of user feedback state.
    }
};
