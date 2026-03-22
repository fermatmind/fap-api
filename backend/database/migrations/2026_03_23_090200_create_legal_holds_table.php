<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legal_holds')) {
            return;
        }

        Schema::create('legal_holds', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('scope_type', 64);
            $table->string('scope_id', 64);
            $table->string('reason_code', 128);
            $table->string('placed_by', 64)->nullable();
            $table->timestamp('active_from')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id'], 'legal_holds_scope_idx');
            $table->index(['released_at'], 'legal_holds_released_at_idx');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
