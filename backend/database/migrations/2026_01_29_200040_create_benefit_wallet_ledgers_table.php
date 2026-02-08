<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('benefit_wallet_ledgers')) {
            Schema::create('benefit_wallet_ledgers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('benefit_code', 64);
                $table->integer('delta');
                $table->string('reason', 64);
                $table->string('order_no', 64)->nullable();
                $table->string('attempt_id', 64)->nullable();
                $table->string('idempotency_key', 128);
                $table->json('meta_json')->nullable();
                $table->timestamps();

                $table->unique('idempotency_key', 'benefit_wallet_ledgers_idempotency_key_unique');
                $table->index(['org_id', 'benefit_code', 'created_at'], 'benefit_wallet_ledgers_org_benefit_created_idx');
            });
            return;
        }

        Schema::table('benefit_wallet_ledgers', function (Blueprint $table) {
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'benefit_code')) {
                $table->string('benefit_code', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'delta')) {
                $table->integer('delta')->default(0);
            }
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'reason')) {
                $table->string('reason', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'order_no')) {
                $table->string('order_no', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'attempt_id')) {
                $table->string('attempt_id', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'idempotency_key')) {
                $table->string('idempotency_key', 128)->nullable();
            }
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'meta_json')) {
                $table->json('meta_json')->nullable();
            }
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('benefit_wallet_ledgers', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasTable('benefit_wallet_ledgers') && Schema::hasColumn('benefit_wallet_ledgers', 'org_id')) {
            DB::table('benefit_wallet_ledgers')->whereNull('org_id')->update(['org_id' => 0]);
        }

        $uniqueName = 'benefit_wallet_ledgers_idempotency_key_unique';
        $legacyUniqueName = 'benefit_wallet_ledgers_idempotency_unique';
        if (!$this->indexExists('benefit_wallet_ledgers', $uniqueName)
            && !$this->indexExists('benefit_wallet_ledgers', $legacyUniqueName)
            && Schema::hasColumn('benefit_wallet_ledgers', 'idempotency_key')) {
            $duplicates = DB::table('benefit_wallet_ledgers')
                ->select('idempotency_key')
                ->whereNotNull('idempotency_key')
                ->groupBy('idempotency_key')
                ->havingRaw('count(*) > 1')
                ->limit(1)
                ->get();
            if ($duplicates->count() === 0) {
                Schema::table('benefit_wallet_ledgers', function (Blueprint $table) {
                    $table->unique('idempotency_key', 'benefit_wallet_ledgers_idempotency_key_unique');
                });
            }
        }

        if (!$this->indexExists('benefit_wallet_ledgers', 'benefit_wallet_ledgers_org_benefit_created_idx')
            && Schema::hasColumn('benefit_wallet_ledgers', 'org_id')
            && Schema::hasColumn('benefit_wallet_ledgers', 'benefit_code')
            && Schema::hasColumn('benefit_wallet_ledgers', 'created_at')) {
            Schema::table('benefit_wallet_ledgers', function (Blueprint $table) {
                $table->index(['org_id', 'benefit_code', 'created_at'], 'benefit_wallet_ledgers_org_benefit_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('benefit_wallet_ledgers');
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
