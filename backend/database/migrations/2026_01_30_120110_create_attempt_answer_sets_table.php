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

        if (!Schema::hasTable('attempt_answer_sets')) {
            Schema::create('attempt_answer_sets', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('attempt_id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('scale_code', 32);
                $table->string('pack_id', 128)->nullable();
                $table->string('dir_version', 128)->nullable();
                $table->string('scoring_spec_version', 64)->nullable();
                if ($isSqlite) {
                    $table->text('answers_json')->nullable();
                } else {
                    $table->longText('answers_json')->nullable();
                }
                $table->string('answers_hash', 64)->nullable();
                $table->integer('question_count')->default(0);
                $table->integer('duration_ms')->default(0);
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['org_id'], 'attempt_answer_sets_org_idx');
                $table->index(['scale_code'], 'attempt_answer_sets_scale_idx');
                $table->index(['answers_hash'], 'attempt_answer_sets_hash_idx');
                $table->index(['submitted_at'], 'attempt_answer_sets_submitted_idx');
            });

            return;
        }

        Schema::table('attempt_answer_sets', function (Blueprint $table) use ($isSqlite) {
            if (!Schema::hasColumn('attempt_answer_sets', 'attempt_id')) {
                $table->uuid('attempt_id');
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'scale_code')) {
                $table->string('scale_code', 32);
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'pack_id')) {
                $table->string('pack_id', 128)->nullable();
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'dir_version')) {
                $table->string('dir_version', 128)->nullable();
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'scoring_spec_version')) {
                $table->string('scoring_spec_version', 64)->nullable();
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'answers_json')) {
                if ($isSqlite) {
                    $table->text('answers_json')->nullable();
                } else {
                    $table->longText('answers_json')->nullable();
                }
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'answers_hash')) {
                $table->string('answers_hash', 64)->nullable();
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'question_count')) {
                $table->integer('question_count')->default(0);
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'duration_ms')) {
                $table->integer('duration_ms')->default(0);
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('attempt_answer_sets', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
        });

        if (!$this->indexExists('attempt_answer_sets', 'attempt_answer_sets_org_idx')) {
            Schema::table('attempt_answer_sets', function (Blueprint $table) {
                $table->index(['org_id'], 'attempt_answer_sets_org_idx');
            });
        }
        if (!$this->indexExists('attempt_answer_sets', 'attempt_answer_sets_scale_idx')) {
            Schema::table('attempt_answer_sets', function (Blueprint $table) {
                $table->index(['scale_code'], 'attempt_answer_sets_scale_idx');
            });
        }
        if (!$this->indexExists('attempt_answer_sets', 'attempt_answer_sets_hash_idx')) {
            Schema::table('attempt_answer_sets', function (Blueprint $table) {
                $table->index(['answers_hash'], 'attempt_answer_sets_hash_idx');
            });
        }
        if (!$this->indexExists('attempt_answer_sets', 'attempt_answer_sets_submitted_idx')) {
            Schema::table('attempt_answer_sets', function (Blueprint $table) {
                $table->index(['submitted_at'], 'attempt_answer_sets_submitted_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_answer_sets');
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
