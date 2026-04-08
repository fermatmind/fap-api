<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('index_states')) {
            return;
        }

        Schema::create('index_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id');
            $table->string('index_state', 64);
            $table->boolean('index_eligible')->default(false);
            $table->string('canonical_path', 255);
            $table->string('canonical_target', 255)->nullable();
            $table->json('reason_codes')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['occupation_id', 'changed_at'], 'index_states_occ_changed_idx');
            $table->index(['index_state', 'index_eligible'], 'index_states_state_eligible_idx');

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
