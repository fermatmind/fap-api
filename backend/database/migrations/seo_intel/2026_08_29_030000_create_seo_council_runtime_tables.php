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

        if (! $schema->hasTable('seo_council_runs')) {
            $schema->create('seo_council_runs', function (Blueprint $table): void {
                $table->id();
                $table->char('run_id', 64)->unique();
                $table->string('idempotency_key', 128)->unique();
                $table->char('request_hash', 64);
                $table->char('registry_hash', 64);
                $table->char('binding_hash', 64);
                $table->char('evidence_hash', 64);
                $table->string('policy_version', 32);
                $table->char('policy_hash', 64);
                $table->string('status', 64);
                $table->string('stop_reason', 96)->nullable();
                $table->unsignedInteger('receipt_version')->default(1);
                $table->char('receipt_hash', 64)->unique();
                $table->char('supersedes_receipt_hash', 64)->nullable();
                $table->json('receipt_json');
                $table->timestamps();

                $table->index(['request_hash', 'status'], 'seo_council_runs_request_status_idx');
            });
        }

        if (! $schema->hasTable('seo_council_run_steps')) {
            $schema->create('seo_council_run_steps', function (Blueprint $table): void {
                $table->id();
                $table->char('step_id', 64)->unique();
                $table->char('run_id', 64);
                $table->unsignedInteger('sequence');
                $table->string('step_type', 80);
                $table->string('status', 64);
                $table->string('stop_reason', 96)->nullable();
                $table->char('step_hash', 64)->unique();
                $table->json('step_json');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['run_id', 'sequence'], 'seo_council_steps_run_sequence_uq');
            });
        }

        if (! $schema->hasTable('seo_council_conflicts')) {
            $schema->create('seo_council_conflicts', function (Blueprint $table): void {
                $table->id();
                $table->char('conflict_id', 64)->unique();
                $table->char('run_id', 64);
                $table->string('status', 64);
                $table->boolean('human_decision_required')->default(false);
                $table->char('conflict_hash', 64)->unique();
                $table->json('conflict_json');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['run_id', 'status'], 'seo_council_conflicts_run_status_idx');
            });
        }

        if (! $schema->hasTable('seo_operator_time_entries')) {
            $schema->create('seo_operator_time_entries', function (Blueprint $table): void {
                $table->id();
                $table->char('entry_id', 64)->unique();
                $table->date('entry_date');
                $table->string('category', 48);
                $table->unsignedInteger('minutes');
                $table->char('mission_hash', 64);
                $table->char('run_hash', 64);
                $table->string('note_summary', 160);
                $table->char('entry_hash', 64)->unique();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['category', 'entry_date'], 'seo_operator_time_category_date_idx');
            });
        }
    }

    public function down(): void
    {
        // Expand-only rollback compatibility: immutable run and operator evidence remains readable.
    }
};
