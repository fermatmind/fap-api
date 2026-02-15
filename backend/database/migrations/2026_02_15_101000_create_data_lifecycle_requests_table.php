<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'data_lifecycle_requests';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->string('request_type', 32);
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('requested_by_admin_user_id')->nullable();
                $table->unsignedBigInteger('approved_by_admin_user_id')->nullable();
                $table->string('subject_ref', 191)->nullable();
                $table->string('reason', 255)->nullable();
                $table->string('result', 32)->nullable();
                $table->json('payload_json')->nullable();
                $table->json('result_json')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->dateTime('executed_at')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }

        $this->ensureIndex(self::TABLE, ['org_id', 'request_type', 'status'], 'data_lifecycle_org_type_status_idx');
        $this->ensureIndex(self::TABLE, ['created_at'], 'data_lifecycle_created_at_idx');
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (SchemaIndex::indexExists($tableName, $indexName)) {
            SchemaIndex::logIndexAction('create_index_skip_exists', $tableName, $indexName, $driver, ['phase' => 'up']);

            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->index($columns, $indexName);
            });
            SchemaIndex::logIndexAction('create_index', $tableName, $indexName, $driver, ['phase' => 'up']);
        } catch (\Throwable $e) {
            if (SchemaIndex::isDuplicateIndexException($e, $indexName)) {
                SchemaIndex::logIndexAction('create_index_skip_duplicate', $tableName, $indexName, $driver, ['phase' => 'up']);

                return;
            }

            throw $e;
        }
    }
};
