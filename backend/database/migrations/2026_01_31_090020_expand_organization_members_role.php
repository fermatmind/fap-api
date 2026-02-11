<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data backfill moved to app/Jobs/Ops/BackfillRoleJob.php
    }

    public function down(): void
    {
        // no-op (data backfill only)
    }
};
