<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('analytics_seo_conversion_daily')) {
            return;
        }

        $addSourceArticleId = ! Schema::hasColumn('analytics_seo_conversion_daily', 'source_article_id');
        $addResultReadyCount = ! Schema::hasColumn('analytics_seo_conversion_daily', 'result_ready_count');

        Schema::table('analytics_seo_conversion_daily', static function (Blueprint $table) use ($addResultReadyCount, $addSourceArticleId): void {
            if ($addSourceArticleId) {
                $table->unsignedBigInteger('source_article_id')->nullable()->after('source_article');
            }

            if ($addResultReadyCount) {
                $table->unsignedInteger('result_ready_count')->default(0)->after('complete_test_count');
            }
        });

        if ($addSourceArticleId) {
            Schema::table('analytics_seo_conversion_daily', static function (Blueprint $table): void {
                $table->index(['source_article_id', 'day'], 'analytics_seo_conv_daily_article_id_idx');
            });
        }
    }

    public function down(): void
    {
        // Forward-only migration: article attribution history must be corrected by a forward fix.
    }
};
