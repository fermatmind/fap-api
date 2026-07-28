<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_search_entry_quality_batch_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('schema_version', 64);
            $table->string('task_id', 96);
            $table->string('operation_id', 128);
            $table->string('operation_type', 16);
            $table->char('active_release_sha', 40);
            $table->string('active_release_name', 128);
            $table->char('quality_package_sha256', 64);
            $table->char('review_package_sha256', 64);
            $table->char('target_set_sha256', 64);
            $table->unsignedInteger('candidate_count');
            $table->unsignedInteger('bilingual_url_count');
            $table->unsignedBigInteger('review_attestation_id');
            $table->char('review_evidence_sha256', 64);
            $table->unsignedBigInteger('actor_admin_user_id');
            $table->string('rollback_identifier', 191);
            $table->char('apply_receipt_sha256', 64)->nullable();
            $table->char('receipt_sha256', 64);
            $table->json('canonical_receipt_json');
            $table->timestamps();

            $table->unique(
                ['operation_id', 'operation_type'],
                'career_search_entry_batch_operation_identity_unique'
            );
            $table->unique(
                ['quality_package_sha256', 'operation_type'],
                'career_search_entry_batch_package_operation_unique'
            );
            $table->unique('receipt_sha256', 'career_search_entry_batch_receipt_unique');
            $table->index(
                ['review_package_sha256', 'target_set_sha256', 'operation_type'],
                'career_search_entry_batch_runtime_lookup_idx'
            );
            $table->index(
                ['apply_receipt_sha256', 'operation_type'],
                'career_search_entry_batch_rollback_lookup_idx'
            );
            $table->foreign('review_attestation_id', 'career_search_entry_batch_review_fk')
                ->references('id')
                ->on('review_attestations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('career_search_entry_quality_batch_operations')) {
            Schema::drop('career_search_entry_quality_batch_operations');
        }
    }
};
