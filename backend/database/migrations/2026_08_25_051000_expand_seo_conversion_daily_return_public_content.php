<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('analytics_seo_conversion_daily')
            || Schema::hasColumn('analytics_seo_conversion_daily', 'return_public_content_count')) {
            return;
        }

        Schema::table('analytics_seo_conversion_daily', static function (Blueprint $table): void {
            $table->unsignedInteger('return_public_content_count')->default(0)->after('view_result_count');
        });
    }

    public function down(): void
    {
        // Expand-only: all five public funnel stages remain available to the rolling read model.
    }
};
