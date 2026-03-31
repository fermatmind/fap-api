<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const ALLOWED_SLOT_IDS = [
        'hero-illustration',
        'traits-illustration',
        'traits-summary-illustration',
        'career-illustration',
        'growth-illustration',
        'relationships-illustration',
        'final-offer-illustration',
    ];

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

                    $normalized = $this->normalizeAssetSlots($raw);

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

    /**
     * @param  array<int, mixed>  $assetSlots
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssetSlots(array $assetSlots): array
    {
        $normalized = [];

        foreach ($assetSlots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $normalized[] = $this->normalizeAssetSlot($slot);
        }

        return $this->sortAssetSlotsBySchemaOrder($normalized);
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function normalizeAssetSlot(array $slot): array
    {
        $slotId = $this->normalizeSlotId($slot['slot_id'] ?? $slot['slotId'] ?? null);
        $label = $this->normalizeNullableText($slot['label'] ?? null) ?? '';
        $aspectRatio = $this->normalizeNullableText($slot['aspect_ratio'] ?? $slot['aspectRatio'] ?? null) ?? '';
        $status = strtolower(trim((string) ($slot['status'] ?? '')));
        $assetRef = $this->normalizeAssetRef($slot['asset_ref'] ?? $slot['assetRef'] ?? null);
        $alt = $this->normalizeNullableText($slot['alt'] ?? null);
        $meta = is_array($slot['meta'] ?? null) ? $slot['meta'] : null;

        return [
            'slot_id' => $slotId,
            'label' => $label,
            'aspect_ratio' => $aspectRatio,
            'status' => $status,
            'asset_ref' => $assetRef,
            'alt' => $alt,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $assetRef
     * @return array<string, string|null>|null
     */
    private function normalizeAssetRef(mixed $assetRef): ?array
    {
        if (! is_array($assetRef)) {
            return null;
        }

        $provider = $this->normalizeNullableText($assetRef['provider'] ?? null);
        $path = $this->normalizeNullableText($assetRef['path'] ?? null);
        $url = $this->normalizeNullableText($assetRef['url'] ?? null);
        $version = $this->normalizeNullableText($assetRef['version'] ?? null);
        $checksum = $this->normalizeNullableText($assetRef['checksum'] ?? null);

        if ($provider === null && $path === null && $url === null && $version === null && $checksum === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'path' => $path,
            'url' => $url,
            'version' => $version,
            'checksum' => $checksum,
        ];
    }

    private function normalizeSlotId(mixed $slotId): string
    {
        $candidate = strtolower(trim((string) $slotId));

        return match ($candidate) {
            'hero.cover' => 'hero-illustration',
            'chapter.career.banner' => 'career-illustration',
            'traits-summary-asset' => 'traits-summary-illustration',
            'final-offer-asset' => 'final-offer-illustration',
            default => $candidate,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $assetSlots
     * @return array<int, array<string, mixed>>
     */
    private function sortAssetSlotsBySchemaOrder(array $assetSlots): array
    {
        $indexMap = [];
        foreach (self::ALLOWED_SLOT_IDS as $index => $slotId) {
            $indexMap[$slotId] = $index;
        }

        usort($assetSlots, static function (array $left, array $right) use ($indexMap): int {
            $leftSlotId = strtolower(trim((string) ($left['slot_id'] ?? '')));
            $rightSlotId = strtolower(trim((string) ($right['slot_id'] ?? '')));

            $leftIndex = $indexMap[$leftSlotId] ?? PHP_INT_MAX;
            $rightIndex = $indexMap[$rightSlotId] ?? PHP_INT_MAX;

            if ($leftIndex === $rightIndex) {
                return $leftSlotId <=> $rightSlotId;
            }

            return $leftIndex <=> $rightIndex;
        });

        return array_values($assetSlots);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
};
