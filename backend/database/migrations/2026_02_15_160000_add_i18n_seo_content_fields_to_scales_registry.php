<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scales_registry')) {
            return;
        }

        Schema::table('scales_registry', function (Blueprint $table): void {
            if (!Schema::hasColumn('scales_registry', 'seo_i18n_json')) {
                $table->json('seo_i18n_json')->nullable()->after('seo_schema_json');
            }

            if (!Schema::hasColumn('scales_registry', 'content_i18n_json')) {
                $table->json('content_i18n_json')->nullable()->after('seo_i18n_json');
            }

            if (!Schema::hasColumn('scales_registry', 'report_summary_i18n_json')) {
                $table->json('report_summary_i18n_json')->nullable()->after('content_i18n_json');
            }

            if (!Schema::hasColumn('scales_registry', 'is_indexable')) {
                $table->boolean('is_indexable')->default(true)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
