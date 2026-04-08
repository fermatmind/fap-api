<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recommendation_snapshots')) {
            return;
        }

        Schema::create('recommendation_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_projection_id');
            $table->foreignUuid('context_snapshot_id');
            $table->foreignUuid('occupation_id');
            $table->json('snapshot_payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['profile_projection_id', 'created_at'], 'recommendation_snapshots_projection_idx');
            $table->index(['context_snapshot_id', 'occupation_id'], 'recommendation_snapshots_context_occ_idx');

            $table->foreign('profile_projection_id')
                ->references('id')
                ->on('profile_projections')
                ->restrictOnDelete();
            $table->foreign('context_snapshot_id')
                ->references('id')
                ->on('context_snapshots')
                ->restrictOnDelete();
            $table->foreign('occupation_id')
                ->references('id')
                ->on('occupations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
