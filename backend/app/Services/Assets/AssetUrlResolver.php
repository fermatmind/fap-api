<?php

namespace App\Services\Assets;

use App\Services\Content\ContentPacksIndex;

final class AssetUrlResolver
{
    public function __construct(private ContentPacksIndex $index)
    {
    }

    public function resolve(
        string $packId,
        string $dirVersion,
        string $relativePath,
        ?string $region = null,
        ?string $assetsBaseUrlOverride = null
    ): string {
        $relativePath = trim($relativePath);
        $this->assertStrictAssetPath($relativePath);

        $baseUrl = $this->pickAssetsBaseUrl($packId, $dirVersion, $assetsBaseUrlOverride);

        return $this->joinUrl($baseUrl, $packId, $dirVersion, $relativePath);
    }

    private function pickAssetsBaseUrl(string $packId, string $dirVersion, ?string $override): string
    {
        $override = is_string($override) ? trim($override) : '';
        if ($override !== '') {
            return $override;
        }

        $versionBase = $this->readAssetsBaseUrlFromVersion($packId, $dirVersion);
        if ($versionBase !== '') {
            return $versionBase;
        }

        $appUrl = trim((string) config('app.url', ''));
        if ($appUrl === '') {
            $appUrl = trim((string) env('APP_URL', ''));
        }
        if ($appUrl === '') {
            $appUrl = 'http://localhost';
        }

        return rtrim($appUrl, '/') . '/storage/content_assets';
    }

    private function readAssetsBaseUrlFromVersion(string $packId, string $dirVersion): string
    {
        $found = $this->index->find($packId, $dirVersion);
        if (!($found['ok'] ?? false)) {
            return '';
        }

        $item = $found['item'] ?? [];
        $manifestPath = (string) ($item['manifest_path'] ?? '');
        if ($manifestPath === '') {
            return '';
        }

        $baseDir = dirname($manifestPath);
        $versionPath = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'version.json';
        if (!is_file($versionPath)) {
            return '';
        }

        $raw = @file_get_contents($versionPath);
        if ($raw === false || trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }

        $baseUrl = trim((string) ($decoded['assets_base_url'] ?? ''));
        return $baseUrl;
    }

    private function joinUrl(string $baseUrl, string ...$parts): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $clean = [];
        foreach ($parts as $p) {
            $p = trim($p);
            $p = trim($p, '/');
            if ($p !== '') $clean[] = $p;
        }

        return $baseUrl . '/' . implode('/', $clean);
    }

    private function assertStrictAssetPath(string $path): void
    {
        if ($path === '') {
            throw new \RuntimeException('asset path empty');
        }
        if (preg_match('#^https?://#i', $path)) {
            throw new \RuntimeException('absolute URL not allowed');
        }
        if (str_starts_with($path, '//')) {
            throw new \RuntimeException('protocol-relative URL not allowed');
        }
        if (str_starts_with($path, '/')) {
            throw new \RuntimeException('absolute path not allowed');
        }
        if (str_contains($path, '..')) {
            throw new \RuntimeException('path traversal not allowed');
        }
        if (!str_starts_with($path, 'assets/')) {
            throw new \RuntimeException('asset path must start with assets/');
        }
    }
}
