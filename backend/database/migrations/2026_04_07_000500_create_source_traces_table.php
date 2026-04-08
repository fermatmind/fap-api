<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_traces')) {
            return;
        }

        Schema::create('source_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_id', 128);
            $table->string('source_type', 64);
            $table->string('title', 255);
            $table->string('url', 2048)->nullable();
            $table->json('fields_used');
            $table->timestamp('retrieved_at');
            $table->decimal('evidence_strength', 5, 4)->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'source_traces_type_source_idx');
            $table->index('retrieved_at', 'source_traces_retrieved_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
