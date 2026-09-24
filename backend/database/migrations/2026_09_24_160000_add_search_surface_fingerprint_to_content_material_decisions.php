<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_material_decisions', function (Blueprint $table): void {
            $table->char('search_surface_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Forward-only expand migration. Older releases ignore this nullable column.
    }
};
