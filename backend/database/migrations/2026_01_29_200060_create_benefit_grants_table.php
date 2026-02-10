<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('benefit_grants')) {
            Schema::create('benefit_grants', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('user_id', 64)->nullable();

                $table->string('benefit_code', 64);
                $table->string('scope', 32)->default('attempt');
                $table->string('attempt_id', 64)->nullable();
                $table->string('status', 32)->default('active');
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->string('benefit_type', 64)->nullable();
                $table->string('benefit_ref', 128)->nullable();
                $table->uuid('source_order_id')->nullable();
                $table->uuid('source_event_id')->nullable();

                $table->index(['org_id', 'user_id', 'benefit_code'], 'benefit_grants_org_user_benefit_idx');
                $table->index(['attempt_id', 'benefit_code'], 'benefit_grants_attempt_benefit_idx');
                $table->unique(['source_order_id', 'benefit_type', 'benefit_ref'], 'uq_benefit_grants_source');
            });
            return;
        }

        Schema::table('benefit_grants', function (Blueprint $table) {
            if (!Schema::hasColumn('benefit_grants', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('benefit_grants', 'benefit_code')) {
                $table->string('benefit_code', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_grants', 'scope')) {
                $table->string('scope', 32)->nullable();
            }
            if (!Schema::hasColumn('benefit_grants', 'attempt_id')) {
                $table->string('attempt_id', 64)->nullable();
            }
        });

        if (!$this->indexExists('benefit_grants', 'benefit_grants_org_user_benefit_idx')
            && Schema::hasColumn('benefit_grants', 'org_id')
            && Schema::hasColumn('benefit_grants', 'user_id')
            && Schema::hasColumn('benefit_grants', 'benefit_code')) {
            Schema::table('benefit_grants', function (Blueprint $table) {
                $table->index(['org_id', 'user_id', 'benefit_code'], 'benefit_grants_org_user_benefit_idx');
            });
        }

        if (!$this->indexExists('benefit_grants', 'benefit_grants_attempt_benefit_idx')
            && Schema::hasColumn('benefit_grants', 'attempt_id')
            && Schema::hasColumn('benefit_grants', 'benefit_code')) {
            Schema::table('benefit_grants', function (Blueprint $table) {
                $table->index(['attempt_id', 'benefit_code'], 'benefit_grants_attempt_benefit_idx');
            });
        }

        if (!$this->indexExists('benefit_grants', 'uq_benefit_grants_source')
            && Schema::hasColumn('benefit_grants', 'source_order_id')
            && Schema::hasColumn('benefit_grants', 'benefit_type')
            && Schema::hasColumn('benefit_grants', 'benefit_ref')) {
            Schema::table('benefit_grants', function (Blueprint $table) {
                $table->unique(['source_order_id', 'benefit_type', 'benefit_ref'], 'uq_benefit_grants_source');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // This migration is guarded by Schema::hasTable(...) in up(), so rollback must never drop the table.
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
