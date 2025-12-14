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
        Schema::create('results', function (Blueprint $table) {
            // 主键：UUID
            $table->uuid('id')->primary();

            // 关联 attempts.id
            $table->uuid('attempt_id');

            // 量表信息
            $table->string('scale_code', 32);      // 例如：MBTI
            $table->string('scale_version', 16);   // 例如：v2.5

            // 人格结果
            $table->string('type_code', 16);       // 例如：ENFJ-A
            $table->json('scores_json');           // {"EI":12,"SN":8,"TF":10,"JP":14,"AT":6}

            // 文案版本 & 有效性
            $table->string('profile_version', 32)->nullable(); // 例如：mbti32-v2.5
            $table->boolean('is_valid')->default(true);        // 是否有效

            // 结果计算时间
            $table->dateTime('computed_at');

            // Laravel 标准时间字段：created_at / updated_at
            $table->timestamps();

            // 索引
            $table->index('attempt_id', 'idx_results_attempt_id');
            $table->index('type_code', 'idx_results_type_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};