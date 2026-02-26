<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('failed_jobs') || ! Schema::hasColumn('failed_jobs', 'uuid')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `failed_jobs` MODIFY `uuid` VARCHAR(255) NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE failed_jobs ALTER COLUMN uuid DROP NOT NULL');

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite does not support ALTER COLUMN to drop NOT NULL safely in-place.
            return;
        }

        // Unknown drivers are intentionally no-op to avoid destructive table rebuilds.
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
