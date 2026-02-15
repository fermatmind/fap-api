<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'content_pack_releases';

    private const PROBE_INDEX = 'content_pack_releases_probe_ok_run_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'probe_ok')) {
                $table->boolean('probe_ok')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'probe_json')) {
                $table->json('probe_json')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'probe_run_at')) {
                $table->timestamp('probe_run_at')->nullable();
            }
        });

        if (
            Schema::hasColumn(self::TABLE, 'probe_ok')
            && Schema::hasColumn(self::TABLE, 'probe_run_at')
            && ! SchemaIndex::indexExists(self::TABLE, self::PROBE_INDEX)
        ) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['probe_ok', 'probe_run_at'], self::PROBE_INDEX);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
