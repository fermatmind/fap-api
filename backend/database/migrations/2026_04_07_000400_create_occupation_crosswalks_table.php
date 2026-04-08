<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('occupation_crosswalks')) {
            return;
        }

        Schema::create('occupation_crosswalks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id');
            $table->string('source_system', 64);
            $table->string('source_code', 128)->nullable();
            $table->string('source_title', 255);
            $table->string('mapping_type', 64);
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['occupation_id', 'source_system'], 'occupation_crosswalks_occ_system_idx');
            $table->index(['source_system', 'source_code'], 'occupation_crosswalks_source_idx');

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
