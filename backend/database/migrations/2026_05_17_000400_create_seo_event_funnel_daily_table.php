<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_event_funnel_daily')) {
            return;
        }

        Schema::create('seo_event_funnel_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 64)->nullable();
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('cluster', 64)->nullable();
            $table->string('source_engine', 64)->default('unknown');
            $table->string('consent_state', 32)->default('unknown');
            $table->string('traffic_quality', 32)->default('unknown');
            $table->string('environment', 32)->nullable();
            $table->unsignedInteger('start_attempt_count')->default(0);
            $table->unsignedInteger('submit_attempt_count')->default(0);
            $table->unsignedInteger('view_result_count')->default(0);
            $table->unsignedInteger('click_unlock_count')->default(0);
            $table->unsignedInteger('create_order_count')->default(0);
            $table->unsignedInteger('payment_confirmed_count')->default(0);
            $table->unsignedInteger('purchase_success_count')->default(0);
            $table->timestamps();

            $table->index(['report_date', 'source_engine'], 'seo_event_funnel_daily_date_source_idx');
            $table->index(['canonical_url_hash', 'locale'], 'seo_event_funnel_daily_url_locale_idx');
            $table->index(['page_entity_type', 'locale'], 'seo_event_funnel_daily_entity_locale_idx');
            $table->index('cluster', 'seo_event_funnel_daily_cluster_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
