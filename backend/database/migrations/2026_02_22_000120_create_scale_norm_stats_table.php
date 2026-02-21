<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scale_norm_stats')) {
            return;
        }

        Schema::create('scale_norm_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('norm_version_id');
            $table->string('metric_level', 16);
            $table->string('metric_code', 32);
            $table->decimal('mean', 8, 4);
            $table->decimal('sd', 8, 4);
            $table->integer('sample_n');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['norm_version_id', 'metric_level', 'metric_code'], 'scale_norm_stats_version_metric_uniq');
            $table->index('norm_version_id', 'scale_norm_stats_norm_version_id_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
