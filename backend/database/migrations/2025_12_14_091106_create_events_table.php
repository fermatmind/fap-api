<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 事件日志表：events
     * 用来记录 scale_view / test_submit / result_view 等
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            // 统一用 UUID 主键
            $table->uuid('id')->primary();

            $table->string('event_code', 64);             // 事件类型：test_submit / result_view ...
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('anon_id', 64)->nullable();

            $table->string('scale_code', 32)->nullable();  // MBTI / DEPRESSION 等
            $table->string('scale_version', 16)->nullable();
            $table->uuid('attempt_id')->nullable();

            $table->string('channel', 32)->nullable();     // dev / wechat-miniprogram ...
            $table->string('region', 32)->nullable();      // CN_MAINLAND
            $table->string('locale', 32)->nullable();      // zh-CN

            $table->string('client_platform', 32)->nullable(); // wechat-miniprogram
            $table->string('client_version', 32)->nullable();  // 1.0.0

            $table->json('meta_json')->nullable();         // 附加信息（题目数量等）
            $table->timestamp('occurred_at');              // 事件发生时间

            $table->timestamps();

            // 常用索引
            $table->index(['event_code', 'occurred_at']);
            $table->index(['scale_code', 'occurred_at']);
            $table->index(['anon_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        // Safety: Down is a no-op to prevent accidental data loss.
        // Schema::dropIfExists('events');
    }
};
