<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTION_LENGTH = 128;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('content_pack_releases') || ! Schema::hasColumn('content_pack_releases', 'action')) {
            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `content_pack_releases` MODIFY COLUMN `action` VARCHAR(%d) NOT NULL',
            self::ACTION_LENGTH,
        ));
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent truncating release action identifiers.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
