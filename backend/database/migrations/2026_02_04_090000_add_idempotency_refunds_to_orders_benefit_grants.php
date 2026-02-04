<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'idempotency_key')) {
                    $table->string('idempotency_key', 128)->nullable();
                }
                if (!Schema::hasColumn('orders', 'refunded_at')) {
                    $table->timestamp('refunded_at')->nullable();
                }
                if (!Schema::hasColumn('orders', 'refund_amount_cents')) {
                    $table->integer('refund_amount_cents')->nullable();
                }
                if (!Schema::hasColumn('orders', 'refund_reason')) {
                    $table->string('refund_reason', 255)->nullable();
                }
            });

            if (!$this->indexExists('orders', 'orders_org_idempotency_key_unique')
                && Schema::hasColumn('orders', 'org_id')
                && Schema::hasColumn('orders', 'idempotency_key')) {
                $duplicates = DB::table('orders')
                    ->select('org_id', 'idempotency_key')
                    ->whereNotNull('idempotency_key')
                    ->where('idempotency_key', '!=', '')
                    ->groupBy('org_id', 'idempotency_key')
                    ->havingRaw('count(*) > 1')
                    ->limit(1)
                    ->get();
                if ($duplicates->count() === 0) {
                    Schema::table('orders', function (Blueprint $table) {
                        $table->unique(['org_id', 'idempotency_key'], 'orders_org_idempotency_key_unique');
                    });
                }
            }
        }

        if (Schema::hasTable('benefit_grants')) {
            Schema::table('benefit_grants', function (Blueprint $table) {
                if (!Schema::hasColumn('benefit_grants', 'revoked_at')) {
                    $table->timestamp('revoked_at')->nullable();
                }
            });

            if (!$this->indexExists('benefit_grants', 'benefit_grants_org_benefit_attempt_status_idx')
                && Schema::hasColumn('benefit_grants', 'org_id')
                && Schema::hasColumn('benefit_grants', 'benefit_code')
                && Schema::hasColumn('benefit_grants', 'attempt_id')
                && Schema::hasColumn('benefit_grants', 'status')) {
                Schema::table('benefit_grants', function (Blueprint $table) {
                    $table->index(
                        ['org_id', 'benefit_code', 'attempt_id', 'status'],
                        'benefit_grants_org_benefit_attempt_status_idx'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            if ($this->indexExists('orders', 'orders_org_idempotency_key_unique')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropUnique('orders_org_idempotency_key_unique');
                });
            }

            Schema::table('orders', function (Blueprint $table) {
                if (Schema::hasColumn('orders', 'idempotency_key')) {
                    $table->dropColumn('idempotency_key');
                }
                if (Schema::hasColumn('orders', 'refunded_at')) {
                    $table->dropColumn('refunded_at');
                }
                if (Schema::hasColumn('orders', 'refund_amount_cents')) {
                    $table->dropColumn('refund_amount_cents');
                }
                if (Schema::hasColumn('orders', 'refund_reason')) {
                    $table->dropColumn('refund_reason');
                }
            });
        }

        if (Schema::hasTable('benefit_grants')) {
            if ($this->indexExists('benefit_grants', 'benefit_grants_org_benefit_attempt_status_idx')) {
                Schema::table('benefit_grants', function (Blueprint $table) {
                    $table->dropIndex('benefit_grants_org_benefit_attempt_status_idx');
                });
            }

            Schema::table('benefit_grants', function (Blueprint $table) {
                if (Schema::hasColumn('benefit_grants', 'revoked_at')) {
                    $table->dropColumn('revoked_at');
                }
            });
        }
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
