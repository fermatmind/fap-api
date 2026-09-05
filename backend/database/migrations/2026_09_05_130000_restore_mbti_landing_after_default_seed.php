<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The earlier release's default seeder reset this content after migration.
        // Reuse its exact baseline check; the seeder now preserves published content.
        (require __DIR__.'/2026_09_05_120000_publish_mbti_landing_zh_content.php')->up();
    }

    public function down(): void
    {
        // Preserve published CMS content on application rollback.
    }
};
