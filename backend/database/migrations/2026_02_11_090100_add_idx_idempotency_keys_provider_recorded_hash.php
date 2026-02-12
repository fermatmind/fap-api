<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'idempotency_keys';
    private const INDEX = 'idx_idempo_provider_recorded_hash';
    private const ALT_INDEX = 'idx_idempo_payload';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)
            || !Schema::hasColumn(self::TABLE, 'provider')
            || !Schema::hasColumn(self::TABLE, 'recorded_at')
            || !Schema::hasColumn(self::TABLE, 'hash')
            || SchemaIndex::indexExists(self::TABLE, self::INDEX)
            || SchemaIndex::indexExists(self::TABLE, self::ALT_INDEX)
            || $this->hasAnyIndexOnColumns(self::TABLE, ['provider', 'recorded_at', 'hash'])) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['provider', 'recorded_at', 'hash'], self::INDEX);
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    /**
     * @param list<string> $columns
     */
    private function hasAnyIndexOnColumns(string $table, array $columns): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        $database = DB::getDatabaseName();
        if (!is_string($database) || $database === '') {
            return false;
        }

        $expectedSequence = implode(',', $columns);

        $rows = DB::select(
            "SELECT index_name,
                    GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS column_sequence
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ?
             GROUP BY index_name",
            [$database, $table]
        );

        foreach ($rows as $row) {
            $columnSequence = (string) ($row->column_sequence ?? $row->COLUMN_SEQUENCE ?? '');
            if ($columnSequence === $expectedSequence) {
                return true;
            }
        }

        return false;
    }
};
