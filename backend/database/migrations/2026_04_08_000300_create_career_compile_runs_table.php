<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'career_compile_runs';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_run_id')->nullable();
            $table->string('compiler_version', 64);
            $table->string('scope_mode', 64);
            $table->boolean('dry_run')->default(false);
            $table->string('status', 64);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('subjects_seen')->default(0);
            $table->unsignedInteger('snapshots_created')->default(0);
            $table->unsignedInteger('snapshots_skipped')->default(0);
            $table->unsignedInteger('snapshots_failed')->default(0);
            $table->json('output_counts')->nullable();
            $table->json('error_summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['scope_mode', 'status'], 'career_compile_runs_scope_status_idx');
            $table->index(['compiler_version'], 'career_compile_runs_compiler_idx');
            $table->index('started_at', 'career_compile_runs_started_idx');

            $table->foreign('import_run_id')
                ->references('id')
                ->on('career_import_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
