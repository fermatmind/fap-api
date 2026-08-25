<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'career_job_display_assets';

    private const UNIQUE_INDEX = 'career_job_display_assets_canonical_slug_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $duplicate = DB::table(self::TABLE)
            ->selectRaw('LOWER(TRIM(canonical_slug)) AS normalized_slug, COUNT(*) AS aggregate')
            ->groupByRaw('LOWER(TRIM(canonical_slug))')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate !== null) {
            throw new RuntimeException('CAREER_DISPLAY_CANONICAL_SLUG_DUPLICATE');
        }

        $hasUniqueIndex = collect(Schema::getIndexes(self::TABLE))
            ->contains(static fn (array $index): bool => ($index['name'] ?? null) === self::UNIQUE_INDEX);
        if ($hasUniqueIndex) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique('canonical_slug', self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        // Expand-only compatibility boundary. Task 9 owns physical contract cleanup.
    }
};
