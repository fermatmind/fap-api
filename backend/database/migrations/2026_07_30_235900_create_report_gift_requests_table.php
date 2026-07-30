<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'report_gift_requests';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('public_token_hash', 64);
            $table->unsignedBigInteger('org_id');
            $table->string('target_attempt_id', 64);
            $table->string('recipient_user_id', 64)->nullable();
            $table->string('recipient_anon_id', 128)->nullable();
            $table->string('scale_code', 32);
            $table->string('sku', 64);
            $table->string('status', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->uuid('purchased_order_id')->nullable();
            $table->string('purchased_by_user_id', 64)->nullable();
            $table->string('purchased_by_anon_id', 128)->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->foreign('purchased_order_id', 'report_gift_requests_purchased_order_id_fk')
                ->references('id')
                ->on('orders')
                ->onDelete('restrict');

            $table->unique('public_token_hash', 'report_gift_requests_public_token_hash_unique');
            $table->unique('purchased_order_id', 'report_gift_requests_purchased_order_id_unique');
            $table->index('org_id', 'report_gift_requests_org_id_idx');
            $table->index('target_attempt_id', 'report_gift_requests_target_attempt_id_idx');
            $table->index('status', 'report_gift_requests_status_idx');
            $table->index('expires_at', 'report_gift_requests_expires_at_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
