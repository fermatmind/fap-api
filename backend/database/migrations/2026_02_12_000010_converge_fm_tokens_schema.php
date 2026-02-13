<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fm_tokens')) {
            Schema::create('fm_tokens', function (Blueprint $table): void {
                $table->string('token', 80)->primary();
                $table->string('token_hash', 64)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('anon_id', 64)->nullable();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('role', 32)->default('public');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('fm_tokens', function (Blueprint $table): void {
            if (!Schema::hasColumn('fm_tokens', 'token_hash')) {
                $table->string('token_hash', 64)->nullable()->after('token');
            }
            if (!Schema::hasColumn('fm_tokens', 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0)->after('anon_id');
            }
            if (!Schema::hasColumn('fm_tokens', 'role')) {
                $table->string('role', 32)->default('public')->after('org_id');
            }
            if (!Schema::hasColumn('fm_tokens', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('role');
            }
            if (!Schema::hasColumn('fm_tokens', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->after('expires_at');
            }
            if (!Schema::hasColumn('fm_tokens', 'meta_json')) {
                $table->json('meta_json')->nullable()->after('revoked_at');
            }
            if (!Schema::hasColumn('fm_tokens', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('meta_json');
            }
            if (!Schema::hasColumn('fm_tokens', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('fm_tokens', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (!$this->indexExists('fm_tokens', 'fm_tokens_token_hash_unique')) {
            Schema::table('fm_tokens', function (Blueprint $table): void {
                $table->unique(['token_hash'], 'fm_tokens_token_hash_unique');
            });
        }

        if (!$this->indexExists('fm_tokens', 'fm_tokens_org_id_idx')) {
            Schema::table('fm_tokens', function (Blueprint $table): void {
                $table->index(['org_id'], 'fm_tokens_org_id_idx');
            });
        }

        if (!$this->indexExists('fm_tokens', 'fm_tokens_expires_at_idx')) {
            Schema::table('fm_tokens', function (Blueprint $table): void {
                $table->index(['expires_at'], 'fm_tokens_expires_at_idx');
            });
        }

        if (!$this->indexExists('fm_tokens', 'fm_tokens_revoked_at_idx')) {
            Schema::table('fm_tokens', function (Blueprint $table): void {
                $table->index(['revoked_at'], 'fm_tokens_revoked_at_idx');
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
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
