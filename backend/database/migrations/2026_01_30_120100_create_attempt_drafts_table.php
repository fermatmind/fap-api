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

        if (!Schema::hasTable('attempt_drafts')) {
            Schema::create('attempt_drafts', function (Blueprint $table) use ($isSqlite) {
                $table->uuid('attempt_id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('resume_token_hash', 64);
                $table->integer('last_seq')->default(0);
                $table->string('cursor', 255)->nullable();
                $table->integer('duration_ms')->default(0);
                if ($isSqlite) {
                    $table->text('answers_json')->nullable();
                } else {
                    $table->longText('answers_json')->nullable();
                }
                $table->integer('answered_count')->default(0);
                $table->timestamps();
                $table->timestamp('expires_at')->nullable();

                $table->index(['org_id'], 'attempt_drafts_org_idx');
                $table->index(['resume_token_hash'], 'attempt_drafts_token_idx');
                $table->index(['expires_at'], 'attempt_drafts_expires_idx');
            });

            return;
        }

        Schema::table('attempt_drafts', function (Blueprint $table) use ($isSqlite) {
            if (!Schema::hasColumn('attempt_drafts', 'attempt_id')) {
                $table->uuid('attempt_id');
            }
            if (!Schema::hasColumn('attempt_drafts', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
            if (!Schema::hasColumn('attempt_drafts', 'resume_token_hash')) {
                $table->string('resume_token_hash', 64);
            }
            if (!Schema::hasColumn('attempt_drafts', 'last_seq')) {
                $table->integer('last_seq')->default(0);
            }
            if (!Schema::hasColumn('attempt_drafts', 'cursor')) {
                $table->string('cursor', 255)->nullable();
            }
            if (!Schema::hasColumn('attempt_drafts', 'duration_ms')) {
                $table->integer('duration_ms')->default(0);
            }
            if (!Schema::hasColumn('attempt_drafts', 'answers_json')) {
                if ($isSqlite) {
                    $table->text('answers_json')->nullable();
                } else {
                    $table->longText('answers_json')->nullable();
                }
            }
            if (!Schema::hasColumn('attempt_drafts', 'answered_count')) {
                $table->integer('answered_count')->default(0);
            }
            if (!Schema::hasColumn('attempt_drafts', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('attempt_drafts', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
            if (!Schema::hasColumn('attempt_drafts', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
        });

        if (!$this->indexExists('attempt_drafts', 'attempt_drafts_org_idx')) {
            Schema::table('attempt_drafts', function (Blueprint $table) {
                $table->index(['org_id'], 'attempt_drafts_org_idx');
            });
        }
        if (!$this->indexExists('attempt_drafts', 'attempt_drafts_token_idx')) {
            Schema::table('attempt_drafts', function (Blueprint $table) {
                $table->index(['resume_token_hash'], 'attempt_drafts_token_idx');
            });
        }
        if (!$this->indexExists('attempt_drafts', 'attempt_drafts_expires_idx')) {
            Schema::table('attempt_drafts', function (Blueprint $table) {
                $table->index(['expires_at'], 'attempt_drafts_expires_idx');
            });
        }
    }

    public function down(): void
    {
        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('attempt_drafts');
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
