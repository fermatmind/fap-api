<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'events';

    private const SHARE_STYLE_COL = 'share_style_g';

    private const PAGE_SESSION_COL = 'page_session_id_g';

    private const INDEX = 'idx_events_share_dedupe_lookup';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        Schema::table(self::TABLE, function (Blueprint $table) use ($driver): void {
            if (! Schema::hasColumn(self::TABLE, self::SHARE_STYLE_COL)) {
                if ($driver === 'mysql') {
                    $table->string(self::SHARE_STYLE_COL, 64)
                        ->storedAs("json_unquote(json_extract(meta_json, '$.share_style'))");
                } elseif ($driver === 'sqlite') {
                    $table->string(self::SHARE_STYLE_COL, 64)
                        ->storedAs("json_extract(meta_json, '$.share_style')");
                } else {
                    $table->string(self::SHARE_STYLE_COL, 64)->nullable();
                }
            }

            if (! Schema::hasColumn(self::TABLE, self::PAGE_SESSION_COL)) {
                if ($driver === 'mysql') {
                    $table->string(self::PAGE_SESSION_COL, 128)
                        ->storedAs("json_unquote(json_extract(meta_json, '$.page_session_id'))");
                } elseif ($driver === 'sqlite') {
                    $table->string(self::PAGE_SESSION_COL, 128)
                        ->storedAs("json_extract(meta_json, '$.page_session_id')");
                } else {
                    $table->string(self::PAGE_SESSION_COL, 128)->nullable();
                }
            }
        });

        $columnsReady = Schema::hasColumn(self::TABLE, self::SHARE_STYLE_COL)
            && Schema::hasColumn(self::TABLE, self::PAGE_SESSION_COL);

        if (! $columnsReady) {
            SchemaIndex::logIndexAction(
                'create_index_skip_missing_columns',
                self::TABLE,
                self::INDEX,
                $driver,
                ['phase' => 'up', 'reason' => 'generated-columns-unavailable']
            );

            return;
        }

        if (SchemaIndex::indexExists(self::TABLE, self::INDEX)) {
            SchemaIndex::logIndexAction('create_index_skip_exists', self::TABLE, self::INDEX, $driver, ['phase' => 'up']);

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(
                ['event_code', 'anon_id', 'attempt_id', self::SHARE_STYLE_COL, self::PAGE_SESSION_COL],
                self::INDEX
            );
        });

        SchemaIndex::logIndexAction('create_index', self::TABLE, self::INDEX, $driver, ['phase' => 'up']);
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
