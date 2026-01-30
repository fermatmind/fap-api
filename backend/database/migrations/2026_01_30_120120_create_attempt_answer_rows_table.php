<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $isSqlite = $driver === 'sqlite';

        if (!Schema::hasTable('attempt_answer_rows')) {
            Schema::create('attempt_answer_rows', function (Blueprint $table) use ($isSqlite) {
                $table->bigIncrements('id');
                $table->uuid('attempt_id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('scale_code', 32);
                $table->string('question_id', 128);
                $table->integer('question_index')->default(0);
                $table->string('question_type', 32);
                if ($isSqlite) {
                    $table->text('answer_json')->nullable();
                } else {
                    $table->longText('answer_json')->nullable();
                }
                $table->integer('duration_ms')->default(0);
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['attempt_id', 'question_id'], 'attempt_answer_rows_attempt_question_unique');
                $table->index(['org_id'], 'attempt_answer_rows_org_idx');
                $table->index(['scale_code'], 'attempt_answer_rows_scale_idx');
                $table->index(['attempt_id'], 'attempt_answer_rows_attempt_idx');
                $table->index(['submitted_at'], 'attempt_answer_rows_submitted_idx');
            });

            $this->ensureMysqlPartitions();
            return;
        }

        Schema::table('attempt_answer_rows', function (Blueprint $table) use ($isSqlite) {
            if (!Schema::hasColumn('attempt_answer_rows', 'attempt_id')) {
                $table->uuid('attempt_id');
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'scale_code')) {
                $table->string('scale_code', 32);
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'question_id')) {
                $table->string('question_id', 128);
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'question_index')) {
                $table->integer('question_index')->default(0);
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'question_type')) {
                $table->string('question_type', 32);
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'answer_json')) {
                if ($isSqlite) {
                    $table->text('answer_json')->nullable();
                } else {
                    $table->longText('answer_json')->nullable();
                }
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'duration_ms')) {
                $table->integer('duration_ms')->default(0);
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
        });

        if (!$this->indexExists('attempt_answer_rows', 'attempt_answer_rows_attempt_question_unique')) {
            Schema::table('attempt_answer_rows', function (Blueprint $table) {
                $table->unique(['attempt_id', 'question_id'], 'attempt_answer_rows_attempt_question_unique');
            });
        }
        if (!$this->indexExists('attempt_answer_rows', 'attempt_answer_rows_org_idx')) {
            Schema::table('attempt_answer_rows', function (Blueprint $table) {
                $table->index(['org_id'], 'attempt_answer_rows_org_idx');
            });
        }
        if (!$this->indexExists('attempt_answer_rows', 'attempt_answer_rows_scale_idx')) {
            Schema::table('attempt_answer_rows', function (Blueprint $table) {
                $table->index(['scale_code'], 'attempt_answer_rows_scale_idx');
            });
        }
        if (!$this->indexExists('attempt_answer_rows', 'attempt_answer_rows_attempt_idx')) {
            Schema::table('attempt_answer_rows', function (Blueprint $table) {
                $table->index(['attempt_id'], 'attempt_answer_rows_attempt_idx');
            });
        }
        if (!$this->indexExists('attempt_answer_rows', 'attempt_answer_rows_submitted_idx')) {
            Schema::table('attempt_answer_rows', function (Blueprint $table) {
                $table->index(['submitted_at'], 'attempt_answer_rows_submitted_idx');
            });
        }

        $this->ensureMysqlPartitions();
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_answer_rows');
    }

    private function ensureMysqlPartitions(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }
        if (!Schema::hasTable('attempt_answer_rows')) {
            return;
        }
        if ($this->hasAnyPartition('attempt_answer_rows')) {
            return;
        }

        $base = Carbon::now()->startOfMonth();
        $parts = [];
        for ($i = 0; $i < 12; $i++) {
            $next = (clone $base)->addMonth();
            $name = 'p' . $base->format('Ym');
            $parts[] = "PARTITION {$name} VALUES LESS THAN ('{$next->format('Y-m-d')}')";
            $base = $next;
        }
        $parts[] = 'PARTITION pmax VALUES LESS THAN (MAXVALUE)';

        $sql = 'ALTER TABLE attempt_answer_rows PARTITION BY RANGE COLUMNS(submitted_at) (' . implode(', ', $parts) . ')';
        DB::statement($sql);
    }

    private function hasAnyPartition(string $table): bool
    {
        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT partition_name FROM information_schema.partitions WHERE table_schema = ? AND table_name = ? AND partition_name IS NOT NULL LIMIT 1',
            [$db, $table]
        );

        return !empty($rows);
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
