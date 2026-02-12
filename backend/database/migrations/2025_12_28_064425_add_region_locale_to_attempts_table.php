<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('attempts', function (Blueprint $table) {
        // 放在 scale_version 后面，方便看
        $table->string('region', 32)->default('CN_MAINLAND')->after('scale_version');
        $table->string('locale', 16)->default('zh-CN')->after('region');

        $table->index(['scale_code', 'region', 'locale'], 'attempts_scale_region_locale_idx');
    });
}

public function down(): void
{
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
