<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('analytics_mbti_attribution_daily')) {
            Schema::create('analytics_mbti_attribution_daily', function (Blueprint $table): void {
                $table->id();
                $table->date('day');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('locale', 16)->default('en');
                $table->string('entry_surface', 128)->default('unknown');
                $table->string('source_page_type', 64)->default('unknown');
                $table->string('test_slug', 128)->default('');
                $table->string('form_code', 64)->default('');

                $table->unsignedInteger('entry_views')->default(0);
                $table->unsignedInteger('start_clicks')->default(0);
                $table->unsignedInteger('start_attempts')->default(0);
                $table->unsignedInteger('result_views')->default(0);
                $table->unsignedInteger('unlock_clicks')->default(0);
                $table->unsignedInteger('orders_created')->default(0);
                $table->unsignedInteger('payments_confirmed')->default(0);
                $table->unsignedInteger('unlock_successes')->default(0);
                $table->unsignedInteger('payment_unlock_successes')->default(0);
                $table->unsignedInteger('invite_creates')->default(0);
                $table->unsignedInteger('invite_shares')->default(0);
                $table->unsignedInteger('invite_completions')->default(0);
                $table->unsignedInteger('invite_unlock_successes')->default(0);

                $table->timestamp('last_refreshed_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['day', 'org_id', 'locale', 'entry_surface', 'source_page_type', 'test_slug', 'form_code'],
                    'analytics_mbti_attr_daily_unique'
                );
                $table->index(['day', 'org_id'], 'analytics_mbti_attr_daily_day_org_idx');
                $table->index(['entry_surface', 'source_page_type'], 'analytics_mbti_attr_daily_surface_idx');
            });

            return;
        }

        Schema::table('analytics_mbti_attribution_daily', function (Blueprint $table): void {
            if (! Schema::hasColumn('analytics_mbti_attribution_daily', 'payment_unlock_successes')) {
                $table->unsignedInteger('payment_unlock_successes')->default(0)->after('unlock_successes');
            }
            if (! Schema::hasColumn('analytics_mbti_attribution_daily', 'invite_unlock_successes')) {
                $table->unsignedInteger('invite_unlock_successes')->default(0)->after('invite_completions');
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
