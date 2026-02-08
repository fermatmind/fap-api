<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'migration_index_audits';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('migration_name', 191)->nullable();
                $table->string('table_name', 128);
                $table->string('index_name', 128);
                $table->string('action', 64);
                $table->string('phase', 32)->nullable();
                $table->string('driver', 32);
                $table->string('status', 32)->default('logged');
                $table->string('reason', 191)->nullable();
                $table->text('meta_json')->nullable();
                $table->timestamp('recorded_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, 'migration_name')) {
                $table->string('migration_name', 191)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'table_name')) {
                $table->string('table_name', 128);
            }
            if (!Schema::hasColumn(self::TABLE, 'index_name')) {
                $table->string('index_name', 128);
            }
            if (!Schema::hasColumn(self::TABLE, 'action')) {
                $table->string('action', 64);
            }
            if (!Schema::hasColumn(self::TABLE, 'phase')) {
                $table->string('phase', 32)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'driver')) {
                $table->string('driver', 32)->default('');
            }
            if (!Schema::hasColumn(self::TABLE, 'status')) {
                $table->string('status', 32)->default('logged');
            }
            if (!Schema::hasColumn(self::TABLE, 'reason')) {
                $table->string('reason', 191)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'meta_json')) {
                $table->text('meta_json')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'recorded_at')) {
                $table->timestamp('recorded_at')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        $this->ensureIndex(['migration_name'], 'migration_index_audits_migration_idx');
        $this->ensureIndex(['action', 'phase'], 'migration_index_audits_action_phase_idx');
        $this->ensureIndex(['recorded_at'], 'migration_index_audits_recorded_at_idx');
        $this->ensureIndex(['table_name', 'index_name'], 'migration_index_audits_table_index_idx');
    }

    public function down(): void
    {
        // Safety: Down is a no-op to prevent accidental data loss.
        // Schema::drop(self::TABLE);
    }

    /**
     * @param list<string> $columns
     */
    private function ensureIndex(array $columns, string $indexName): void
    {
        if (SchemaIndex::indexExists(self::TABLE, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn(self::TABLE, $column)) {
                return;
            }
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
