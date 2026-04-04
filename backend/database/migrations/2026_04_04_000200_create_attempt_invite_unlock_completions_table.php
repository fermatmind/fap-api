<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'attempt_invite_unlock_completions';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('invite_id');
            $table->string('invite_code', 64);
            $table->string('target_attempt_id', 64);
            $table->string('invitee_attempt_id', 64)->nullable();
            $table->unsignedBigInteger('invitee_org_id')->nullable();
            $table->string('invitee_user_id', 64)->nullable();
            $table->string('invitee_anon_id', 128)->nullable();
            $table->string('invitee_identity_key', 191)->nullable();
            $table->boolean('qualified')->default(false);
            $table->string('qualified_reason', 64)->nullable();
            $table->string('qualification_status', 32)->default('pending_validation');
            $table->boolean('counted')->default(false);
            $table->string('counted_identity_key', 191)->nullable();
            $table->string('idempotency_key', 128);
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->foreign('invite_id', 'attempt_invite_unlock_completions_invite_id_fk')
                ->references('id')
                ->on('attempt_invite_unlocks')
                ->onDelete('cascade');

            $table->unique('idempotency_key', 'attempt_invite_unlock_completions_idempotency_key_unique');
            $table->unique(
                ['invite_id', 'invitee_attempt_id'],
                'attempt_invite_unlock_completions_invite_attempt_unique'
            );
            $table->unique(
                ['invite_id', 'invitee_identity_key'],
                'attempt_invite_unlock_completions_invite_identity_unique'
            );
            $table->unique(
                'counted_identity_key',
                'attempt_invite_unlock_completions_counted_identity_unique'
            );

            $table->index('invite_id', 'attempt_invite_unlock_completions_invite_id_idx');
            $table->index('invite_code', 'attempt_invite_unlock_completions_invite_code_idx');
            $table->index('target_attempt_id', 'attempt_invite_unlock_completions_target_attempt_idx');
            $table->index('qualification_status', 'attempt_invite_unlock_completions_status_idx');
            $table->index('qualified_reason', 'attempt_invite_unlock_completions_reason_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
