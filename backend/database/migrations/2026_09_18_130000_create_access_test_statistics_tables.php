<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table): void {
            $table->char('analytics_start_ip_hash', 64)->nullable();
            $table->string('analytics_start_ip_status', 24)->nullable();
            $table->boolean('analytics_start_eligible')->nullable();
            $table->string('analytics_start_exclusion_reason', 48)->nullable();
            $table->char('analytics_submit_ip_hash', 64)->nullable();
            $table->string('analytics_submit_ip_status', 24)->nullable();
            $table->boolean('analytics_submit_eligible')->nullable();
            $table->string('analytics_submit_exclusion_reason', 48)->nullable();
            $table->string('analytics_rule_version', 64)->nullable();
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->char('analytics_ip_hash', 64)->nullable();
            $table->string('analytics_ip_status', 24)->nullable();
            $table->boolean('analytics_eligible')->nullable();
            $table->string('analytics_exclusion_reason', 48)->nullable();
            $table->string('analytics_rule_version', 64)->nullable();
        });

        Schema::create('analytics_access_test_daily', function (Blueprint $table): void {
            $table->date('day');
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('scale_code', 64)->default('*');
            $table->string('form_code', 64)->default('*');
            $table->string('locale', 16)->default('*');
            $table->unsignedInteger('valid_visit_ips')->default(0);
            $table->unsignedInteger('started_test_ips')->default(0);
            $table->unsignedInteger('completed_test_ips')->default(0);
            $table->unsignedInteger('successful_attempts')->default(0);
            $table->unsignedInteger('valid_page_views')->default(0);
            $table->unsignedInteger('missing_visit_ip_events')->default(0);
            $table->unsignedInteger('missing_started_ip_attempts')->default(0);
            $table->unsignedInteger('missing_completed_ip_attempts')->default(0);
            $table->unsignedInteger('excluded_page_views')->default(0);
            $table->unsignedInteger('excluded_started_attempts')->default(0);
            $table->unsignedInteger('excluded_completed_attempts')->default(0);
            $table->unsignedInteger('suspected_page_views')->default(0);
            $table->unsignedInteger('suspected_attempts')->default(0);
            $table->string('coverage_status', 32)->default('not_collected');
            $table->string('source_version', 64);
            $table->timestamp('data_through_at')->nullable();
            $table->timestamp('last_successful_refresh_at');
            $table->timestamps();

            $table->unique(
                ['day', 'org_id', 'scale_code', 'form_code', 'locale'],
                'analytics_access_test_daily_unique'
            );
            $table->index(['org_id', 'day'], 'analytics_access_test_daily_org_day_idx');
            $table->index(
                ['org_id', 'scale_code', 'form_code', 'locale', 'day'],
                'analytics_access_test_daily_filter_idx'
            );
        });

        $this->ensureIndex('attempts', ['org_id', 'started_at'], 'attempts_analytics_org_started_idx');
        $this->ensureIndex('attempts', ['org_id', 'submitted_at'], 'attempts_analytics_org_submitted_idx');
        $this->ensureIndex('events', ['org_id', 'event_name', 'occurred_at'], 'events_analytics_org_page_day_idx');
        $this->ensureIndex('results', ['org_id', 'computed_at'], 'results_analytics_org_computed_idx');
    }

    public function down(): void
    {
        // Forward-only expand migration. Aggregate and privacy-safe identity history must not be deleted automatically.
    }

    /** @param list<string> $columns */
    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if (SchemaIndex::indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
