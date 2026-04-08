<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('projection_lineages')) {
            return;
        }

        Schema::create('projection_lineages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_projection_id')->nullable();
            $table->foreignUuid('child_projection_id');
            $table->foreignUuid('trigger_context_snapshot_id')->nullable();
            $table->string('trigger_assessment_id', 64)->nullable();
            $table->string('lineage_reason', 64);
            $table->json('diff_summary')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('child_projection_id');
            $table->index('parent_projection_id', 'projection_lineages_parent_idx');
            $table->index('trigger_context_snapshot_id', 'projection_lineages_trigger_context_idx');

            $table->foreign('parent_projection_id')
                ->references('id')
                ->on('profile_projections')
                ->restrictOnDelete();
            $table->foreign('child_projection_id')
                ->references('id')
                ->on('profile_projections')
                ->restrictOnDelete();
            $table->foreign('trigger_context_snapshot_id')
                ->references('id')
                ->on('context_snapshots')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
