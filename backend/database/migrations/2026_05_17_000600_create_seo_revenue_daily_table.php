<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_revenue_daily')) {
            return;
        }

        Schema::create('seo_revenue_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 64)->nullable();
            $table->string('cluster', 64)->nullable();
            $table->string('source_engine', 64)->default('unknown');
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('purchase_count')->default(0);
            $table->unsignedBigInteger('revenue_cents')->default(0);
            $table->string('currency', 8)->default('CNY');
            $table->unsignedInteger('aov_cents')->nullable();
            $table->unsignedInteger('rpv_proxy_cents')->nullable();
            $table->unsignedInteger('purchase_rate_ppm')->nullable();
            $table->timestamps();

            $table->index(['report_date', 'source_engine'], 'seo_revenue_daily_date_source_idx');
            $table->index(['canonical_url_hash', 'locale'], 'seo_revenue_daily_url_locale_idx');
            $table->index('cluster', 'seo_revenue_daily_cluster_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
