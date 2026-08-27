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

        if (! $schema->hasTable('seo_change_ledgers')) {
            $schema->create('seo_change_ledgers', function (Blueprint $table): void {
                $table->id();
                $table->uuid('ledger_id')->unique();
                $table->string('schema_version', 64);
                $table->string('idempotency_key', 160)->unique();
                $table->string('change_type', 80);
                $table->text('hypothesis');
                $table->text('rationale');
                $table->json('source_json')->nullable();
                $table->json('public_url_cohort_json')->nullable();
                $table->string('page_family', 80)->nullable();
                $table->string('locale', 16)->nullable();
                $table->string('authority_revision', 160)->nullable();
                $table->json('baseline_window_json')->nullable();
                $table->json('primary_metric_json')->nullable();
                $table->json('guardrail_metrics_json')->nullable();
                $table->json('observation_window_json')->nullable();
                $table->string('change_revision', 160)->nullable();
                $table->json('canary_scope_json')->nullable();
                $table->json('blast_radius_json')->nullable();
                $table->json('public_runtime_readback_json')->nullable();
                $table->json('gsc_funnel_evidence_state_json')->nullable();
                $table->json('rollback_plan_json')->nullable();
                $table->json('owner_actor_json');
                $table->json('approval_policy_decision_json')->nullable();
                $table->string('current_state', 32)->default('draft');
                $table->string('close_reason', 512)->nullable();
                $table->unsignedBigInteger('transition_sequence')->default(0);
                $table->timestamps();

                $table->index(['current_state', 'updated_at'], 'seo_change_ledgers_state_updated_idx');
                $table->index(['page_family', 'locale'], 'seo_change_ledgers_family_locale_idx');
                $table->index('authority_revision', 'seo_change_ledgers_authority_revision_idx');
                $table->index('change_revision', 'seo_change_ledgers_change_revision_idx');
            });
        }

        if (! $schema->hasTable('seo_change_ledger_events')) {
            $schema->create('seo_change_ledger_events', function (Blueprint $table): void {
                $table->id();
                $table->uuid('event_id')->unique();
                $table->uuid('ledger_id');
                $table->unsignedBigInteger('sequence');
                $table->string('idempotency_key', 160)->unique();
                $table->string('event_type', 64);
                $table->string('from_state', 32)->nullable();
                $table->string('to_state', 32);
                $table->string('denial_code', 80)->nullable();
                $table->json('actor_json');
                $table->json('evidence_json')->nullable();
                $table->char('evidence_hash', 64);
                $table->dateTime('occurred_at');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['ledger_id', 'sequence'], 'seo_change_ledger_events_sequence_uq');
                $table->index(['ledger_id', 'occurred_at'], 'seo_change_ledger_events_ledger_time_idx');
                $table->index(['to_state', 'occurred_at'], 'seo_change_ledger_events_state_time_idx');
            });
        }
    }

    public function down(): void
    {
        // Expand-only rollback compatibility: ledger and append-only audit evidence
        // remain readable by the previous application release.
    }
};
