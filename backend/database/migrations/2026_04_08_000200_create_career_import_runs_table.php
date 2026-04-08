<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'career_import_runs';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dataset_name', 255);
            $table->string('dataset_version', 128)->nullable();
            $table->string('dataset_checksum', 128);
            $table->string('source_path', 2048)->nullable();
            $table->string('scope_mode', 64);
            $table->boolean('dry_run')->default(false);
            $table->string('status', 64);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('rows_accepted')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->json('output_counts')->nullable();
            $table->json('error_summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['dataset_name', 'dataset_checksum'], 'career_import_runs_dataset_idx');
            $table->index(['scope_mode', 'status'], 'career_import_runs_scope_status_idx');
            $table->index('started_at', 'career_import_runs_started_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
