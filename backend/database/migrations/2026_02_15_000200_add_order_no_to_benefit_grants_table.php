<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'benefit_grants';

    private const ORG_ORDER_STATUS_INDEX = 'benefit_grants_org_order_status_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'order_no')) {
                $table->string('order_no', 64)->nullable();
            }
        });

        if (
            Schema::hasColumn(self::TABLE, 'org_id')
            && Schema::hasColumn(self::TABLE, 'order_no')
            && Schema::hasColumn(self::TABLE, 'status')
            && ! SchemaIndex::indexExists(self::TABLE, self::ORG_ORDER_STATUS_INDEX)
        ) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['org_id', 'order_no', 'status'], self::ORG_ORDER_STATUS_INDEX);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
