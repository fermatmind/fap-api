<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_crawler_logs_daily')) {
            return;
        }

        Schema::create('seo_crawler_logs_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->char('path_hash', 64)->nullable();
            $table->string('path_display_masked', 512)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 64)->nullable();
            $table->string('source_engine', 64)->default('unknown');
            $table->string('bot_family', 64)->default('unknown_bot');
            $table->char('user_agent_hash', 64)->nullable();
            $table->string('method', 16)->nullable();
            $table->unsignedInteger('status_code')->nullable();
            $table->string('response_time_bucket', 32)->nullable();
            $table->unsignedInteger('crawl_count')->default(0);
            $table->boolean('robots_allowed')->nullable();
            $table->boolean('blocked_by_robots')->nullable();
            $table->boolean('private_flow_hit')->default(false);
            $table->boolean('noindex_hit')->default(false);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('report_date', 'seo_crawler_logs_daily_report_date_idx');
            $table->index('source_engine', 'seo_crawler_logs_daily_source_engine_idx');
            $table->index('bot_family', 'seo_crawler_logs_daily_bot_family_idx');
            $table->index('canonical_url_hash', 'seo_crawler_logs_daily_canonical_hash_idx');
            $table->index('status_code', 'seo_crawler_logs_daily_status_code_idx');
            $table->index('private_flow_hit', 'seo_crawler_logs_daily_private_flow_idx');
            $table->index('noindex_hit', 'seo_crawler_logs_daily_noindex_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
