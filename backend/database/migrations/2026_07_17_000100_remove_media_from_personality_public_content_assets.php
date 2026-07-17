<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RETIREMENT_EVIDENCE_ID = 'personality_public_content_media_retirement_2026_07_17';

    public function up(): void
    {
        if (! Schema::hasTable('personality_public_content_assets')) {
            return;
        }

        if (Schema::hasColumn('personality_public_content_assets', 'media_json')) {
            Schema::table('personality_public_content_assets', function ($table): void {
                // Bound to docs/migrations/destructive-retirements.json via self::RETIREMENT_EVIDENCE_ID.
                $table->dropColumn('media_json');
            });
        }
    }

    public function down(): void
    {
        // Forward-only: personality public content media is permanently unsupported.
    }
};
