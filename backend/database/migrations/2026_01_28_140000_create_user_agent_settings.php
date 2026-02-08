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

        if (!Schema::hasTable('user_agent_settings')) {
            Schema::create('user_agent_settings', function (Blueprint $table) use ($isSqlite) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->boolean('enabled')->default(false);
                if ($isSqlite) {
                    $table->text('quiet_hours_json')->nullable();
                    $table->text('thresholds_json')->nullable();
                    $table->text('channels_json')->nullable();
                } else {
                    $table->json('quiet_hours_json')->nullable();
                    $table->json('thresholds_json')->nullable();
                    $table->json('channels_json')->nullable();
                }
                $table->unsignedInteger('max_messages_per_day')->default(2);
                $table->unsignedInteger('cooldown_minutes')->default(240);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('user_agent_settings', function (Blueprint $table) use ($isSqlite) {
                if (!Schema::hasColumn('user_agent_settings', 'id')) {
                    $table->bigIncrements('id');
                }
                if (!Schema::hasColumn('user_agent_settings', 'user_id')) {
                    $table->unsignedBigInteger('user_id');
                }
                if (!Schema::hasColumn('user_agent_settings', 'enabled')) {
                    $table->boolean('enabled')->default(false);
                }
                if (!Schema::hasColumn('user_agent_settings', 'quiet_hours_json')) {
                    if ($isSqlite) {
                        $table->text('quiet_hours_json')->nullable();
                    } else {
                        $table->json('quiet_hours_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('user_agent_settings', 'thresholds_json')) {
                    if ($isSqlite) {
                        $table->text('thresholds_json')->nullable();
                    } else {
                        $table->json('thresholds_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('user_agent_settings', 'channels_json')) {
                    if ($isSqlite) {
                        $table->text('channels_json')->nullable();
                    } else {
                        $table->json('channels_json')->nullable();
                    }
                }
                if (!Schema::hasColumn('user_agent_settings', 'max_messages_per_day')) {
                    $table->unsignedInteger('max_messages_per_day')->default(2);
                }
                if (!Schema::hasColumn('user_agent_settings', 'cooldown_minutes')) {
                    $table->unsignedInteger('cooldown_minutes')->default(240);
                }
                if (!Schema::hasColumn('user_agent_settings', 'last_sent_at')) {
                    $table->timestamp('last_sent_at')->nullable();
                }
                if (!Schema::hasColumn('user_agent_settings', 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (!Schema::hasColumn('user_agent_settings', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $uniqueIndex = 'user_agent_settings_user_id_uq';
        if (
            Schema::hasTable('user_agent_settings')
            && Schema::hasColumn('user_agent_settings', 'user_id')
            && !$this->indexExists('user_agent_settings', $uniqueIndex)
        ) {
            Schema::table('user_agent_settings', function (Blueprint $table) use ($uniqueIndex) {
                $table->unique(['user_id'], $uniqueIndex);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_agent_settings')) {
            return;
        }

        $uniqueIndex = 'user_agent_settings_user_id_uq';
        if ($this->indexExists('user_agent_settings', $uniqueIndex)) {
            Schema::table('user_agent_settings', function (Blueprint $table) use ($uniqueIndex) {
                $table->dropUnique($uniqueIndex);
            });
        }

        // Prevent accidental data loss. This table might have existed before.
        // Schema::dropIfExists('user_agent_settings');
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
