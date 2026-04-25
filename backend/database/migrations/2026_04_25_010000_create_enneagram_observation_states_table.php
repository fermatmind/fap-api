<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enneagram_observation_states')) {
            return;
        }

        Schema::create('enneagram_observation_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('user_id', 64)->nullable();
            $table->string('anon_id', 128)->nullable();
            $table->uuid('attempt_id');
            $table->string('scale_code', 32)->default('ENNEAGRAM');
            $table->string('form_code', 64)->nullable();
            $table->string('interpretation_context_id', 128)->nullable();
            $table->string('status', 64)->default('initial_result');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('day3_submitted_at')->nullable();
            $table->timestamp('day7_submitted_at')->nullable();
            $table->timestamp('resonance_feedback_submitted_at')->nullable();
            $table->timestamp('user_confirmed_at')->nullable();
            $table->string('user_confirmed_type', 8)->nullable();
            $table->string('user_disagreed_reason', 255)->nullable();
            $table->unsignedTinyInteger('resonance_score')->nullable();
            $table->unsignedTinyInteger('observation_completion_rate')->default(0);
            $table->string('suggested_next_action', 64)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'attempt_id'], 'enneagram_observation_states_org_attempt_unique');
            $table->index(['org_id', 'user_id'], 'enneagram_observation_states_org_user_idx');
            $table->index(['org_id', 'anon_id'], 'enneagram_observation_states_org_anon_idx');
            $table->index(['org_id', 'status'], 'enneagram_observation_states_org_status_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
