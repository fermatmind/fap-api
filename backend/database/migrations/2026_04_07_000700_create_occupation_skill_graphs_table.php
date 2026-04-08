<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('occupation_skill_graphs')) {
            return;
        }

        Schema::create('occupation_skill_graphs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id');
            $table->string('stack_key', 128);
            $table->json('skill_overlap_graph');
            $table->json('task_overlap_graph')->nullable();
            $table->json('tool_overlap_graph')->nullable();
            $table->timestamps();

            $table->index(['occupation_id', 'stack_key'], 'occupation_skill_graphs_occ_stack_idx');

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
