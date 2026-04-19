<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('page_blocks')) {
            return;
        }

        Schema::create('page_blocks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('landing_surface_id');
            $table->string('block_key', 128);
            $table->string('block_type', 64)->default('json');
            $table->string('title', 255)->nullable();
            $table->json('payload_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('landing_surface_id')
                ->references('id')
                ->on('landing_surfaces')
                ->cascadeOnDelete();
            $table->unique(['landing_surface_id', 'block_key'], 'uq_page_blocks_surface_key');
            $table->index(['landing_surface_id', 'sort_order'], 'idx_page_blocks_surface_order');
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent content loss in production.
    }
};
