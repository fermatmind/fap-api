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
        if (!Schema::hasTable('integrations')) {
            Schema::create('integrations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('provider', 64);
                $table->string('external_user_id', 128)->nullable();
                $table->string('status', 32)->default('pending');
                $table->json('scopes_json')->nullable();
                $table->string('consent_version', 64)->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('ingest_key_hash', 64)->nullable();
                $table->string('webhook_last_event_id', 128)->nullable();
                $table->unsignedBigInteger('webhook_last_timestamp')->nullable();
                $table->timestamp('webhook_last_received_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('integrations', function (Blueprint $table): void {
            if (!Schema::hasColumn('integrations', 'ingest_key_hash')) {
                $table->string('ingest_key_hash', 64)->nullable()->after('status');
            }
            if (!Schema::hasColumn('integrations', 'scopes_json')) {
                $table->json('scopes_json')->nullable()->after('ingest_key_hash');
            }
            if (!Schema::hasColumn('integrations', 'connected_at')) {
                $table->timestamp('connected_at')->nullable()->after('consent_version');
            }
            if (!Schema::hasColumn('integrations', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->after('connected_at');
            }
            if (!Schema::hasColumn('integrations', 'webhook_last_event_id')) {
                $table->string('webhook_last_event_id', 128)->nullable()->after('revoked_at');
            }
            if (!Schema::hasColumn('integrations', 'webhook_last_timestamp')) {
                $table->unsignedBigInteger('webhook_last_timestamp')->nullable()->after('webhook_last_event_id');
            }
            if (!Schema::hasColumn('integrations', 'webhook_last_received_at')) {
                $table->timestamp('webhook_last_received_at')->nullable()->after('webhook_last_timestamp');
            }
            if (!Schema::hasColumn('integrations', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('integrations', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn('integrations', 'ingest_key')) {
            $hasId = Schema::hasColumn('integrations', 'id');
            $hasProvider = Schema::hasColumn('integrations', 'provider');
            $hasUserId = Schema::hasColumn('integrations', 'user_id');

            $query = DB::table('integrations')->select('ingest_key', 'ingest_key_hash');
            if ($hasId) {
                $query->addSelect('id');
            }
            if ($hasProvider) {
                $query->addSelect('provider');
            }
            if ($hasUserId) {
                $query->addSelect('user_id');
            }

            $rows = $query->get();
            foreach ($rows as $row) {
                $ingestKey = trim((string) ($row->ingest_key ?? ''));
                if ($ingestKey === '') {
                    continue;
                }
                $hash = hash('sha256', $ingestKey);
                $current = trim((string) ($row->ingest_key_hash ?? ''));
                if ($current === $hash) {
                    continue;
                }
                $update = DB::table('integrations');
                if ($hasId && is_numeric($row->id ?? null)) {
                    $update->where('id', (int) $row->id);
                } elseif ($hasProvider && isset($row->provider) && $hasUserId && is_numeric($row->user_id ?? null)) {
                    $update
                        ->where('provider', (string) $row->provider)
                        ->where('user_id', (int) $row->user_id);
                } elseif ($hasProvider && isset($row->provider)) {
                    $update->where('provider', (string) $row->provider);
                } else {
                    $update->where('ingest_key', $ingestKey);
                }

                $update->update([
                    'ingest_key_hash' => $hash,
                ]);
            }
        }

        if (!$this->indexExists('integrations', 'integrations_ingest_key_hash_unique')) {
            Schema::table('integrations', function (Blueprint $table): void {
                $table->unique(['ingest_key_hash'], 'integrations_ingest_key_hash_unique');
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
