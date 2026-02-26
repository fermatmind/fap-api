<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOOKUP_INDEX = 'orders_org_order_contact_email_idx';

    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'contact_email_hash')) {
                $table->string('contact_email_hash', 64)->nullable();
            }
        });

        if (
            Schema::hasColumn('orders', 'org_id')
            && Schema::hasColumn('orders', 'order_no')
            && Schema::hasColumn('orders', 'contact_email_hash')
            && ! $this->indexExists('orders', self::LOOKUP_INDEX)
        ) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->index(['org_id', 'order_no', 'contact_email_hash'], self::LOOKUP_INDEX);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
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

        return ! empty($rows);
    }
};
