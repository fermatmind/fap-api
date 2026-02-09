<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Replaced by 2026_02_08_000001_add_provider_composite_unique_to_payment_events.php.
        // Keep this migration for history consistency, but make execution a no-op
        // to avoid duplicate index creation and migration non-determinism.
        return;
    }

    public function down(): void
    {
        // Intentionally no-op: rollback behavior is owned by 2026_02_08_000001 migration.
        return;
    }
};
