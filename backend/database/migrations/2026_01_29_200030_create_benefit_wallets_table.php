<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('benefit_wallets')) {
            Schema::create('benefit_wallets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('benefit_code', 64);
                $table->integer('balance')->default(0);
                $table->timestamps();

                $table->unique(['org_id', 'benefit_code'], 'benefit_wallets_org_id_benefit_code_unique');
                $table->index('org_id', 'benefit_wallets_org_idx');
            });
            return;
        }

        Schema::table('benefit_wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('benefit_wallets', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('benefit_wallets', 'benefit_code')) {
                $table->string('benefit_code', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_wallets', 'balance')) {
                $table->integer('balance')->default(0);
            }
            if (!Schema::hasColumn('benefit_wallets', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('benefit_wallets', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        // Data backfill moved to ops job to keep migration schema-only.

        $uniqueName = 'benefit_wallets_org_id_benefit_code_unique';
        $legacyUniqueName = 'benefit_wallets_org_benefit_unique';
        if (!$this->indexExists('benefit_wallets', $uniqueName)
            && !$this->indexExists('benefit_wallets', $legacyUniqueName)
            && Schema::hasColumn('benefit_wallets', 'org_id')
            && Schema::hasColumn('benefit_wallets', 'benefit_code')) {
            Schema::table('benefit_wallets', function (Blueprint $table) {
                $table->unique(['org_id', 'benefit_code'], 'benefit_wallets_org_id_benefit_code_unique');
            });
        }

        if (!$this->indexExists('benefit_wallets', 'benefit_wallets_org_idx') && Schema::hasColumn('benefit_wallets', 'org_id')) {
            Schema::table('benefit_wallets', function (Blueprint $table) {
                $table->index('org_id', 'benefit_wallets_org_idx');
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

        $db = DB::getDatabaseName();
        $rows = DB::select(
            "SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1",
            [$db, $table, $indexName]
        );
        return !empty($rows);
    }
};
