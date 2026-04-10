<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('analytics_career_attribution_daily')) {
            return;
        }

        Schema::create('analytics_career_attribution_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('locale', 16)->default('en');
            $table->string('surface', 128)->default('unknown');
            $table->string('route_family', 64)->default('unknown');
            $table->string('event_name', 64)->default('');
            $table->string('subject_kind', 32)->default('none');
            $table->string('subject_key', 128)->default('');
            $table->string('readiness_class', 64)->default('unknown');
            $table->string('query_mode', 16)->default('non_query');

            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('unique_anon_count')->default(0);
            $table->unsignedInteger('unique_session_count')->default(0);

            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['day', 'org_id', 'locale', 'surface', 'route_family', 'event_name', 'subject_kind', 'subject_key', 'readiness_class', 'query_mode'],
                'analytics_career_attr_daily_unique'
            );
            $table->index(['day', 'org_id'], 'analytics_career_attr_daily_day_org_idx');
            $table->index(['surface', 'event_name'], 'analytics_career_attr_daily_surface_event_idx');
            $table->index(['subject_kind', 'subject_key', 'readiness_class'], 'analytics_career_attr_daily_subject_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
