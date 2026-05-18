<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (Schema::hasTable('seo_search_engine_verification_statuses')) {
            return;
        }

        Schema::create('seo_search_engine_verification_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('engine', 64);
            $table->string('site_host', 255)->nullable();
            $table->string('verification_status', 64)->default('unknown');
            $table->string('verification_method', 64)->nullable();
            $table->boolean('account_required')->default(true);
            $table->boolean('api_available')->default(false);
            $table->boolean('sitemap_submission_supported')->default(false);
            $table->boolean('url_submission_supported')->default(false);
            $table->json('notes_json')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index('engine', 'seo_search_engine_verify_engine_idx');
            $table->index('verification_status', 'seo_search_engine_verify_status_idx');
            $table->index('checked_at', 'seo_search_engine_verify_checked_at_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
