<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attempt_email_bindings')) {
            return;
        }

        Schema::create('attempt_email_bindings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->uuid('attempt_id');
            $table->string('pii_email_key_version', 32)->nullable();
            $table->string('email_hash', 64);
            $table->text('email_enc');
            $table->string('bound_anon_id', 128)->nullable();
            $table->string('bound_user_id', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->string('source', 64)->default('result_gate');
            $table->timestamp('first_bound_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'attempt_id', 'email_hash'], 'attempt_email_bindings_attempt_email_uq');
            $table->index(['org_id', 'email_hash', 'created_at'], 'attempt_email_bindings_email_created_idx');
            $table->index(['org_id', 'attempt_id'], 'attempt_email_bindings_attempt_idx');
            $table->index('status', 'attempt_email_bindings_status_idx');
        });
    }

    public function down(): void
    {
        // Forward-only migration: rollback disabled to avoid deleting result access bindings.
    }
};
