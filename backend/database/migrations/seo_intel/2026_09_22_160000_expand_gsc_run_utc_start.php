<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'seo_intel';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_gsc_sync_runs') && ! $schema->hasColumn('seo_gsc_sync_runs', 'started_at_utc')) {
            $schema->table('seo_gsc_sync_runs', function (Blueprint $table): void {
                // DATETIME avoids both session timezone conversion and MySQL's
                // implicit first-TIMESTAMP automatic update. Never backfill guesses.
                $table->dateTime('started_at_utc', 6)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Expand-only: old releases ignore the field; retain recorded instants.
    }
};
