<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * 目标：
         * - 新环境：events 不存在 => 创建表（可直接跑起来）
         * - 老环境：events 已存在 => 只补“缺列”（避免线上迁移炸）
         *
         * 注意：这里不做“改列类型/改长度”的强制 ALTER（更安全、避免每次部署触发风险）
         */

        if (!Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table) {
                $table->char('id', 36)->primary();

                $table->string('event_code', 64);
                $table->unsignedBigInteger('user_id')->nullable()->after('event_code'); // 可选：以后接登录用户

                $table->string('anon_id', 128)->nullable();
                $table->string('attempt_id', 64);

                $table->timestamp('occurred_at')->useCurrent(); // 客户端不传也不会炸
                $table->json('meta_json')->nullable();

                $table->timestamps();
            });

            return;
        }

        // events 已存在：幂等补列（只加缺的）
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'meta_json')) {
                $table->json('meta_json')->nullable();
            }

            if (!Schema::hasColumn('events', 'occurred_at')) {
                $table->timestamp('occurred_at')->useCurrent();
            }

            if (!Schema::hasColumn('events', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('event_code');
            }

            // 如果你未来确实要强制把 anon_id/attempt_id 等列“扩容/改类型”，
            // 建议单独做一个“手工确认后再跑”的迁移（避免线上误伤）。
        });
    }

    public function down(): void
    {
        // 生产环境不建议回滚时 drop 表；保持 no-op 更安全
        // 如需本地回滚：可手动改成 Schema::dropIfExists('events');
    }
};