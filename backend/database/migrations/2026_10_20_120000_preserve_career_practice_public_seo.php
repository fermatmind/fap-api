<?php

declare(strict_types=1);

use App\Services\Cms\CareerInterestPracticeRelease;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('articles') && Schema::hasTable('career_guides')) {
            app(CareerInterestPracticeRelease::class)->publish();
        }
    }

    public function down(): void
    {
        // Preserve native revision history; previous readers support the unchanged body and metadata.
    }
};
