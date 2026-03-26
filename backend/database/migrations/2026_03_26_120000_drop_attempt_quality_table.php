<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attempt_quality')) {
            Schema::drop('attempt_quality');
        }
    }

    public function down(): void
    {
        // forward-only retirement migration: rollback intentionally disabled.
    }
};
