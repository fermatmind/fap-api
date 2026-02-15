<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureIndex('orders', ['order_no', 'updated_at'], 'orders_order_no_updated_at_idx');
        $this->ensureIndex('orders', ['target_attempt_id', 'updated_at'], 'orders_attempt_updated_at_idx');
        $this->ensureIndex('attempts', ['id'], 'attempts_id_idx_for_global_search');
        $this->ensureIndex('shares', ['id'], 'shares_id_idx_for_global_search');
        $this->ensureIndex('users', ['email'], 'users_email_idx_for_global_search');
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
