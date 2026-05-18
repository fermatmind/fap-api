<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (Schema::hasTable('seo_domestic_submission_logs')) {
            return;
        }

        Schema::create('seo_domestic_submission_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('engine', 64);
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64);
            $table->string('submission_type', 64)->default('sitemap_or_url');
            $table->string('submission_status', 64)->default('dry_run');
            $table->integer('response_code')->nullable();
            $table->char('response_body_hash', 64)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('error_reason', 255)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('engine', 'seo_domestic_submission_engine_idx');
            $table->index('canonical_url_hash', 'seo_domestic_submission_url_hash_idx');
            $table->index('source_engine', 'seo_domestic_submission_source_engine_idx');
            $table->index('submission_status', 'seo_domestic_submission_status_idx');
            $table->index('submitted_at', 'seo_domestic_submission_submitted_at_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
