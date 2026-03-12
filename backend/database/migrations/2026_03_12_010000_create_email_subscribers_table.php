<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_subscribers')) {
            return;
        }

        Schema::create('email_subscribers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('pii_email_key_version', 32)->nullable();
            $table->text('email_enc');
            $table->string('email_hash', 64)->unique();
            $table->string('locale', 16)->nullable();
            $table->string('first_source', 64)->nullable();
            $table->string('last_source', 64)->nullable();
            $table->boolean('marketing_consent')->default(false);
            $table->boolean('transactional_recovery_enabled')->default(true);
            $table->json('first_context_json')->nullable();
            $table->json('last_context_json')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
