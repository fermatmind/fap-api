<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table) {
            // 主键：UUID 字符串
            $table->uuid('id')->primary();

            // 用户 & 量表信息
            $table->string('anon_id', 64);              // 匿名用户 ID（比如从 openid 映射）
            $table->string('user_id', 64)->nullable();  // 预留：以后有登录系统再用

            $table->string('scale_code', 32);           // 例如：MBTI
            $table->string('scale_version', 16);        // 例如：v0.2 / v2.5
            $table->integer('question_count');          // 本次题目数量，例如 144

            // 答案摘要：JSON
            // 示例：{"EI":{"A":12,"B":8},"SN":{"A":9,"B":11}, ...}
            $table->json('answers_summary_json');

            // 客户端 & 渠道信息
            $table->string('client_platform', 32);      // wechat-miniprogram / web 等
            $table->string('client_version', 32)->nullable();
            $table->string('channel', 32)->nullable();  // wechat_ad / pdd / organic ...
            $table->string('referrer', 255)->nullable();// 上一个页面或来源

            // 时间信息
            $table->timestamp('started_at')->nullable();    // 点击开始测评
            $table->timestamp('submitted_at')->nullable();  // 点击提交

            // Laravel 自动维护的时间字段
            $table->timestamps();   // created_at, updated_at

            // 常用查询索引
            $table->index(['anon_id', 'scale_code'], 'idx_attempts_anon_scale');
            $table->index('created_at', 'idx_attempts_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Safety: Down is a no-op to prevent accidental data loss.
        // Schema::dropIfExists('attempts');
    }
};
