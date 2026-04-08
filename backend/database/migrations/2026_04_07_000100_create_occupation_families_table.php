<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('occupation_families')) {
            return;
        }

        Schema::create('occupation_families', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('canonical_slug', 160)->unique();
            $table->string('title_en', 255);
            $table->string('title_zh', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
