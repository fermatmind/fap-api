<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        if (!Schema::hasTable('report_snapshots')) {
            Schema::create('report_snapshots', function (Blueprint $table) use ($isSqlite) {
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('attempt_id', 64);
                $table->string('order_no', 64)->nullable();
                $table->string('scale_code', 64);
                $table->string('pack_id', 128);
                $table->string('dir_version', 128);
                $table->string('scoring_spec_version', 64)->nullable();
                $table->string('report_engine_version', 32)->default('v1.2');
                $table->string('snapshot_version', 32)->default('v1');
                if ($isSqlite) {
                    $table->text('report_json');
                } else {
                    $table->json('report_json');
                }
                $table->timestamp('created_at');

                $table->unique(['attempt_id'], 'report_snapshots_attempt_id_unique');
                $table->index('org_id', 'report_snapshots_org_id_idx');
                $table->index('order_no', 'report_snapshots_order_no_idx');
                $table->index('scale_code', 'report_snapshots_scale_code_idx');
            });

            return;
        }

        Schema::table('report_snapshots', function (Blueprint $table) use ($isSqlite) {
            if (!Schema::hasColumn('report_snapshots', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('report_snapshots', 'attempt_id')) {
                $table->string('attempt_id', 64);
            }
            if (!Schema::hasColumn('report_snapshots', 'order_no')) {
                $table->string('order_no', 64)->nullable();
            }
            if (!Schema::hasColumn('report_snapshots', 'scale_code')) {
                $table->string('scale_code', 64);
            }
            if (!Schema::hasColumn('report_snapshots', 'pack_id')) {
                $table->string('pack_id', 128);
            }
            if (!Schema::hasColumn('report_snapshots', 'dir_version')) {
                $table->string('dir_version', 128);
            }
            if (!Schema::hasColumn('report_snapshots', 'scoring_spec_version')) {
                $table->string('scoring_spec_version', 64)->nullable();
            }
            if (!Schema::hasColumn('report_snapshots', 'report_engine_version')) {
                $table->string('report_engine_version', 32)->default('v1.2');
            }
            if (!Schema::hasColumn('report_snapshots', 'snapshot_version')) {
                $table->string('snapshot_version', 32)->default('v1');
            }
            if (!Schema::hasColumn('report_snapshots', 'report_json')) {
                if ($isSqlite) {
                    $table->text('report_json');
                } else {
                    $table->json('report_json');
                }
            }
            if (!Schema::hasColumn('report_snapshots', 'created_at')) {
                $table->timestamp('created_at');
            }
        });

        if (Schema::hasTable('report_snapshots') && Schema::hasColumn('report_snapshots', 'org_id')) {
            DB::table('report_snapshots')->whereNull('org_id')->update(['org_id' => 0]);
        }

        $uniqueName = 'report_snapshots_attempt_id_unique';
        if (Schema::hasTable('report_snapshots')
            && Schema::hasColumn('report_snapshots', 'attempt_id')
            && !$this->indexExists('report_snapshots', $uniqueName)) {
            $duplicates = DB::table('report_snapshots')
                ->select('attempt_id')
                ->whereNotNull('attempt_id')
                ->groupBy('attempt_id')
                ->havingRaw('count(*) > 1')
                ->limit(1)
                ->get();
            if ($duplicates->count() === 0) {
                Schema::table('report_snapshots', function (Blueprint $table) use ($uniqueName) {
                    $table->unique(['attempt_id'], $uniqueName);
                });
            }
        }

        if (!$this->indexExists('report_snapshots', 'report_snapshots_org_id_idx')
            && Schema::hasColumn('report_snapshots', 'org_id')) {
            Schema::table('report_snapshots', function (Blueprint $table) {
                $table->index('org_id', 'report_snapshots_org_id_idx');
            });
        }

        if (!$this->indexExists('report_snapshots', 'report_snapshots_order_no_idx')
            && Schema::hasColumn('report_snapshots', 'order_no')) {
            Schema::table('report_snapshots', function (Blueprint $table) {
                $table->index('order_no', 'report_snapshots_order_no_idx');
            });
        }

        if (!$this->indexExists('report_snapshots', 'report_snapshots_scale_code_idx')
            && Schema::hasColumn('report_snapshots', 'scale_code')) {
            Schema::table('report_snapshots', function (Blueprint $table) {
                $table->index('scale_code', 'report_snapshots_scale_code_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
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
