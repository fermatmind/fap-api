<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_cluster_daily')) {
            return;
        }

        Schema::create('seo_cluster_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->string('cluster', 64);
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64)->default('unknown');
            $table->unsignedInteger('landing_event_count')->default(0);
            $table->unsignedInteger('start_attempt_count')->default(0);
            $table->unsignedInteger('submit_attempt_count')->default(0);
            $table->unsignedInteger('purchase_count')->default(0);
            $table->unsignedBigInteger('revenue_cents')->default(0);
            $table->string('currency', 8)->default('CNY');
            $table->timestamps();

            $table->index(['report_date', 'cluster'], 'seo_cluster_daily_date_cluster_idx');
            $table->index(['locale', 'source_engine'], 'seo_cluster_daily_locale_source_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
