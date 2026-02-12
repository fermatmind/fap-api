<?php

declare(strict_types=1);

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'payment_events';
    private const INDEX = 'idx_pay_status_time';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)
            || !Schema::hasColumn(self::TABLE, 'provider')
            || !Schema::hasColumn(self::TABLE, 'status')
            || !Schema::hasColumn(self::TABLE, 'received_at')
            || SchemaIndex::indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['provider', 'status', 'received_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
