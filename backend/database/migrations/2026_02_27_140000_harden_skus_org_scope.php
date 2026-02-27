<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'skus';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'org_id')) {
                $table->unsignedBigInteger('org_id')->default(0);
            }
        });

        $this->ensureIndex(
            ['org_id', 'scale_code', 'is_active'],
            'skus_org_scale_active_idx'
        );
        $this->ensureIndex(
            ['org_id', 'benefit_code'],
            'skus_org_benefit_code_idx'
        );
        $this->ensureUnique(
            ['org_id', 'sku'],
            'skus_org_sku_unique'
        );
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
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
};
