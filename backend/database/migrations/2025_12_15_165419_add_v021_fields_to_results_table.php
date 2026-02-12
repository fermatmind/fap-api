<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            // v0.2.1 稳定结构字段
            $table->json('scores_pct')->nullable()->after('scores_json');
            $table->json('axis_states')->nullable()->after('scores_pct');
            $table->string('content_package_version', 32)->nullable()->after('profile_version');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};