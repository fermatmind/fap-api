<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unified_access_projections')) {
            return;
        }

        Schema::create('unified_access_projections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('attempt_id', 64);
            $table->string('access_state', 64);
            $table->string('report_state', 64);
            $table->string('pdf_state', 64);
            $table->string('reason_code', 128)->nullable();
            $table->unsignedInteger('projection_version')->default(1);
            $table->json('actions_json')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('produced_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id'], 'unified_access_projections_attempt_id_unique');
            $table->index('attempt_id', 'unified_access_projections_attempt_id_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
