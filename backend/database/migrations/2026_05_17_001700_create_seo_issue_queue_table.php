<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_issue_queue')) {
            return;
        }

        Schema::create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_uid', 128)->unique();
            $table->string('issue_type', 64);
            $table->string('severity', 32)->default('info');
            $table->string('source_system', 64);
            $table->string('source_engine', 64)->nullable();
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 64)->nullable();
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('cluster', 64)->nullable();
            $table->string('status', 32)->default('open');
            $table->string('lifecycle_state', 32)->default('open');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->string('summary', 512)->nullable();
            $table->string('recommendation', 512)->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('issue_type', 'seo_issue_queue_issue_type_idx');
            $table->index('severity', 'seo_issue_queue_severity_idx');
            $table->index('source_system', 'seo_issue_queue_source_system_idx');
            $table->index('source_engine', 'seo_issue_queue_source_engine_idx');
            $table->index('canonical_url_hash', 'seo_issue_queue_canonical_hash_idx');
            $table->index('status', 'seo_issue_queue_status_idx');
            $table->index('lifecycle_state', 'seo_issue_queue_lifecycle_idx');
            $table->index('detected_at', 'seo_issue_queue_detected_at_idx');
            $table->index('page_entity_type', 'seo_issue_queue_page_entity_type_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
