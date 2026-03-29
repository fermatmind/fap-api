<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'content_release_exact_manifest_files';

    private const UNIQUE_INDEX = 'cremf_manifest_path_uq';

    private const TEMP_FOREIGN_KEY_SUPPORT_INDEX = 'cremf_manifest_fk_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! $this->supportsPortableAsciiLogicalPathNormalization()) {
            return;
        }

        if (
            ! Schema::hasColumn(self::TABLE, 'content_release_exact_manifest_id')
            || ! Schema::hasColumn(self::TABLE, 'logical_path')
        ) {
            return;
        }

        if (! SchemaIndex::indexExists(self::TABLE, self::TEMP_FOREIGN_KEY_SUPPORT_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['content_release_exact_manifest_id'], self::TEMP_FOREIGN_KEY_SUPPORT_INDEX);
            });
        }

        if (SchemaIndex::indexExists(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `logical_path` VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            self::TABLE
        ));

        if (! SchemaIndex::indexExists(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['content_release_exact_manifest_id', 'logical_path'], self::UNIQUE_INDEX);
            });
        }

        if (SchemaIndex::indexExists(self::TABLE, self::TEMP_FOREIGN_KEY_SUPPORT_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::TEMP_FOREIGN_KEY_SUPPORT_INDEX);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function supportsPortableAsciiLogicalPathNormalization(): bool
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true);
    }
};
