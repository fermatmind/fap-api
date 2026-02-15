<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'payment_reconcile_snapshots';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('org_id')->default(0);
                $table->date('snapshot_date');
                $table->unsignedInteger('paid_orders_count')->default(0);
                $table->unsignedInteger('paid_without_benefit_count')->default(0);
                $table->unsignedInteger('benefit_without_report_count')->default(0);
                $table->unsignedInteger('webhook_replay_count')->default(0);
                $table->json('meta_json')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }

        $this->ensureIndex(self::TABLE, ['org_id', 'snapshot_date'], 'payment_reconcile_org_day_idx');
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
