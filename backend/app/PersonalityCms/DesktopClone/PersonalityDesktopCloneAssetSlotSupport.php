<?php

declare(strict_types=1);

namespace App\PersonalityCms\DesktopClone;

final class PersonalityDesktopCloneAssetSlotSupport
{
    public const SLOT_ID_HERO_ILLUSTRATION = 'hero-illustration';

    public const SLOT_ID_TRAITS_ILLUSTRATION = 'traits-illustration';

    public const SLOT_ID_TRAITS_SUMMARY_ILLUSTRATION = 'traits-summary-illustration';

    public const SLOT_ID_CAREER_ILLUSTRATION = 'career-illustration';

    public const SLOT_ID_GROWTH_ILLUSTRATION = 'growth-illustration';

    public const SLOT_ID_RELATIONSHIPS_ILLUSTRATION = 'relationships-illustration';

    public const SLOT_ID_FINAL_OFFER_ILLUSTRATION = 'final-offer-illustration';

    public const STATUS_PLACEHOLDER = 'placeholder';

    public const STATUS_READY = 'ready';

    public const STATUS_DISABLED = 'disabled';

    public const ASSET_PROVIDER_OSS = 'oss';

    public const ASSET_PROVIDER_CDN = 'cdn';

    public const ASSET_PROVIDER_INTERNAL = 'internal';

    public const ASSET_PROVIDER_PLACEHOLDER = 'placeholder';

    /**
     * @return list<string>
     */
    public static function allowedSlotIds(): array
    {
        return [
            self::SLOT_ID_HERO_ILLUSTRATION,
            self::SLOT_ID_TRAITS_ILLUSTRATION,
            self::SLOT_ID_TRAITS_SUMMARY_ILLUSTRATION,
            self::SLOT_ID_CAREER_ILLUSTRATION,
            self::SLOT_ID_GROWTH_ILLUSTRATION,
            self::SLOT_ID_RELATIONSHIPS_ILLUSTRATION,
            self::SLOT_ID_FINAL_OFFER_ILLUSTRATION,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_PLACEHOLDER,
            self::STATUS_READY,
            self::STATUS_DISABLED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedAssetProviders(): array
    {
        return [
            self::ASSET_PROVIDER_OSS,
            self::ASSET_PROVIDER_CDN,
            self::ASSET_PROVIDER_INTERNAL,
            self::ASSET_PROVIDER_PLACEHOLDER,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function slotIdOptions(): array
    {
        return array_combine(self::allowedSlotIds(), self::allowedSlotIds()) ?: [];
    }

    /**
     * @param  array<int, mixed>  $assetSlots
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeAssetSlots(array $assetSlots): array
    {
        $normalized = [];

        foreach ($assetSlots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $normalized[] = self::normalizeAssetSlot($slot);
        }

        return self::sortAssetSlotsBySchemaOrder($normalized);
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    public static function normalizeAssetSlot(array $slot): array
    {
        $slotId = self::normalizeSlotId($slot['slot_id'] ?? $slot['slotId'] ?? null);
        $label = self::normalizeNullableText($slot['label'] ?? null) ?? '';
        $aspectRatio = self::normalizeNullableText($slot['aspect_ratio'] ?? $slot['aspectRatio'] ?? null) ?? '';
        $status = strtolower(trim((string) ($slot['status'] ?? '')));
        $assetRef = self::normalizeAssetRef($slot['asset_ref'] ?? $slot['assetRef'] ?? null);
        $alt = self::normalizeNullableText($slot['alt'] ?? null);
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
    public static function normalizeAssetRef(mixed $assetRef): ?array
    {
        if (! is_array($assetRef)) {
            return null;
        }

        $provider = self::normalizeNullableText($assetRef['provider'] ?? null);
        $path = self::normalizeNullableText($assetRef['path'] ?? null);
        $url = self::normalizeNullableText($assetRef['url'] ?? null);
        $version = self::normalizeNullableText($assetRef['version'] ?? null);
        $checksum = self::normalizeNullableText($assetRef['checksum'] ?? null);

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

    public static function normalizeSlotId(mixed $slotId): string
    {
        $candidate = strtolower(trim((string) $slotId));

        return match ($candidate) {
            'hero.cover' => self::SLOT_ID_HERO_ILLUSTRATION,
            'chapter.career.banner' => self::SLOT_ID_CAREER_ILLUSTRATION,
            'traits-summary-asset' => self::SLOT_ID_TRAITS_SUMMARY_ILLUSTRATION,
            'final-offer-asset' => self::SLOT_ID_FINAL_OFFER_ILLUSTRATION,
            default => $candidate,
        };
    }

    public static function isAllowedSlotId(string $slotId): bool
    {
        return in_array($slotId, self::allowedSlotIds(), true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $assetSlots
     * @return array<int, array<string, mixed>>
     */
    public static function sortAssetSlotsBySchemaOrder(array $assetSlots): array
    {
        $indexMap = [];
        foreach (self::allowedSlotIds() as $index => $slotId) {
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

    private static function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
