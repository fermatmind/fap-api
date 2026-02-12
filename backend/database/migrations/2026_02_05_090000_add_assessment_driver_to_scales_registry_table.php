<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scales_registry')) {
            return;
        }

        Schema::table('scales_registry', function (Blueprint $table) {
            if (!Schema::hasColumn('scales_registry', 'assessment_driver')) {
                $table->string('assessment_driver', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
