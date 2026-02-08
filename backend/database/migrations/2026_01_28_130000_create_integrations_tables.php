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

        if (!Schema::hasTable('integrations')) {
            Schema::create('integrations', function (Blueprint $table) use ($isSqlite) {
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('provider', 64);
                $table->string('external_user_id', 128)->nullable();
                $table->string('status', 32)->default('pending');
                if ($isSqlite) {
                    $table->text('scopes_json')->nullable();
                } else {
                    $table->json('scopes_json')->nullable();
                }
                $table->string('consent_version', 64)->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('integrations', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('integrations', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
                if (!Schema::hasColumn('integrations', 'provider')) {
                    $table->string('provider', 64);
                }
                if (!Schema::hasColumn('integrations', 'external_user_id')) {
                    $table->string('external_user_id', 128)->nullable();
                }
                if (!Schema::hasColumn('integrations', 'status')) {
                    $table->string('status', 32)->default('pending');
                }
                if (!Schema::hasColumn('integrations', 'scopes_json')) {
                    if ($isSqlite) {
                        $table->text('scopes_json')->nullable();
                    } else {
                        $table->json('scopes_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('integrations', 'consent_version')) {
                    $table->string('consent_version', 64)->nullable();
                }
                if (!Schema::hasColumn('integrations', 'connected_at')) {
                    $table->timestamp('connected_at')->nullable();
                }
                if (!Schema::hasColumn('integrations', 'revoked_at')) {
                    $table->timestamp('revoked_at')->nullable();
                }
                if (!Schema::hasColumn('integrations', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('integrations', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $indexName = 'integrations_user_provider_unique';
        if (
            Schema::hasTable('integrations')
            && Schema::hasColumn('integrations', 'user_id')
            && Schema::hasColumn('integrations', 'provider')
            && !$this->indexExists('integrations', $indexName)
        ) {
            Schema::table('integrations', function (Blueprint $table) use ($indexName) {
                $table->unique(['user_id', 'provider'], $indexName);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('integrations')) {
            return;
        }

        $indexName = 'integrations_user_provider_unique';
        if ($this->indexExists('integrations', $indexName)) {
            Schema::table('integrations', function (Blueprint $table) use ($indexName) {
                $table->dropUnique($indexName);
            });
        }

        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('integrations');
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
