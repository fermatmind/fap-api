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

        // ✅ 修复历史 NOT NULL（MySQL/PG）
        if (Schema::hasTable('benefit_grants')) {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'mysql') {
                if (Schema::hasColumn('benefit_grants', 'user_id')) {
                    DB::statement('ALTER TABLE benefit_grants MODIFY user_id varchar(64) NULL');
                }
                if (Schema::hasColumn('benefit_grants', 'benefit_ref')) {
                    DB::statement('ALTER TABLE benefit_grants MODIFY benefit_ref varchar(128) NULL');
                }
                if (Schema::hasColumn('benefit_grants', 'benefit_type')) {
                    DB::statement('ALTER TABLE benefit_grants MODIFY benefit_type varchar(64) NULL');
                }
            } elseif ($driver === 'pgsql') {
                if (Schema::hasColumn('benefit_grants', 'user_id')) {
                    DB::statement('ALTER TABLE benefit_grants ALTER COLUMN user_id DROP NOT NULL');
                }
                if (Schema::hasColumn('benefit_grants', 'benefit_ref')) {
                    DB::statement('ALTER TABLE benefit_grants ALTER COLUMN benefit_ref DROP NOT NULL');
                }
                if (Schema::hasColumn('benefit_grants', 'benefit_type')) {
                    DB::statement('ALTER TABLE benefit_grants ALTER COLUMN benefit_type DROP NOT NULL');
                }
            }
            // sqlite 不做列改造：CI/新库由 2026_01_22_* 的创建分支直接生成 nullable
        }

        if (Schema::hasTable('benefit_grants') && Schema::hasColumn('benefit_grants', 'org_id')) {
            DB::table('benefit_grants')->whereNull('org_id')->update(['org_id' => 0]);
        }

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
            $duplicates = DB::table('benefit_grants')
                ->select('source_order_id', 'benefit_type', 'benefit_ref')
                ->whereNotNull('source_order_id')
                ->whereNotNull('benefit_type')
                ->whereNotNull('benefit_ref')
                ->groupBy('source_order_id', 'benefit_type', 'benefit_ref')
                ->havingRaw('count(*) > 1')
                ->limit(1)
                ->get();
            if ($duplicates->count() === 0) {
                Schema::table('benefit_grants', function (Blueprint $table) {
                    $table->unique(['source_order_id', 'benefit_type', 'benefit_ref'], 'uq_benefit_grants_source');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_grants');
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
