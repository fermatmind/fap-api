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
        $isMysql = $driver === 'mysql';

        if (!Schema::hasTable('attempt_answer_rows')) {
            Schema::create('attempt_answer_rows', function (Blueprint $table) use ($isSqlite, $isMysql) {
                /**
                 * MySQL 分区硬约束：
                 * - 分区键 submitted_at 必须在 PRIMARY KEY 里
                 * - PRIMARY KEY 的所有列必须 NOT NULL
                 *
                 * 因此：
                 * - MySQL：不使用自增 id；用复合主键 (attempt_id, question_id, submitted_at)
                 * - SQLite：保留 id 自增 + UNIQUE(attempt_id, question_id) 方便 upsert
                 */
                if ($isMysql) {
                    $table->uuid('attempt_id');
                    $table->unsignedBigInteger('org_id')->default(0);
                    $table->string('scale_code', 32);
                    $table->string('question_id', 128);
                    $table->integer('question_index')->default(0);
                    $table->string('question_type', 32);
                    $table->longText('answer_json')->nullable();
                    $table->integer('duration_ms')->default(0);

                    // 分区键：必须 NOT NULL 才能进入 PRIMARY KEY
                    $table->dateTime('submitted_at'); // NOT NULL
                    $table->dateTime('created_at')->nullable();

                    // 复合主键：包含分区键 submitted_at
                    $table->primary(['attempt_id', 'question_id', 'submitted_at'], 'attempt_answer_rows_pk');

                    // 查询/分析常用索引（注意：MySQL 分区表下，索引不需要都带分区键，但主键/唯一键必须带）
                    $table->index(['org_id'], 'attempt_answer_rows_org_idx');
                    $table->index(['scale_code'], 'attempt_answer_rows_scale_idx');
                    $table->index(['attempt_id'], 'attempt_answer_rows_attempt_idx');
                    $table->index(['submitted_at'], 'attempt_answer_rows_submitted_idx');
                } else {
                    // sqlite / pgsql 等：保留原始设计
                    $table->bigIncrements('id');
                    $table->uuid('attempt_id');
                    $table->unsignedBigInteger('org_id')->default(0);
                    $table->string('scale_code', 32);
                    $table->string('question_id', 128);
                    $table->integer('question_index')->default(0);
                    $table->string('question_type', 32);
                    $table->text('answer_json')->nullable();
                    $table->integer('duration_ms')->default(0);
                    $table->timestamp('submitted_at')->nullable();
                    $table->timestamp('created_at')->nullable();

                    $table->unique(['attempt_id', 'question_id'], 'attempt_answer_rows_attempt_question_unique');
                    $table->index(['org_id'], 'attempt_answer_rows_org_idx');
                    $table->index(['scale_code'], 'attempt_answer_rows_scale_idx');
                    $table->index(['attempt_id'], 'attempt_answer_rows_attempt_idx');
                    $table->index(['submitted_at'], 'attempt_answer_rows_submitted_idx');
                }
            });

            return;
        }

        // --- 表已存在：只做“缺啥补啥”，并且保证不会因为分区动作导致 migrate 失败 ---
        Schema::table('attempt_answer_rows', function (Blueprint $table) use ($isSqlite, $isMysql) {
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
                // 旧表存在时，不强行改 NOT NULL，避免生产/历史数据被打爆。
                if ($isMysql) {
                    $table->dateTime('submitted_at')->nullable();
                } else {
                    $table->timestamp('submitted_at')->nullable();
                }
            }
            if (!Schema::hasColumn('attempt_answer_rows', 'created_at')) {
                if ($isMysql) {
                    $table->dateTime('created_at')->nullable();
                } else {
                    $table->timestamp('created_at')->nullable();
                }
            }
        });

        // sqlite/非 mysql：补齐 UNIQUE 以匹配 upsert
        if (!$isMysql) {
            if (!$this->indexExists('attempt_answer_rows', 'attempt_answer_rows_attempt_question_unique')) {
                Schema::table('attempt_answer_rows', function (Blueprint $table) {
                    $table->unique(['attempt_id', 'question_id'], 'attempt_answer_rows_attempt_question_unique');
                });
            }
        }

        // 统一补齐普通索引
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
