<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fm_tokens', function (Blueprint $table) {
            $table->string('token', 80)->primary();      // fm_xxx
            $table->string('anon_id', 64)->index();      // anon_xxx
            $table->timestamp('expires_at')->nullable(); // 先不启用也行
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
