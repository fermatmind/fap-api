<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('occupation_aliases')) {
            return;
        }

        Schema::create('occupation_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id')->nullable();
            $table->foreignUuid('family_id')->nullable();
            $table->string('alias', 255);
            $table->string('normalized', 255);
            $table->string('lang', 16);
            $table->string('register', 64);
            $table->string('intent_scope', 64);
            $table->string('target_kind', 64);
            $table->decimal('precision_score', 5, 4)->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->string('seniority_hint', 64)->nullable();
            $table->string('function_hint', 128)->nullable();
            $table->timestamps();

            $table->index(['normalized', 'lang'], 'occupation_aliases_lookup_idx');
            $table->index(['occupation_id', 'family_id'], 'occupation_aliases_target_idx');

            $table->foreign('occupation_id')
                ->references('id')
                ->on('occupations')
                ->restrictOnDelete();
            $table->foreign('family_id')
                ->references('id')
                ->on('occupation_families')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
