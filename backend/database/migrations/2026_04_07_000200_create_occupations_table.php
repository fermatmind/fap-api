<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('occupations')) {
            return;
        }

        Schema::create('occupations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id');
            $table->uuid('parent_id')->nullable();
            $table->string('canonical_slug', 160)->unique();
            $table->string('entity_level', 64);
            $table->string('truth_market', 32);
            $table->string('display_market', 32);
            $table->string('crosswalk_mode', 64);
            $table->string('canonical_title_en', 255);
            $table->string('canonical_title_zh', 255);
            $table->string('search_h1_zh', 255);
            $table->decimal('structural_stability', 5, 4)->nullable();
            $table->json('task_prototype_signature')->nullable();
            $table->decimal('market_semantics_gap', 5, 4)->nullable();
            $table->decimal('regulatory_divergence', 5, 4)->nullable();
            $table->decimal('toolchain_divergence', 5, 4)->nullable();
            $table->decimal('skill_gap_threshold', 5, 4)->nullable();
            $table->json('trust_inheritance_scope')->nullable();
            $table->timestamps();

            $table->index(['family_id', 'entity_level'], 'occupations_family_level_idx');
            $table->index(['truth_market', 'display_market'], 'occupations_market_idx');

            $table->foreign('family_id')
                ->references('id')
                ->on('occupation_families')
                ->restrictOnDelete();
            $table->foreign('parent_id')
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
