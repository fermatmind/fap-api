<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('analytics_career_attribution_daily')) {
            return;
        }

        if (! Schema::hasColumn('analytics_career_attribution_daily', 'source_page_type')) {
            Schema::table('analytics_career_attribution_daily', function (Blueprint $table): void {
                $table->string('source_page_type', 64)->default('unknown')->after('surface');
            });
        }

        Schema::table('analytics_career_attribution_daily', function (Blueprint $table): void {
            try {
                $table->dropUnique('analytics_career_attr_daily_unique');
            } catch (\Throwable $e) {
                if (! SchemaIndex::isMissingIndexException($e, 'analytics_career_attr_daily_unique')) {
                    throw $e;
                }
            }

            try {
                $table->dropIndex('analytics_career_attr_daily_surface_event_idx');
            } catch (\Throwable $e) {
                if (! SchemaIndex::isMissingIndexException($e, 'analytics_career_attr_daily_surface_event_idx')) {
                    throw $e;
                }
            }
        });

        Schema::table('analytics_career_attribution_daily', function (Blueprint $table): void {
            $table->unique(
                ['day', 'org_id', 'locale', 'surface', 'source_page_type', 'route_family', 'event_name', 'subject_kind', 'subject_key', 'readiness_class', 'query_mode'],
                'analytics_career_attr_daily_unique'
            );
            $table->index(
                ['surface', 'source_page_type', 'event_name'],
                'analytics_career_attr_daily_surface_event_idx'
            );
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
