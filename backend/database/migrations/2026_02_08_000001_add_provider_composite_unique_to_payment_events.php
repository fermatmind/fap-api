<?php

use App\Support\Database\SchemaIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'payment_events';
    private const OLD_UNIQUE = 'payment_events_provider_event_id_unique';
    private const NEW_UNIQUE = 'payment_events_provider_provider_event_id_unique';
    private const LEGACY_UNIQUE = 'payment_events_provider_provider_event_unique';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, 'provider')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('provider', 50)->default('billing');
            });
        }

        if (!Schema::hasColumn(self::TABLE, 'provider_event_id')) {
            return;
        }

        if (SchemaIndex::indexExists(self::TABLE, self::OLD_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }

        if (SchemaIndex::indexExists(self::TABLE, self::LEGACY_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }

        if (!SchemaIndex::indexExists(self::TABLE, self::NEW_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['provider', 'provider_event_id'], self::NEW_UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, 'provider_event_id')) {
            return;
        }

        if (SchemaIndex::indexExists(self::TABLE, self::NEW_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        if (!SchemaIndex::indexExists(self::TABLE, self::OLD_UNIQUE)) {
            $hasCrossProviderDuplicates = DB::table(self::TABLE)
                ->select('provider_event_id')
                ->whereNotNull('provider_event_id')
                ->groupBy('provider_event_id')
                ->havingRaw('count(*) > 1')
                ->limit(1)
                ->exists();

            if (!$hasCrossProviderDuplicates) {
                Schema::table(self::TABLE, function (Blueprint $table) {
                    $table->unique('provider_event_id', self::OLD_UNIQUE);
                });
            }
        }
    }
};
