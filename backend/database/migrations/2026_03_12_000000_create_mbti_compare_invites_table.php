<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mbti_compare_invites', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('share_id', 128)->index();
            $table->uuid('inviter_attempt_id')->index();
            $table->string('inviter_scale_code', 32)->default('MBTI');
            $table->string('locale', 16)->nullable();
            $table->string('inviter_type_code', 32)->nullable();
            $table->uuid('invitee_attempt_id')->nullable()->index();
            $table->string('invitee_anon_id', 128)->nullable();
            $table->unsignedBigInteger('invitee_user_id')->nullable()->index();
            $table->string('invitee_order_no', 64)->nullable()->index();
            $table->string('status', 16)->default('pending');
            $table->json('meta_json')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
