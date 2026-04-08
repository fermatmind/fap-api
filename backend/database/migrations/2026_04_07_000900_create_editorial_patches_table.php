<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('editorial_patches')) {
            return;
        }

        Schema::create('editorial_patches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id');
            $table->boolean('required')->default(false);
            $table->string('status', 64);
            $table->string('patch_version', 64)->nullable();
            $table->json('notes')->nullable();
            $table->timestamps();

            $table->index(['occupation_id', 'status'], 'editorial_patches_occ_status_idx');

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
