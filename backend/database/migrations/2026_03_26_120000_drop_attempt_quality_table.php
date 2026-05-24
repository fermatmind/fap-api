<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RETIREMENT_EVIDENCE_ID = 'attempt_quality_retirement_2026_03_26';

    public function up(): void
    {
        if (Schema::hasTable('attempt_quality')) {
            // Bound to backend/docs/migrations/destructive-retirements.json via self::RETIREMENT_EVIDENCE_ID.
            Schema::drop('attempt_quality');
        }
    }

    public function down(): void
    {
        // forward-only retirement migration: rollback intentionally disabled.
    }
};
