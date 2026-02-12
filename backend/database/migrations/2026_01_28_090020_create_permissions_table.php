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
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name', 128)->unique();
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });
            return;
        }

        Schema::table('permissions', function (Blueprint $table): void {
            if (!Schema::hasColumn('permissions', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('permissions', 'name')) {
                $table->string('name', 128)->nullable();
            }
            if (!Schema::hasColumn('permissions', 'description')) {
                $table->string('description', 255)->nullable();
            }
            if (!Schema::hasColumn('permissions', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('permissions', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn('permissions', 'name')) {
            $this->ensureUniqueIndex('permissions', ['name'], 'uniq_permissions_name', ['permissions_name_unique']);
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }

    private function ensureUniqueIndex(string $tableName, array $columns, string $indexName, array $alternateNames = []): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $knownNames = array_values(array_unique(array_merge([$indexName], $alternateNames)));

        foreach ($knownNames as $knownName) {
            if (SchemaIndex::indexExists($tableName, $knownName)) {
                SchemaIndex::logIndexAction('create_unique_skip_exists', $tableName, $knownName, $driver, ['phase' => 'up']);
                return;
            }
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
};
