<?php

declare(strict_types=1);

use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personality_profile_variant_clone_contents')) {
            return;
        }

        DB::table('personality_profile_variant_clone_contents')
            ->select(['id', 'asset_slots_json'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $raw = json_decode((string) ($row->asset_slots_json ?? '[]'), true);
                    if (! is_array($raw)) {
                        $raw = [];
                    }

                    $normalized = PersonalityDesktopCloneAssetSlotSupport::normalizeAssetSlots($raw);

                    if ($normalized === $raw) {
                        continue;
                    }

                    DB::table('personality_profile_variant_clone_contents')
                        ->where('id', (int) $row->id)
                        ->update([
                            'asset_slots_json' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // forward-only migration: rollback disabled to prevent data loss in production.
        // Irreversible operation: schema/data rollback handled via forward fix migrations.
    }
};
