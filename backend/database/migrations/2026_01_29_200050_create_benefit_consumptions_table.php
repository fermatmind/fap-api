<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('benefit_consumptions')) {
            Schema::create('benefit_consumptions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('benefit_code', 64);
                $table->string('attempt_id', 64);
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();

                $table->unique(['org_id', 'benefit_code', 'attempt_id'], 'benefit_consumptions_org_benefit_attempt_unique');
                $table->index('org_id', 'benefit_consumptions_org_idx');
            });
            return;
        }

        Schema::table('benefit_consumptions', function (Blueprint $table) {
            if (!Schema::hasColumn('benefit_consumptions', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('benefit_consumptions', 'benefit_code')) {
                $table->string('benefit_code', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_consumptions', 'attempt_id')) {
                $table->string('attempt_id', 64)->nullable();
            }
            if (!Schema::hasColumn('benefit_consumptions', 'consumed_at')) {
                $table->timestamp('consumed_at')->nullable();
            }
            if (!Schema::hasColumn('benefit_consumptions', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('benefit_consumptions', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasTable('benefit_consumptions') && Schema::hasColumn('benefit_consumptions', 'org_id')) {
            DB::table('benefit_consumptions')->whereNull('org_id')->update(['org_id' => 0]);
        }

        if (!$this->indexExists('benefit_consumptions', 'benefit_consumptions_org_benefit_attempt_unique')
            && Schema::hasColumn('benefit_consumptions', 'org_id')
            && Schema::hasColumn('benefit_consumptions', 'benefit_code')
            && Schema::hasColumn('benefit_consumptions', 'attempt_id')) {
            $duplicates = DB::table('benefit_consumptions')
                ->select('org_id', 'benefit_code', 'attempt_id')
                ->whereNotNull('benefit_code')
                ->whereNotNull('attempt_id')
                ->groupBy('org_id', 'benefit_code', 'attempt_id')
                ->havingRaw('count(*) > 1')
                ->limit(1)
                ->get();
            if ($duplicates->count() === 0) {
                Schema::table('benefit_consumptions', function (Blueprint $table) {
                    $table->unique(['org_id', 'benefit_code', 'attempt_id'], 'benefit_consumptions_org_benefit_attempt_unique');
                });
            }
        }

        if (!$this->indexExists('benefit_consumptions', 'benefit_consumptions_org_idx')
            && Schema::hasColumn('benefit_consumptions', 'org_id')) {
            Schema::table('benefit_consumptions', function (Blueprint $table) {
                $table->index('org_id', 'benefit_consumptions_org_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_consumptions');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }

        if ($driver === 'mysql') {
            $rows = DB::select("SHOW INDEX FROM `{$table}`");
            foreach ($rows as $row) {
                if ((string) ($row->Key_name ?? '') === $indexName) {
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

        return false;
    }
};
