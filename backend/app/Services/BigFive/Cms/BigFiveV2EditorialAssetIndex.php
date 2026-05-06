<?php

declare(strict_types=1);

namespace App\Services\BigFive\Cms;

use App\Models\BigFiveV2EditorialAssetIndexEntry;
use Illuminate\Support\Facades\File;

final class BigFiveV2EditorialAssetIndex
{
    private const ROOT = 'content_assets/big5/result_page_v2';

    /**
     * @return list<BigFiveV2EditorialAssetIndexEntry>
     */
    public function entries(): array
    {
        $root = $this->rootPath();
        if (! File::isDirectory($root)) {
            return [];
        }

        $snapshotLinks = $this->releaseSnapshotLinks();
        $entries = [];

        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $relativePath = $this->relativePath($file->getPathname());
            $document = $this->readJson($file->getPathname());
            $entries[] = new BigFiveV2EditorialAssetIndexEntry(
                assetKey: $this->assetKey($relativePath, $document),
                assetType: $this->assetType($relativePath, $document),
                relativePath: $relativePath,
                package: $this->stringValue($document['package'] ?? $document['package_key'] ?? ''),
                version: $this->stringValue($document['version'] ?? $document['snapshot_version'] ?? ''),
                mode: $this->stringValue($document['mode'] ?? ''),
                runtimeUse: $this->stringValue($document['runtime_use'] ?? 'unknown'),
                productionUseAllowed: (bool) ($document['production_use_allowed'] ?? false),
                readyForProduction: (bool) ($document['ready_for_production'] ?? false),
                productionRolloutEnabled: (bool) ($document['production_rollout_enabled'] ?? false),
                immutable: (bool) ($document['immutable'] ?? false),
                sha256: (string) hash_file('sha256', $file->getPathname()),
                linkedReleaseSnapshotIds: $snapshotLinks[$relativePath] ?? [],
            );
        }

        usort(
            $entries,
            static fn (BigFiveV2EditorialAssetIndexEntry $left, BigFiveV2EditorialAssetIndexEntry $right): int => $left->relativePath <=> $right->relativePath
        );

        return $entries;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $entries = $this->entries();

        return [
            'root' => self::ROOT,
            'asset_count' => count($entries),
            'read_only' => true,
            'runtime_mutation_allowed' => false,
            'publish_action_allowed' => false,
            'release_linked_asset_count' => count(array_filter(
                $entries,
                static fn (BigFiveV2EditorialAssetIndexEntry $entry): bool => $entry->linkedReleaseSnapshotIds !== []
            )),
            'asset_types' => array_values(array_unique(array_map(
                static fn (BigFiveV2EditorialAssetIndexEntry $entry): string => $entry->assetType,
                $entries
            ))),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function rows(): array
    {
        return array_map(
            static fn (BigFiveV2EditorialAssetIndexEntry $entry): array => $entry->toArray(),
            $this->entries()
        );
    }

    private function rootPath(): string
    {
        return base_path(self::ROOT);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function relativePath(string $absolutePath): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolutePath, $base)
            ? substr($absolutePath, strlen($base))
            : $absolutePath;

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assetKey(string $relativePath, array $document): string
    {
        $candidate = $document['package_key'] ?? $document['package'] ?? $document['snapshot_id'] ?? '';
        $candidate = $this->stringValue($candidate);

        return $candidate !== '' ? $candidate : pathinfo($relativePath, PATHINFO_FILENAME);
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assetType(string $relativePath, array $document): string
    {
        $mode = $this->stringValue($document['mode'] ?? '');
        if ($mode !== '') {
            return $mode;
        }

        if (str_contains($relativePath, '/governance/')) {
            return 'governance_asset';
        }

        if (str_contains($relativePath, '/qa/')) {
            return 'qa_asset';
        }

        if (str_contains($relativePath, '/releases/')) {
            return 'release_asset';
        }

        return 'content_asset';
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return array<string,list<string>>
     */
    private function releaseSnapshotLinks(): array
    {
        $links = [];
        $releaseRoot = $this->rootPath().DIRECTORY_SEPARATOR.'releases';
        if (! File::isDirectory($releaseRoot)) {
            return $links;
        }

        foreach (File::allFiles($releaseRoot) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $snapshot = $this->readJson($file->getPathname());
            $snapshotId = $this->stringValue($snapshot['snapshot_id'] ?? '');
            if ($snapshotId === '') {
                continue;
            }

            foreach ((array) ($snapshot['content_version_refs'] ?? []) as $ref) {
                if (! is_array($ref)) {
                    continue;
                }

                $path = $this->normalizeRefPath($this->stringValue($ref['path'] ?? ''));
                if ($path === '') {
                    continue;
                }

                $links[$path] ??= [];
                if (! in_array($snapshotId, $links[$path], true)) {
                    $links[$path][] = $snapshotId;
                }
            }
        }

        return $links;
    }

    private function normalizeRefPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'backend/')) {
            return substr($path, strlen('backend/'));
        }

        return $path;
    }
}
