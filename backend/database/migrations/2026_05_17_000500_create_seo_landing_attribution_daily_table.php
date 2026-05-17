<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_landing_attribution_daily')) {
            return;
        }

        Schema::create('seo_landing_attribution_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64)->default('unknown');
            $table->string('source_route_family', 64)->nullable();
            $table->string('source_slug', 255)->nullable();
            $table->string('content_id', 128)->nullable();
            $table->string('test_slug', 255)->nullable();
            $table->string('cta_id', 128)->nullable();
            $table->string('entrypoint', 128)->nullable();
            $table->unsignedInteger('first_touch_count')->default(0);
            $table->unsignedInteger('last_touch_count')->default(0);
            $table->unsignedInteger('cta_touch_count')->default(0);
            $table->unsignedInteger('landing_event_count')->default(0);
            $table->unsignedInteger('start_attempt_count')->default(0);
            $table->unsignedInteger('submit_attempt_count')->default(0);
            $table->unsignedInteger('purchase_success_count')->default(0);
            $table->timestamps();

            $table->index(['report_date', 'source_engine'], 'seo_landing_attr_daily_date_source_idx');
            $table->index(['canonical_url_hash', 'locale'], 'seo_landing_attr_daily_url_locale_idx');
            $table->index(['source_route_family', 'source_slug'], 'seo_landing_attr_daily_route_idx');
            $table->index('test_slug', 'seo_landing_attr_daily_test_slug_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
