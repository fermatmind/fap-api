<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'storage_blob_locations';

    private const UNIQUE_INDEX = 'sbl_disk_path_uq';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! $this->supportsPortableAsciiIndexNormalization()) {
            return;
        }

        if (
            ! Schema::hasColumn(self::TABLE, 'disk')
            || ! Schema::hasColumn(self::TABLE, 'storage_path')
        ) {
            return;
        }

        if (SchemaIndex::indexExists(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `disk` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            self::TABLE
        ));
        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `storage_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            self::TABLE
        ));

        if (! SchemaIndex::indexExists(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['disk', 'storage_path'], self::UNIQUE_INDEX);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function supportsPortableAsciiIndexNormalization(): bool
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true);
    }
};
