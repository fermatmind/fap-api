<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'referral_reward_issuances';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('org_id')->default(0)->index();
            $table->string('compare_invite_id', 128)->unique();
            $table->string('share_id', 128)->nullable()->index();
            $table->string('trigger_order_no', 64)->unique();
            $table->string('inviter_attempt_id', 64);
            $table->string('invitee_attempt_id', 64);
            $table->string('inviter_user_id', 64)->nullable();
            $table->string('invitee_user_id', 64)->nullable();
            $table->string('inviter_anon_id', 128)->nullable();
            $table->string('invitee_anon_id', 128)->nullable();
            $table->string('reward_sku', 64);
            $table->unsignedInteger('reward_quantity')->default(1);
            $table->string('status', 16)->index();
            $table->string('reason_code', 64)->nullable();
            $table->json('attribution_json')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'status', 'created_at'], 'referral_reward_issuances_org_status_created_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
