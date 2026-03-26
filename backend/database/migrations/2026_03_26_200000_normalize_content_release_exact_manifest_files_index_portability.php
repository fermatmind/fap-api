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

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! $this->supportsPortableAsciiLogicalPathNormalization()) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'logical_path')) {
            return;
        }

        if (SchemaIndex::indexExists(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('logical_path', 1024)
                ->charset('ascii')
                ->collation('ascii_bin')
                ->change();
        });

        if (! SchemaIndex::indexExists(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['content_release_exact_manifest_id', 'logical_path'], self::UNIQUE_INDEX);
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
