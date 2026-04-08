<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('context_snapshots')) {
            return;
        }

        Schema::create('context_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('identity_id', 64)->nullable();
            $table->string('visitor_id', 64)->nullable();
            $table->timestamp('captured_at');
            $table->foreignUuid('current_occupation_id')->nullable();
            $table->string('employment_status', 64)->nullable();
            $table->string('monthly_comp_band', 64)->nullable();
            $table->decimal('burnout_level', 6, 2)->nullable();
            $table->decimal('switch_urgency', 6, 2)->nullable();
            $table->decimal('risk_tolerance', 6, 2)->nullable();
            $table->string('geo_region', 64)->nullable();
            $table->decimal('family_constraint_level', 6, 2)->nullable();
            $table->decimal('manager_track_preference', 6, 2)->nullable();
            $table->unsignedInteger('time_horizon_months')->nullable();
            $table->json('context_payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['identity_id', 'captured_at'], 'context_snapshots_identity_idx');
            $table->index(['visitor_id', 'captured_at'], 'context_snapshots_visitor_idx');
            $table->index('current_occupation_id', 'context_snapshots_current_occ_idx');

            $table->foreign('current_occupation_id')
                ->references('id')
                ->on('occupations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
