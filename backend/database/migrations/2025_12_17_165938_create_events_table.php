<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 幂等：线上表已存在时，直接跳过，避免部署炸迁移
        if (Schema::hasTable('events')) {
            return;
        }

        Schema::create('events', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('event_code', 64);
            $table->string('anon_id', 128)->nullable();
            $table->string('attempt_id', 64);
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('events');
    }
};
