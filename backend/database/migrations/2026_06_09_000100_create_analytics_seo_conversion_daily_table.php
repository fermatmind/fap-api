<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('analytics_seo_conversion_daily')) {
            return;
        }

        Schema::create('analytics_seo_conversion_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->unsignedBigInteger('org_id')->default(0);

            $table->text('url');
            $table->char('url_hash', 40);
            $table->string('lang', 16)->default('');
            $table->string('page_type', 64)->default('');
            $table->text('source_url')->nullable();
            $table->char('source_url_hash', 40)->default('');
            $table->string('source_article', 160)->default('');
            $table->char('source_article_hash', 40)->default('');
            $table->text('target_test')->nullable();
            $table->char('target_test_hash', 40)->default('');
            $table->string('scale_id', 64)->default('');
            $table->string('form_id', 64)->default('');
            $table->char('session_id_hash', 64)->default('');
            $table->string('referrer_host', 160)->default('');
            $table->char('referrer_host_hash', 40)->default('');

            $table->unsignedInteger('landing_pv_count')->default(0);
            $table->unsignedInteger('article_to_test_click_count')->default(0);
            $table->unsignedInteger('start_test_count')->default(0);
            $table->unsignedInteger('complete_test_count')->default(0);
            $table->unsignedInteger('view_result_count')->default(0);

            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(
                [
                    'day',
                    'org_id',
                    'url_hash',
                    'lang',
                    'page_type',
                    'source_url_hash',
                    'source_article_hash',
                    'target_test_hash',
                    'scale_id',
                    'form_id',
                    'session_id_hash',
                    'referrer_host_hash',
                ],
                'analytics_seo_conv_daily_scope_unique'
            );
            $table->index(['day', 'org_id'], 'analytics_seo_conv_daily_day_org_idx');
            $table->index(['url_hash', 'day'], 'analytics_seo_conv_daily_url_idx');
            $table->index(['source_article_hash', 'day'], 'analytics_seo_conv_daily_article_idx');
            $table->index(['target_test_hash', 'day'], 'analytics_seo_conv_daily_test_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
