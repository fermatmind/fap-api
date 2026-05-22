<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (Schema::hasTable('seo_crawler_log_daily_aggregates')) {
            return;
        }

        Schema::create('seo_crawler_log_daily_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->date('log_date');
            $table->string('host', 255)->default('unknown_host');
            $table->string('surface_family', 64)->default('unknown');
            $table->string('bot_family', 64)->default('unknown_bot');
            $table->string('bot_variant', 64)->default('unknown');
            $table->string('bot_verification_state', 64)->default('ua_claim_only');
            $table->string('route_family', 64)->default('unknown_public_path');
            $table->string('page_entity_type', 64)->nullable();
            $table->string('canonical_path', 512)->nullable();
            $table->char('path_hash', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('method_bucket', 16)->default('OTHER');
            $table->boolean('query_present')->default(false);
            $table->string('query_risk_state', 64)->default('none');
            $table->boolean('private_path_blocked')->default(false);
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('source_log_family', 64)->default('nginx_openresty_access_log');
            $table->string('privacy_transform_version', 64)->default('crawler_log_privacy_transform_v1');
            $table->char('idempotency_key', 64);
            $table->timestamps();

            $table->unique('idempotency_key', 'seo_clda_idempotency_key_unique');
            $table->index('log_date', 'seo_clda_log_date_idx');
            $table->index('host', 'seo_clda_host_idx');
            $table->index('surface_family', 'seo_clda_surface_family_idx');
            $table->index('bot_family', 'seo_clda_bot_family_idx');
            $table->index('route_family', 'seo_clda_route_family_idx');
            $table->index('http_status', 'seo_clda_http_status_idx');
            $table->index('query_risk_state', 'seo_clda_query_risk_state_idx');
            $table->index('private_path_blocked', 'seo_clda_private_path_blocked_idx');
            $table->index(['log_date', 'host', 'bot_family'], 'seo_clda_date_host_bot_idx');
            $table->index(['log_date', 'surface_family', 'route_family'], 'seo_clda_date_surface_route_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
