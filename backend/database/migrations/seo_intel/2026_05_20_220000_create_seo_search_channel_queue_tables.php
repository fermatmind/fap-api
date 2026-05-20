<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        if (! Schema::hasTable('seo_search_channel_queue_batches')) {
            Schema::create('seo_search_channel_queue_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('channel', 64);
                $table->string('status', 64)->default('draft');
                $table->unsignedInteger('item_count')->default(0);
                $table->json('dry_run_report')->nullable();
                $table->text('approval_note')->nullable();
                $table->string('created_by', 128)->nullable();
                $table->string('approved_by', 128)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->index(['channel', 'status'], 'seo_scq_batches_channel_status_idx');
                $table->index('approved_at', 'seo_scq_batches_approved_at_idx');
            });
        }

        if (! Schema::hasTable('seo_search_channel_queue_items')) {
            Schema::create('seo_search_channel_queue_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('batch_id')->nullable()->index('seo_scq_items_batch_id_idx');
                $table->text('canonical_url');
                $table->string('locale', 16);
                $table->string('page_entity_type', 64);
                $table->string('entity_type', 64)->nullable();
                $table->string('entity_id', 255)->nullable();
                $table->string('source_authority', 64);
                $table->string('source_table', 128)->nullable();
                $table->string('channel', 64);
                $table->string('eligibility_state', 64)->default('eligible');
                $table->string('approval_state', 64)->default('pending');
                $table->string('execution_state', 64)->default('dry_run_ready');
                $table->string('indexability_state', 64);
                $table->string('claim_boundary_state', 64)->default('claim_safe');
                $table->boolean('private_flow')->default(false);
                $table->json('reason_codes')->nullable();
                $table->timestamp('lastmod')->nullable();
                $table->char('content_hash', 64)->nullable();
                $table->char('url_hash', 64);
                $table->char('idempotency_key', 64);
                $table->string('approved_by', 128)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->unique('idempotency_key', 'seo_scq_items_idempotency_unique');
                $table->index(['channel', 'eligibility_state'], 'seo_scq_items_channel_eligibility_idx');
                $table->index(['approval_state', 'execution_state'], 'seo_scq_items_state_idx');
                $table->index(['page_entity_type', 'locale'], 'seo_scq_items_type_locale_idx');
                $table->index('url_hash', 'seo_scq_items_url_hash_idx');
            });
        }

        if (! Schema::hasTable('seo_search_channel_queue_events')) {
            Schema::create('seo_search_channel_queue_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('queue_item_id')->nullable();
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->string('event_type', 96);
                $table->json('event_payload')->nullable();
                $table->string('actor_type', 64)->default('system');
                $table->string('actor_id', 128)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('queue_item_id', 'seo_scq_events_item_id_idx');
                $table->index('batch_id', 'seo_scq_events_batch_id_idx');
                $table->index(['event_type', 'created_at'], 'seo_scq_events_type_created_idx');
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
