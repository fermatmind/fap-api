<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shares', function (Blueprint $table) {
            $table->uuid('id')->primary();               // share_id
            $table->uuid('attempt_id')->index();         // 关联 attempt
            $table->string('anon_id', 64)->nullable()->index();

            $table->string('scale_code', 32);
            $table->string('scale_version', 16);
            $table->string('content_package_version', 32);

            $table->timestamps();

            // 一个 attempt 复用同一个 share_id（避免每次生成都变）
            $table->unique('attempt_id');
        });
    }

    public function down(): void
    {
        // Safety: Down is a no-op to prevent accidental data loss.
        // Schema::dropIfExists('shares');
    }
};
