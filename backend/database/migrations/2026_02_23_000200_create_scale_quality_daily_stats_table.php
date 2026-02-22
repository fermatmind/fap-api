<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scale_quality_daily_stats')) {
            Schema::create('scale_quality_daily_stats', function (Blueprint $table): void {
                $table->date('day');
                $table->string('scale_code', 64);
                $table->unsignedInteger('attempts_total')->default(0);
                $table->unsignedInteger('attempts_submitted')->default(0);
                $table->decimal('crisis_rate', 6, 4)->default(0);
                $table->decimal('speeding_rate', 6, 4)->default(0);
                $table->decimal('straightlining_rate', 6, 4)->default(0);
                $table->json('quality_level_dist_json')->nullable();
                $table->timestamps();

                $table->unique(['day', 'scale_code'], 'scale_quality_daily_stats_day_scale_unique');
            });
        }

        Schema::table('scale_quality_daily_stats', function (Blueprint $table): void {
            if (! Schema::hasColumn('scale_quality_daily_stats', 'day')) {
                $table->date('day')->nullable();
            }
            if (! Schema::hasColumn('scale_quality_daily_stats', 'scale_code')) {
                $table->string('scale_code', 64)->nullable();
            }
            if (! Schema::hasColumn('scale_quality_daily_stats', 'attempts_total')) {
                $table->unsignedInteger('attempts_total')->default(0);
            }
            if (! Schema::hasColumn('scale_quality_daily_stats', 'attempts_submitted')) {
                $table->unsignedInteger('attempts_submitted')->default(0);
            }
            if (! Schema::hasColumn('scale_quality_daily_stats', 'crisis_rate')) {
                $table->decimal('crisis_rate', 6, 4)->default(0);
            }
            if (! Schema::hasColumn('scale_quality_daily_stats', 'speeding_rate')) {
                $table->decimal('speeding_rate', 6, 4)->default(0);
            }
            if (! Schema::hasColumn('scale_quality_daily_stats', 'straightlining_rate')) {
                $table->decimal('straightlining_rate', 6, 4)->default(0);
            }
            if (! Schema::hasColumn('scale_quality_daily_stats', 'quality_level_dist_json')) {
                $table->json('quality_level_dist_json')->nullable();
            }
            if (! Schema::hasColumn('scale_quality_daily_stats', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('scale_quality_daily_stats', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
