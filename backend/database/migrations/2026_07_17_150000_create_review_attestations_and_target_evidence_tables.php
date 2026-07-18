<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_attestations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('schema_version', 64);
            $table->string('review_mode', 32);
            $table->string('review_source', 64);
            $table->string('scope_type', 64);
            $table->string('scope_identity', 191);
            $table->string('decision', 32);
            $table->unsignedInteger('target_count');
            $table->char('target_set_sha256', 64);
            $table->char('package_sha256', 64)->nullable();
            $table->json('exceptions_json');
            $table->string('statement_version', 64);
            $table->unsignedBigInteger('attested_by_admin_user_id');
            $table->timestamp('attested_at');
            $table->char('evidence_sha256', 64);
            $table->json('canonical_evidence_json');
            $table->timestamps();

            $table->unique('evidence_sha256', 'review_attestations_evidence_unique');
            $table->index(
                ['scope_type', 'scope_identity', 'decision'],
                'review_attestations_scope_decision_idx'
            );
            $table->index('attested_at', 'review_attestations_attested_at_idx');
        });

        Schema::create('review_attestation_target_evidences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('review_attestation_id');
            $table->string('target_identity', 191);
            $table->char('target_sha256', 64);
            $table->string('target_decision', 32);
            $table->json('exception_json')->nullable();
            $table->char('evidence_sha256', 64);
            $table->timestamps();

            $table->unique(
                ['review_attestation_id', 'target_identity'],
                'review_attestation_targets_identity_unique'
            );
            $table->unique('evidence_sha256', 'review_attestation_targets_evidence_unique');
            $table->index(
                ['target_identity', 'target_sha256'],
                'review_attestation_targets_fingerprint_idx'
            );
            $table->foreign('review_attestation_id', 'review_attestation_targets_attestation_fk')
                ->references('id')
                ->on('review_attestations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('review_attestation_target_evidences')) {
            Schema::drop('review_attestation_target_evidences');
        }
        if (Schema::hasTable('review_attestations')) {
            Schema::drop('review_attestations');
        }
    }
};
