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
        if (!Schema::hasTable('permission_role')) {
            Schema::create('permission_role', function (Blueprint $table): void {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->unique(['permission_id', 'role_id'], 'uniq_permission_role');
                $table->index(['role_id'], 'idx_permission_role_role');
                $table->index(['permission_id'], 'idx_permission_role_permission');
            });
            return;
        }

        Schema::table('permission_role', function (Blueprint $table): void {
            if (!Schema::hasColumn('permission_role', 'permission_id')) {
                $table->unsignedBigInteger('permission_id')->nullable();
            }
            if (!Schema::hasColumn('permission_role', 'role_id')) {
                $table->unsignedBigInteger('role_id')->nullable();
            }
        });

        if (Schema::hasColumn('permission_role', 'permission_id') && Schema::hasColumn('permission_role', 'role_id')) {
            $this->ensureUniqueIndex('permission_role', ['permission_id', 'role_id'], 'uniq_permission_role');
        }
        if (Schema::hasColumn('permission_role', 'role_id')) {
            $this->ensureIndex('permission_role', ['role_id'], 'idx_permission_role_role');
        }
        if (Schema::hasColumn('permission_role', 'permission_id')) {
            $this->ensureIndex('permission_role', ['permission_id'], 'idx_permission_role_permission');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
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
