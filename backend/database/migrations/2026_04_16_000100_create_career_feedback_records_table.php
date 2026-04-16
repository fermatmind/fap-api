<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('career_feedback_records')) {
            return;
        }

        Schema::create('career_feedback_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_kind', 64);
            $table->string('subject_slug', 128)->nullable();
            $table->unsignedTinyInteger('burnout_checkin')->nullable();
            $table->unsignedTinyInteger('career_satisfaction')->nullable();
            $table->unsignedTinyInteger('switch_urgency')->nullable();
            $table->foreignUuid('context_snapshot_id')->nullable();
            $table->foreignUuid('profile_projection_id')->nullable();
            $table->foreignUuid('recommendation_snapshot_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_kind', 'subject_slug', 'created_at'], 'career_feedback_subject_idx');
            $table->index(['recommendation_snapshot_id', 'created_at'], 'career_feedback_recommendation_idx');

            $table->foreign('context_snapshot_id')
                ->references('id')
                ->on('context_snapshots')
                ->nullOnDelete();
            $table->foreign('profile_projection_id')
                ->references('id')
                ->on('profile_projections')
                ->nullOnDelete();
            $table->foreign('recommendation_snapshot_id')
                ->references('id')
                ->on('recommendation_snapshots')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};

