<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Models\CareerJobDisplayAsset;
use Generator;

final class CareerCurrentAuthorityCompatibilityReader
{
    public const BATCH_SIZE = 50;

    private const ROW_FIELDS = [
        'surface_version',
        'asset_type',
        'asset_role',
        'status',
        'component_order_json',
        'page_payload_json',
        'seo_payload_json',
        'sources_json',
        'structured_data_json',
        'implementation_contract_json',
        'metadata_json',
    ];

    public function __construct(private readonly CareerCurrentAuthorityPackage $package) {}

    /**
     * @param  list<string>  $slugs
     * @return Generator<int,list<string>>
     */
    public function batches(array $slugs): Generator
    {
        if ($slugs === [] || array_values(array_unique($slugs)) !== $slugs) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_COMPATIBILITY_BATCH_INVALID');
        }
        foreach (array_chunk($slugs, self::BATCH_SIZE) as $chunk) {
            yield $chunk;
        }
    }

    /** @param list<string> $expectedSlugs */
    public function assertInventory(array $expectedSlugs): void
    {
        $assets = CareerJobDisplayAsset::query()
            ->select(['canonical_slug', 'asset_type', 'asset_role', 'status'])
            ->orderBy('canonical_slug')
            ->get();
        $actualSlugs = $assets
            ->map(static fn (CareerJobDisplayAsset $asset): string => strtolower(trim((string) $asset->canonical_slug)))
            ->all();

        if ($actualSlugs !== $expectedSlugs
            || $assets->contains(static fn (CareerJobDisplayAsset $asset): bool => $asset->asset_type !== CareerCurrentAuthorityPackage::ASSET_TYPE
                || $asset->asset_role !== CareerCurrentAuthorityPackage::ASSET_ROLE
                || $asset->status !== CareerCurrentAuthorityPackage::READY_STATUS)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_COMPATIBILITY_ROWS_INCOMPLETE');
        }
    }

    /**
     * @param  array{entries:array<string,array<string,array<string,mixed>>>}  $index
     * @param  list<string>  $slugs
     * @return array<string,array<string,mixed>>
     */
    public function rowsForSlugs(array $index, array $slugs): array
    {
        if ($slugs === [] || count($slugs) > self::BATCH_SIZE || array_values(array_unique($slugs)) !== $slugs) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_COMPATIBILITY_BATCH_INVALID');
        }

        $assets = CareerJobDisplayAsset::query()
            ->runtimeColumns()
            ->whereIn('canonical_slug', $slugs)
            ->orderBy('canonical_slug')
            ->get();
        if ($assets->count() !== count($slugs)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_COMPATIBILITY_ROW_MISSING');
        }

        $rows = [];
        foreach ($assets as $asset) {
            $slug = strtolower(trim((string) $asset->canonical_slug));
            if (isset($rows[$slug]) || ! in_array($slug, $slugs, true)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_COMPATIBILITY_ROW_UNEXPECTED');
            }
            $row = ['canonical_slug' => $slug];
            foreach (self::ROW_FIELDS as $field) {
                $row[$field] = $asset->getAttribute($field);
            }
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $entry = $index['entries'][$slug][$locale] ?? null;
                if (! is_array($entry)
                    || ! hash_equals((string) $entry['legacy_row_sha256'], CareerCurrentAuthorityPackage::hashValue($row))) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_COMPATIBILITY_ROW_HASH_MISMATCH');
                }
                $surface = $this->package->publicProjection($row, $locale);
                unset($surface['content_v3']);
                if (! hash_equals(
                    (string) $entry['legacy_projection_sha256'],
                    CareerCurrentAuthorityPackage::hashValue($surface),
                )) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_COMPATIBILITY_PROJECTION_HASH_MISMATCH');
                }
            }
            $rows[$slug] = $row;
        }

        if (array_keys($rows) !== $slugs) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_COMPATIBILITY_SLUG_SET_MISMATCH');
        }

        return $rows;
    }
}
