<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('artifact_reconcile_cases')) {
            return;
        }

        Schema::create('artifact_reconcile_cases', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('attempt_id', 64)->nullable();
            $table->string('slot_code', 64)->nullable();
            $table->string('case_type', 64);
            $table->string('status', 64)->default('open');
            $table->string('suspected_cause', 128)->nullable();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('resolution_code', 128)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('attempt_id', 'artifact_reconcile_cases_attempt_id_idx');
            $table->index('case_type', 'artifact_reconcile_cases_case_type_idx');
            $table->index('status', 'artifact_reconcile_cases_status_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
