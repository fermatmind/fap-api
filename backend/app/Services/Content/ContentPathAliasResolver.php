<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ContentPathAliasResolver
{
    /**
     * Resolve backend content_packs root directory for a legacy pack id.
     * This is a compat alias bridge only. Current runtime canonical truth must come from
     * content_packs config / primary resolvers, not from alias selection side effects.
     * Default behavior remains legacy-first, with safe fallback if mapped path does not exist.
     */
    public function resolveBackendPackRoot(string $legacyPackId): string
    {
        $packId = strtoupper(trim($legacyPackId));
        if ($packId === '') {
            return base_path('content_packs');
        }

        $legacyRelativePath = 'content_packs/'.$packId;
        $alias = $this->findActiveAlias('backend_content_packs', $legacyRelativePath);
        if (! is_array($alias)) {
            return base_path($legacyRelativePath);
        }

        $legacyAbsolutePath = base_path(trim((string) ($alias['old_path'] ?? $legacyRelativePath), '/'));
        $mappedAbsolutePath = base_path(trim((string) ($alias['new_path'] ?? $legacyRelativePath), '/'));
        $mode = strtolower(trim((string) config('scale_identity.content_path_mode', 'legacy')));

        return match ($mode) {
            'dual_prefer_new', 'v2' => $this->preferExisting($mappedAbsolutePath, $legacyAbsolutePath),
            'dual_prefer_old', 'legacy' => $this->preferExisting($legacyAbsolutePath, $mappedAbsolutePath),
            default => $this->preferExisting($legacyAbsolutePath, $mappedAbsolutePath),
        };
    }

    /**
     * Resolve backend source directory for publish command.
     * Modes:
     * - legacy/dual: prefer legacy path, fallback mapped path.
     * - v2: prefer mapped path, fallback legacy path.
     * This does not redefine which dir is canonical for runtime reads.
     */
    public function resolveBackendPublishSourceDir(string $legacyPackId, string $version): string
    {
        return (string) ($this->resolveBackendPublishSourceContext($legacyPackId, $version)['selected_path'] ?? base_path('content_packs'));
    }

    /**
     * @return array{
     *   mode:string,
     *   selected_path:string,
     *   selected_source:string,
     *   legacy_path:string,
     *   mapped_path:?string,
     *   alias_matched:bool,
     *   fallback_used:bool
     * }
     */
    public function resolveBackendPublishSourceContext(string $legacyPackId, string $version): array
    {
        $mode = $this->normalizePublishMode(config('scale_identity.content_publish_mode', 'legacy'));
        $packId = strtoupper(trim($legacyPackId));
        $packVersion = trim($version);
        if ($packId === '' || $packVersion === '') {
            $defaultPath = base_path('content_packs');

            return [
                'mode' => $mode,
                'selected_path' => $defaultPath,
                'selected_source' => 'legacy',
                'legacy_path' => $defaultPath,
                'mapped_path' => null,
                'alias_matched' => false,
                'fallback_used' => false,
            ];
        }

        $legacyRelativeRoot = 'content_packs/'.$packId;
        $legacyAbsoluteRoot = base_path($legacyRelativeRoot);
        $legacyVersionPath = $legacyAbsoluteRoot.DIRECTORY_SEPARATOR.$packVersion;

        $alias = $this->findActiveAlias('backend_content_packs', $legacyRelativeRoot);
        if (! is_array($alias)) {
            return [
                'mode' => $mode,
                'selected_path' => $legacyVersionPath,
                'selected_source' => 'legacy',
                'legacy_path' => $legacyVersionPath,
                'mapped_path' => null,
                'alias_matched' => false,
                'fallback_used' => false,
            ];
        }

        $mappedAbsoluteRoot = base_path(trim((string) ($alias['new_path'] ?? $legacyRelativeRoot), '/'));
        $mappedVersionPath = $mappedAbsoluteRoot.DIRECTORY_SEPARATOR.$packVersion;
        if ($mode === 'v2') {
            $selected = $this->selectExistingPath(
                $mappedVersionPath,
                'mapped',
                $legacyVersionPath,
                'legacy'
            );
        } else {
            $selected = $this->selectExistingPath(
                $legacyVersionPath,
                'legacy',
                $mappedVersionPath,
                'mapped'
            );
        }

        return [
            'mode' => $mode,
            'selected_path' => (string) $selected['selected_path'],
            'selected_source' => (string) $selected['selected_source'],
            'legacy_path' => $legacyVersionPath,
            'mapped_path' => $mappedVersionPath,
            'alias_matched' => true,
            'fallback_used' => (bool) $selected['fallback_used'],
        ];
    }

    /**
     * @return array{old_path:string,new_path:string}|null
     */
    private function findActiveAlias(string $scope, string $oldPath): ?array
    {
        if (! Schema::hasTable('content_path_aliases')) {
            return null;
        }

        try {
            $row = DB::table('content_path_aliases')
                ->select(['old_path', 'new_path'])
                ->where('scope', $scope)
                ->where('old_path', $oldPath)
                ->where('is_active', true)
                ->first();

            if (! $row) {
                return null;
            }

            $legacy = trim((string) ($row->old_path ?? ''));
            $mapped = trim((string) ($row->new_path ?? ''));
            if ($legacy === '' || $mapped === '') {
                return null;
            }

            return [
                'old_path' => $legacy,
                'new_path' => $mapped,
            ];
        } catch (\Throwable) {
            // Keep non-blocking behavior during phased rollout.
            return null;
        }
    }

    private function preferExisting(string $primaryPath, string $fallbackPath): string
    {
        if (is_dir($primaryPath)) {
            return $primaryPath;
        }

        if (is_dir($fallbackPath)) {
            return $fallbackPath;
        }

        return $primaryPath;
    }

    private function normalizePublishMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, ['legacy', 'dual', 'v2'], true) ? $mode : 'legacy';
    }

    /**
     * @return array{selected_path:string,selected_source:string,fallback_used:bool}
     */
    private function selectExistingPath(
        string $primaryPath,
        string $primarySource,
        string $fallbackPath,
        string $fallbackSource
    ): array {
        if (is_dir($primaryPath)) {
            return [
                'selected_path' => $primaryPath,
                'selected_source' => $primarySource,
                'fallback_used' => false,
            ];
        }

        if (is_dir($fallbackPath)) {
            return [
                'selected_path' => $fallbackPath,
                'selected_source' => $fallbackSource,
                'fallback_used' => true,
            ];
        }

        return [
            'selected_path' => $primaryPath,
            'selected_source' => $primarySource,
            'fallback_used' => true,
        ];
    }
}
