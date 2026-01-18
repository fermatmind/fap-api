<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ 如果你想把 result_json 放在某个字段后面：
        // 先找一个“确实存在”的列作为 after 目标
        $after = null;
        if (Schema::hasColumn('attempts', 'meta_json')) {
            $after = 'meta_json';
        } elseif (Schema::hasColumn('attempts', 'answers_json')) {
            $after = 'answers_json';
        } elseif (Schema::hasColumn('attempts', 'updated_at')) {
            $after = 'updated_at';
        }

        Schema::table('attempts', function (Blueprint $table) use ($after) {
            // ✅ 结果写回容器
            if (!Schema::hasColumn('attempts', 'result_json')) {
                $col = $table->json('result_json')->nullable();
                if ($after) $col->after($after);
            }

            // ✅ 便于检索：最终类型（如 ESFP-A）
            if (!Schema::hasColumn('attempts', 'type_code')) {
                $col = $table->string('type_code', 16)->nullable();
                if (Schema::hasColumn('attempts', 'result_json')) {
                    $col->after('result_json');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            if (Schema::hasColumn('attempts', 'type_code')) {
                $table->dropColumn('type_code');
            }
            if (Schema::hasColumn('attempts', 'result_json')) {
                $table->dropColumn('result_json');
            }
        });
    }
};