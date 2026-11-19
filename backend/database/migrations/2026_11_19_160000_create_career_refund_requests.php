<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_refund_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('order_no', 128)->unique();
            $table->string('owner_key', 191);
            $table->string('provider', 24);
            $table->string('currency', 3);
            $table->unsignedInteger('amount_cents');
            $table->string('reason_code', 32);
            $table->text('detail')->nullable();
            $table->string('status', 24)->index();
            $table->uuid('approval_id')->nullable()->unique();
            $table->uuid('batch_id')->nullable()->index();
            $table->text('decision_note')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['org_id', 'status', 'created_at']);
        });
    }

    // Additive table: older releases ignore it; never auto-delete refund history.
    public function down(): void {}
};
