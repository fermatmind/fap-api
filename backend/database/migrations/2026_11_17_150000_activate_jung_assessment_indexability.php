<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Indexability is published by JungAssessmentIndexabilitySeeder through scales:seed-default.
    }

    public function down(): void
    {
        // Published registry identities remain available during application rollback.
    }
};
