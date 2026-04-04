<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'attempt_invite_unlocks';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('target_org_id');
            $table->string('invite_code', 64)->unique('attempt_invite_unlocks_invite_code_unique');
            $table->string('target_attempt_id', 64);
            $table->string('target_scale_code', 32);
            $table->string('inviter_user_id', 64)->nullable();
            $table->string('inviter_anon_id', 128)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('required_invitees')->default(2);
            $table->unsignedTinyInteger('completed_invitees')->default(0);
            $table->string('qualification_rule_version', 32)->default('v1');
            $table->timestamp('expires_at')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['target_org_id', 'target_attempt_id'],
                'attempt_invite_unlocks_target_org_attempt_unique'
            );
            $table->index('target_org_id', 'attempt_invite_unlocks_target_org_id_idx');
            $table->index('target_scale_code', 'attempt_invite_unlocks_target_scale_code_idx');
            $table->index('status', 'attempt_invite_unlocks_status_idx');
            $table->index('expires_at', 'attempt_invite_unlocks_expires_at_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
