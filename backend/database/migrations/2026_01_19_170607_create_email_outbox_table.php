<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_outbox')) {
            return;
        }

        Schema::create('email_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id', 64)->index();
            $table->string('email');
            $table->string('template', 64);
            $table->json('payload_json')->nullable();
            $table->string('claim_token_hash', 64)->unique();
            $table->timestamp('claim_expires_at')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_outbox');
    }
};
