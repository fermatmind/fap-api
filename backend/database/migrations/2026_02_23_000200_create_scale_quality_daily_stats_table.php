<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scale_quality_daily_stats')) {
            return;
        }

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

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
