<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'payment_events';

    private const ORG_PROVIDER_EVENT_INDEX = 'payment_events_org_provider_event_idx';

    private const ORG_ORDER_INDEX = 'payment_events_org_order_no_idx';

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

        if (
            Schema::hasColumn(self::TABLE, 'org_id')
            && Schema::hasColumn(self::TABLE, 'provider')
            && Schema::hasColumn(self::TABLE, 'provider_event_id')
            && ! SchemaIndex::indexExists(self::TABLE, self::ORG_PROVIDER_EVENT_INDEX)
        ) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['org_id', 'provider', 'provider_event_id'], self::ORG_PROVIDER_EVENT_INDEX);
            });
        }

        if (
            Schema::hasColumn(self::TABLE, 'org_id')
            && Schema::hasColumn(self::TABLE, 'order_no')
            && ! SchemaIndex::indexExists(self::TABLE, self::ORG_ORDER_INDEX)
        ) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['org_id', 'order_no'], self::ORG_ORDER_INDEX);
            });
        }
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
