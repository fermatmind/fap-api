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
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name', 64)->unique();
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });
            return;
        }

        Schema::table('roles', function (Blueprint $table): void {
            if (!Schema::hasColumn('roles', 'id')) {
                $table->bigIncrements('id');
            }
            if (!Schema::hasColumn('roles', 'name')) {
                $table->string('name', 64)->nullable();
            }
            if (!Schema::hasColumn('roles', 'description')) {
                $table->string('description', 255)->nullable();
            }
            if (!Schema::hasColumn('roles', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('roles', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn('roles', 'name')) {
            $this->ensureUniqueIndex('roles', ['name'], 'uniq_roles_name', ['roles_name_unique']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
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
