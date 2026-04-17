<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_projections')) {
            return;
        }

        Schema::create('profile_projections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('identity_id', 64)->nullable();
            $table->string('visitor_id', 191)->nullable();
            $table->foreignUuid('context_snapshot_id');
            $table->string('projection_version', 64);
            $table->decimal('psychometric_axis_coverage', 6, 2)->nullable();
            $table->json('projection_payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['context_snapshot_id', 'created_at'], 'profile_projections_context_idx');
            $table->index(['identity_id', 'created_at'], 'profile_projections_identity_idx');
            $table->index(['visitor_id', 'created_at'], 'profile_projections_visitor_idx');

            $table->foreign('context_snapshot_id')
                ->references('id')
                ->on('context_snapshots')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
