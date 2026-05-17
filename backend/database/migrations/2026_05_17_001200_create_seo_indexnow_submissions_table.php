<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_indexnow_submissions')) {
            return;
        }

        Schema::create('seo_indexnow_submissions', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url')->nullable();
            $table->string('source_engine', 64)->default('bing_indexnow');
            $table->string('submission_type', 64)->default('url_updated');
            $table->string('submission_status', 64)->default('dry_run');
            $table->integer('response_code')->nullable();
            $table->char('response_body_hash', 64)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('error_reason', 255)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('canonical_url_hash', 'seo_indexnow_submissions_url_hash_idx');
            $table->index('source_engine', 'seo_indexnow_submissions_source_engine_idx');
            $table->index('submission_status', 'seo_indexnow_submissions_status_idx');
            $table->index('submitted_at', 'seo_indexnow_submissions_submitted_at_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
