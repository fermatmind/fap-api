<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'organizations';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, 'status')) {
                $table->string('status', 32)->default('active')->after('name');
            }

            if (!Schema::hasColumn(self::TABLE, 'domain')) {
                $table->string('domain', 191)->nullable()->after('status');
            }

            if (!Schema::hasColumn(self::TABLE, 'timezone')) {
                $table->string('timezone', 64)->default('UTC')->after('domain');
            }

            if (!Schema::hasColumn(self::TABLE, 'locale')) {
                $table->string('locale', 16)->default('en-US')->after('timezone');
            }
        });

        $this->ensureIndex('organizations', ['status', 'updated_at'], 'organizations_status_updated_at_idx');
        $this->ensureIndex('organizations', ['domain'], 'organizations_domain_idx');
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
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
