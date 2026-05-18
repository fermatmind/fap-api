<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (Schema::hasTable('seo_consent_daily')) {
            return;
        }

        Schema::create('seo_consent_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->string('consent_state', 32);
            $table->string('source_engine', 64)->default('unknown');
            $table->unsignedInteger('event_count')->default(0);
            $table->timestamps();

            $table->index(['report_date', 'consent_state'], 'seo_consent_daily_date_state_idx');
            $table->index('source_engine', 'seo_consent_daily_source_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
