<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'content_pack_releases';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'pack_version')) {
                $table->string('pack_version', 64)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'manifest_json')) {
                $table->json('manifest_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'storage_path')) {
                $table->string('storage_path', 512)->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'source_commit')) {
                $table->string('source_commit', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
