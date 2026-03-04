<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'attempt_submissions';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('attempt_id', 64);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_anon_id', 128)->nullable();
                $table->string('dedupe_key', 64);
                $table->string('mode', 24)->default('async');
                $table->string('state', 24)->default('pending');
                $table->string('error_code', 64)->nullable();
                $table->string('error_message', 255)->nullable();
                $table->json('request_payload_json')->nullable();
                $table->json('response_payload_json')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        } else {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::TABLE, 'id')) {
                    $table->uuid('id')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                    $table->unsignedBigInteger('org_id')->default(0);
                }
                if (! Schema::hasColumn(self::TABLE, 'attempt_id')) {
                    $table->string('attempt_id', 64);
                }
                if (! Schema::hasColumn(self::TABLE, 'actor_user_id')) {
                    $table->unsignedBigInteger('actor_user_id')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'actor_anon_id')) {
                    $table->string('actor_anon_id', 128)->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'dedupe_key')) {
                    $table->string('dedupe_key', 64);
                }
                if (! Schema::hasColumn(self::TABLE, 'mode')) {
                    $table->string('mode', 24)->default('async');
                }
                if (! Schema::hasColumn(self::TABLE, 'state')) {
                    $table->string('state', 24)->default('pending');
                }
                if (! Schema::hasColumn(self::TABLE, 'error_code')) {
                    $table->string('error_code', 64)->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'error_message')) {
                    $table->string('error_message', 255)->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'request_payload_json')) {
                    $table->json('request_payload_json')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'response_payload_json')) {
                    $table->json('response_payload_json')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'started_at')) {
                    $table->timestamp('started_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'finished_at')) {
                    $table->timestamp('finished_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if (! Schema::hasColumn(self::TABLE, 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        $this->ensureUnique(['org_id', 'dedupe_key'], 'attempt_submissions_org_dedupe_unique');
        $this->ensureIndex(['org_id', 'attempt_id', 'state'], 'attempt_submissions_org_attempt_state_idx');
        $this->ensureIndex(['org_id', 'actor_user_id'], 'attempt_submissions_org_user_idx');
        $this->ensureIndex(['org_id', 'actor_anon_id'], 'attempt_submissions_org_anon_idx');
        $this->ensureIndex(['created_at'], 'attempt_submissions_created_at_idx');
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureUnique(array $columns, string $indexName): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (SchemaIndex::indexExists(self::TABLE, $indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->unique($columns, $indexName);
        });
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function ensureIndex(array $columns, string $indexName): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (SchemaIndex::indexExists(self::TABLE, $indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
