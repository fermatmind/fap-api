<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_outbox')) {
            return;
        }

        Schema::table('email_outbox', function (Blueprint $table) {
            if (!Schema::hasColumn('email_outbox', 'attempt_id')) {
                $table->string('attempt_id', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
