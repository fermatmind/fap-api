<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_gsc_daily')) {
            return;
        }

        Schema::create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('query_display_masked', 255)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64)->default('google');
            $table->string('device', 32)->nullable();
            $table->string('country', 16)->nullable();
            $table->string('search_type', 32)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('ctr_ppm')->nullable();
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->boolean('is_brand_query')->default(false);
            $table->string('query_type', 32)->default('unknown');
            $table->string('data_state', 32)->default('final');
            $table->timestamp('collected_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('report_date', 'seo_gsc_daily_report_date_idx');
            $table->index('canonical_url_hash', 'seo_gsc_daily_url_hash_idx');
            $table->index('query_hash', 'seo_gsc_daily_query_hash_idx');
            $table->index('source_engine', 'seo_gsc_daily_source_engine_idx');
            $table->index('is_brand_query', 'seo_gsc_daily_brand_query_idx');
            $table->index('query_type', 'seo_gsc_daily_query_type_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
