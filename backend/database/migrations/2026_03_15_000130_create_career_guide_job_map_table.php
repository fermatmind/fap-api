<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_guide_job_map', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('career_guide_id');
            $table->unsignedBigInteger('career_job_id');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['career_guide_id', 'career_job_id'], 'uq_career_guide_job');
            $table->index(['career_guide_id', 'sort_order'], 'idx_career_guide_job_sort');
            $table->index(['career_job_id'], 'idx_career_guide_job_target');
            $table->foreign('career_guide_id', 'fk_career_guide_job_guide')
                ->references('id')
                ->on('career_guides')
                ->cascadeOnDelete();
            $table->foreign('career_job_id', 'fk_career_guide_job_job')
                ->references('id')
                ->on('career_jobs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
