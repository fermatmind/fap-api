<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // ✅ sqlite 兼容：uuid = char(36)
            if (!Schema::hasColumn('events', 'share_id')) {
                $table->uuid('share_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // ⚠️ sqlite 对 dropIndex/dropColumn 支持不一致，尽量写稳
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'share_id')) {
                // 默认索引名：events_share_id_index
                try { $table->dropIndex('events_share_id_index'); } catch (\Throwable $e) {}
                $table->dropColumn('share_id');
            }
        });
    }
};