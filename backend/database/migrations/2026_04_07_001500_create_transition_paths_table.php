<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transition_paths')) {
            return;
        }

        Schema::create('transition_paths', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recommendation_snapshot_id');
            $table->foreignUuid('from_occupation_id');
            $table->foreignUuid('to_occupation_id');
            $table->string('path_type', 64);
            $table->json('path_payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recommendation_snapshot_id', 'path_type'], 'transition_paths_snapshot_type_idx');
            $table->index(['from_occupation_id', 'to_occupation_id'], 'transition_paths_occ_pair_idx');

            $table->foreign('recommendation_snapshot_id')
                ->references('id')
                ->on('recommendation_snapshots')
                ->restrictOnDelete();
            $table->foreign('from_occupation_id')
                ->references('id')
                ->on('occupations')
                ->restrictOnDelete();
            $table->foreign('to_occupation_id')
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
