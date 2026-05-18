<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (Schema::hasTable('seo_baidu_landing_daily')) {
            return;
        }

        Schema::create('seo_baidu_landing_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64)->default('baidu');
            $table->unsignedInteger('landing_event_count')->default(0);
            $table->unsignedInteger('start_attempt_count')->default(0);
            $table->unsignedInteger('submit_attempt_count')->default(0);
            $table->unsignedInteger('view_result_count')->default(0);
            $table->unsignedInteger('purchase_success_count')->default(0);
            $table->unsignedBigInteger('revenue_cents')->default(0);
            $table->string('currency', 8)->default('CNY');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('report_date', 'seo_baidu_landing_daily_report_date_idx');
            $table->index('canonical_url_hash', 'seo_baidu_landing_daily_url_hash_idx');
            $table->index('source_engine', 'seo_baidu_landing_daily_source_engine_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
