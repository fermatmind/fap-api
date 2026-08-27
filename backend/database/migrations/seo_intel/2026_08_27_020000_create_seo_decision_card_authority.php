<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_decision_cards')) {
            $schema->create('seo_decision_cards', function (Blueprint $table): void {
                $table->id();
                $table->string('schema_version', 64);
                $table->string('decision_card_id', 80);
                $table->uuid('decision_revision_id')->unique();
                $table->string('idempotency_key', 160)->unique();
                $table->string('cluster_uid', 64);
                $table->unsignedBigInteger('revision_number');
                $table->uuid('ledger_id');
                $table->string('detector', 80);
                $table->string('root_cause', 160);
                $table->string('page_family', 80);
                $table->string('locale', 16);
                $table->string('authority_revision', 160);
                $table->string('runtime_revision', 160)->nullable();
                $table->string('cache_revision', 160)->nullable();
                $table->string('release_revision', 160)->nullable();
                $table->unsignedInteger('affected_unique_url_count');
                $table->string('evidence_state', 32);
                $table->string('evidence_freshness', 32);
                $table->string('measurement_state', 32);
                $table->boolean('measurement_independent')->default(false);
                $table->string('business_priority', 32);
                $table->string('risk_tier', 32);
                $table->string('estimated_fix_cost', 32);
                $table->decimal('priority_score', 12, 4)->nullable();
                $table->string('highest_allowed_action', 32);
                $table->text('next_step');
                $table->string('owner', 80);
                $table->dateTime('first_observed_at');
                $table->dateTime('last_observed_at');
                $table->dateTime('expires_at');
                $table->string('status', 32);
                $table->string('close_reason', 512)->nullable();
                $table->string('selection_revision', 80)->nullable();
                $table->char('evidence_hash', 64);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['cluster_uid', 'revision_number'], 'seo_decision_cards_cluster_revision_uq');
                $table->index(['decision_card_id', 'revision_number'], 'seo_decision_cards_identity_revision_idx');
                $table->index(['status', 'last_observed_at'], 'seo_decision_cards_status_observed_idx');
                $table->index('ledger_id', 'seo_decision_cards_ledger_idx');
            });
        }

        if (! $schema->hasTable('seo_current_decision_cards')) {
            $schema->create('seo_current_decision_cards', function (Blueprint $table): void {
                // This table is only a pointer projection. seo_decision_cards remains
                // the single card authority and seo_change_ledgers remains its audit authority.
                $table->string('cluster_uid', 64)->primary();
                $table->string('decision_card_id', 80)->unique();
                $table->uuid('decision_revision_id')->unique();
                $table->timestamp('updated_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Expand-only rollback compatibility: decision authority, current pointers,
        // and their ledger bindings remain readable by the previous release.
    }
};
