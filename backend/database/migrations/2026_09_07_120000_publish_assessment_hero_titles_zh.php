<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Retain migration history across releases. Assessment hero titles are now frontend-owned.
    }

    public function down(): void
    {
        // No schema or content changes.
    }
};
