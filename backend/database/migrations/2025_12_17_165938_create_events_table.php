<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            // 正常情况下 2025_12_14 会先创建；这里不重复 create
            return;
        }

        // 先补“缺列”
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
        });

        // 再做“类型/长度修正”（仅 MySQL 下执行；而且只在不匹配时执行）
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // anon_id: 64 -> 128（如果需要）
        $this->alterColumnIfNeeded('anon_id', "varchar(128) null");

        // scale_code: 32 -> 64（如果需要）
        $this->alterColumnIfNeeded('scale_code', "varchar(64) null");

        // scale_version: 16 -> 32（如果需要）
        $this->alterColumnIfNeeded('scale_version', "varchar(32) null");

        // attempt_id: uuid/char(36) nullable -> varchar(64) not null（如果需要）
        $this->alterColumnIfNeeded('attempt_id', "varchar(64) not null");

        // occurred_at：如果你希望“客户端不传也不炸”，可以给默认值（可选）
        // 如果生产已 OK（你 controller 里 now() 兜底了），这里不强改也行。
        // $this->alterColumnIfNeeded('occurred_at', "timestamp not null default current_timestamp");
    }

    public function down(): void
    {
        // 生产已 Ran，回滚 drop 表风险太大：保持 no-op
    }

    private function alterColumnIfNeeded(string $column, string $targetSqlType): void
    {
        if (!Schema::hasColumn('events', $column)) {
            return;
        }

        $row = DB::selectOne("
            SELECT column_type, is_nullable
            FROM information_schema.columns
            WHERE table_schema = database()
              AND table_name = 'events'
              AND column_name = ?
            LIMIT 1
        ", [$column]);

        if (!$row) return;

        $current = strtolower(trim(($row->column_type ?? '')));
        $nullable = strtolower(trim(($row->is_nullable ?? ''))); // yes/no

        // 只做最基本的“需要才改”的判断（避免每次都 ALTER）
        $want = strtolower($targetSqlType);

        $need = false;
        if ($column === 'anon_id' && str_contains($current, 'varchar(64)')) $need = true;
        if ($column === 'scale_code' && str_contains($current, 'varchar(32)')) $need = true;
        if ($column === 'scale_version' && str_contains($current, 'varchar(16)')) $need = true;

        if ($column === 'attempt_id') {
            // uuid 常见是 char(36) 或 varchar(36)
            if (str_contains($current, 'char(36)') || str_contains($current, 'varchar(36)') || $nullable === 'yes') {
                $need = true;
            }
        }

        // 若你手动传了更严格的类型，也允许强制触发
        if (!$need && $want !== '' && !str_contains($want, $current)) {
            // 这里不做“完全字符串比较”，只在我们上面没命中时保持保守
            return;
        }

        if ($need) {
            DB::statement("ALTER TABLE `events` MODIFY `{$column}` {$targetSqlType}");
        }
    }
};