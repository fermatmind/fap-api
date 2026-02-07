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
        if (!Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table): void {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('admin_user_id');
                $table->unique(['role_id', 'admin_user_id'], 'uniq_role_user');
                $table->index(['admin_user_id'], 'idx_role_user_admin');
                $table->index(['role_id'], 'idx_role_user_role');
            });
            return;
        }

        Schema::table('role_user', function (Blueprint $table): void {
            if (!Schema::hasColumn('role_user', 'role_id')) {
                $table->unsignedBigInteger('role_id')->nullable();
            }
            if (!Schema::hasColumn('role_user', 'admin_user_id')) {
                $table->unsignedBigInteger('admin_user_id')->nullable();
            }
        });

        if (Schema::hasColumn('role_user', 'role_id') && Schema::hasColumn('role_user', 'admin_user_id')) {
            $this->ensureUniqueIndex('role_user', ['role_id', 'admin_user_id'], 'uniq_role_user');
        }
        if (Schema::hasColumn('role_user', 'admin_user_id')) {
            $this->ensureIndex('role_user', ['admin_user_id'], 'idx_role_user_admin');
        }
        if (Schema::hasColumn('role_user', 'role_id')) {
            $this->ensureIndex('role_user', ['role_id'], 'idx_role_user_role');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }

    private function ensureUniqueIndex(string $tableName, array $columns, string $indexName): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (SchemaIndex::indexExists($tableName, $indexName)) {
            SchemaIndex::logIndexAction('create_unique_skip_exists', $tableName, $indexName, $driver, ['phase' => 'up']);
            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
                $table->unique($columns, $indexName);
            });
            SchemaIndex::logIndexAction('create_unique', $tableName, $indexName, $driver, ['phase' => 'up']);
        } catch (\Throwable $e) {
            if (SchemaIndex::isDuplicateIndexException($e, $indexName)) {
                SchemaIndex::logIndexAction('create_unique_skip_duplicate', $tableName, $indexName, $driver, ['phase' => 'up']);
                return;
            }

            throw $e;
        }
    }

    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
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
