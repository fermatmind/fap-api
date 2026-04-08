<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('occupation_truth_metrics')) {
            return;
        }

        Schema::create('occupation_truth_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('occupation_id');
            $table->foreignUuid('source_trace_id')->nullable();
            $table->unsignedBigInteger('median_pay_usd_annual')->nullable();
            $table->unsignedBigInteger('jobs_2024')->nullable();
            $table->unsignedBigInteger('projected_jobs_2034')->nullable();
            $table->bigInteger('employment_change')->nullable();
            $table->decimal('outlook_pct_2024_2034', 6, 2)->nullable();
            $table->string('outlook_description', 255)->nullable();
            $table->string('entry_education', 255)->nullable();
            $table->string('work_experience', 255)->nullable();
            $table->string('on_the_job_training', 255)->nullable();
            $table->decimal('ai_exposure', 6, 2)->nullable();
            $table->longText('ai_rationale')->nullable();
            $table->string('truth_market', 32);
            $table->timestamp('effective_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['occupation_id', 'effective_at'], 'occupation_truth_metrics_occ_effective_idx');
            $table->index(['truth_market', 'effective_at'], 'occupation_truth_metrics_market_effective_idx');

            $table->foreign('occupation_id')
                ->references('id')
                ->on('occupations')
                ->restrictOnDelete();
            $table->foreign('source_trace_id')
                ->references('id')
                ->on('source_traces')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
