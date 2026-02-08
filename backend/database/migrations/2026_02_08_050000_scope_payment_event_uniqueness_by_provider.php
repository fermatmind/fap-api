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
    private const NEW_UNIQUE = 'payment_events_provider_provider_event_unique';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }
        if (!Schema::hasColumn(self::TABLE, 'provider') || !Schema::hasColumn(self::TABLE, 'provider_event_id')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if (SchemaIndex::indexExists(self::TABLE, self::OLD_UNIQUE)) {
            try {
                Schema::table(self::TABLE, function (Blueprint $table) {
                    $table->dropUnique(self::OLD_UNIQUE);
                });
                SchemaIndex::logIndexAction('drop_unique', self::TABLE, self::OLD_UNIQUE, $driver, ['phase' => 'up']);
            } catch (\Throwable $e) {
                if (SchemaIndex::isMissingIndexException($e, self::OLD_UNIQUE)) {
                    SchemaIndex::logIndexAction('drop_unique_skip_missing', self::TABLE, self::OLD_UNIQUE, $driver, ['phase' => 'up']);
                } else {
                    throw $e;
                }
            }
        }

        if (!SchemaIndex::indexExists(self::TABLE, self::NEW_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['provider', 'provider_event_id'], self::NEW_UNIQUE);
            });
            SchemaIndex::logIndexAction('create_unique', self::TABLE, self::NEW_UNIQUE, $driver, ['phase' => 'up']);
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

        $driver = DB::connection()->getDriverName();

        if (SchemaIndex::indexExists(self::TABLE, self::NEW_UNIQUE)) {
            try {
                Schema::table(self::TABLE, function (Blueprint $table) {
                    $table->dropUnique(self::NEW_UNIQUE);
                });
                SchemaIndex::logIndexAction('drop_unique', self::TABLE, self::NEW_UNIQUE, $driver, ['phase' => 'down']);
            } catch (\Throwable $e) {
                if (SchemaIndex::isMissingIndexException($e, self::NEW_UNIQUE)) {
                    SchemaIndex::logIndexAction('drop_unique_skip_missing', self::TABLE, self::NEW_UNIQUE, $driver, ['phase' => 'down']);
                } else {
                    throw $e;
                }
            }
        }

        if (!SchemaIndex::indexExists(self::TABLE, self::OLD_UNIQUE)) {
            $crossProviderDuplicates = DB::table(self::TABLE)
                ->select('provider_event_id')
                ->whereNotNull('provider_event_id')
                ->groupBy('provider_event_id')
                ->havingRaw('count(*) > 1')
                ->limit(1)
                ->exists();

            if (!$crossProviderDuplicates) {
                Schema::table(self::TABLE, function (Blueprint $table) {
                    $table->unique('provider_event_id', self::OLD_UNIQUE);
                });
                SchemaIndex::logIndexAction('create_unique', self::TABLE, self::OLD_UNIQUE, $driver, ['phase' => 'down']);
            }
        }
    }
};
