<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_user_bindings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('external_user_id', 191);
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['provider', 'external_user_id'], 'integration_user_bindings_provider_external_unique');
            $table->index(['user_id'], 'integration_user_bindings_user_id_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
