<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SCHEMA_VERSION_LENGTH = 128;

    public function up(): void
    {
        if (! Schema::hasTable('content_release_manifests')
            || ! Schema::hasColumn('content_release_manifests', 'schema_version')) {
            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement(sprintf(
            "ALTER TABLE `content_release_manifests` MODIFY COLUMN `schema_version` VARCHAR(%d) NOT NULL DEFAULT 'storage_manifest.v1'",
            self::SCHEMA_VERSION_LENGTH,
        ));
    }

    public function down(): void
    {
        // Forward-only migration: shrinking could truncate production schema identifiers.
    }
};
