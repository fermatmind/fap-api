<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'orders_org_idempotency_key_unique';
    private const NEW_UNIQUE = 'orders_org_provider_idempotency_key_unique';

    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (!Schema::hasColumn('orders', 'org_id')
            || !Schema::hasColumn('orders', 'provider')
            || !Schema::hasColumn('orders', 'idempotency_key')) {
            return;
        }

        if ($this->indexExists('orders', self::OLD_UNIQUE)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }

        if ($this->indexExists('orders', self::NEW_UNIQUE)) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['org_id', 'provider', 'idempotency_key'], self::NEW_UNIQUE);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if ($this->indexExists('orders', self::NEW_UNIQUE)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        if (!Schema::hasColumn('orders', 'org_id') || !Schema::hasColumn('orders', 'idempotency_key')) {
            return;
        }

        if ($this->indexExists('orders', self::OLD_UNIQUE)) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['org_id', 'idempotency_key'], self::OLD_UNIQUE);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]);
            foreach ($rows as $row) {
                if ((string) ($row->indexname ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = DB::getDatabaseName();
        $rows = DB::select(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1",
            [$database, $table, $indexName]
        );

        return !empty($rows);
    }
};
