<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attempt_retention_bindings')) {
            return;
        }

        Schema::create('attempt_retention_bindings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('attempt_id', 64);
            $table->unsignedBigInteger('retention_policy_id');
            $table->string('bound_by', 64)->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id'], 'attempt_retention_bindings_attempt_unique');
            $table->index(['retention_policy_id'], 'attempt_retention_bindings_policy_id_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
