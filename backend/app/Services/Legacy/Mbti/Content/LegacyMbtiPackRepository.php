<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Content;

use Illuminate\Support\Facades\Log;

class LegacyMbtiPackRepository
{
    public function resolveContentDir(?string $packId, ?string $dirVersion, ?string $region, ?string $locale): string
    {
        $region = is_string($region) && trim($region) !== ''
            ? trim($region)
            : (string) config('content_packs.default_region', 'CN_MAINLAND');

        $locale = is_string($locale) && trim($locale) !== ''
            ? trim($locale)
            : (string) config('content_packs.default_locale', 'zh-CN');

        $dirVersion = is_string($dirVersion) ? trim($dirVersion) : '';
        if ($dirVersion === '' && is_string($packId) && trim($packId) !== '') {
            $dirVersion = $this->packIdToDirVersion(trim($packId));
        }

        if ($dirVersion === '') {
            $dirVersion = (string) config(
                'content_packs.default_dir_version',
                config('content.default_versions.default', 'MBTI-CN-v0.2.1-TEST')
            );
        }

        return $this->normalizeContentPackageDir("default/{$region}/{$locale}/{$dirVersion}", $region, $locale);
    }

    public function loadJsonFromPack(string $contentDir, string $relPath): ?array
    {
        $contentDir = $this->normalizeContentPackageDir($contentDir);
        $relPath = trim(str_replace('\\', '/', $relPath), "/ \t\n\r\0\x0B");
        if ($relPath === '') {
            return null;
        }

        $contentDirTrimmed = trim($contentDir, '/\\');

        $cfgRoot = config('fap.content_packages_dir', null);
        $cfgRoot = is_string($cfgRoot) && $cfgRoot !== '' ? rtrim($cfgRoot, '/') : null;
        $packsRoot = rtrim((string) config('content_packs.root', ''), '/');
        $packsRoot = $packsRoot !== '' ? "{$packsRoot}/{$contentDirTrimmed}" : null;

        $roots = array_values(array_filter([
            $packsRoot,
            storage_path("app/private/content_packages/{$contentDirTrimmed}"),
            storage_path("app/content_packages/{$contentDirTrimmed}"),
            base_path("../content_packages/{$contentDirTrimmed}"),
            base_path("content_packages/{$contentDirTrimmed}"),
            $cfgRoot ? "{$cfgRoot}/{$contentDirTrimmed}" : null,
        ], fn ($p) => is_string($p) && $p !== ''));

        foreach ($roots as $root) {
            $direct = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            if (is_file($direct)) {
                $raw = @file_get_contents($direct);
                if ($raw === false || trim($raw) === '') {
                    return null;
                }

                $json = json_decode($raw, true);

                return is_array($json) ? $json : null;
            }
        }

        $basename = basename($relPath);
        $found = $this->findPackageFile($contentDir, $basename);
        if (! $found || ! is_file($found)) {
            return null;
        }

        $raw = @file_get_contents($found);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);

        return is_array($json) ? $json : null;
    }

    public function loadQuestionsDoc(string $contentDir): ?array
    {
        return $this->loadJsonFromPack($contentDir, 'questions.json');
    }

    public function loadScaleMetaDoc(string $contentDir): ?array
    {
        return $this->loadJsonFromPack($contentDir, 'scale_meta.json')
            ?? $this->loadJsonFromPack($contentDir, 'scoring_spec.json');
    }

    public function loadManifestDoc(string $contentDir): ?array
    {
        return $this->loadJsonFromPack($contentDir, 'manifest.json');
    }

    public function loadPackJson($resolved, string $filename): ?array
    {
        if (! $resolved || ! is_array($resolved->loaders ?? null)) {
            return null;
        }

        $loader = $resolved->loaders['readJson'] ?? null;
        if (! is_callable($loader)) {
            return null;
        }

        $data = $loader($filename);

        return is_array($data) ? $data : null;
    }

    public function loadPackManifest(string $region, string $locale, string $dirVersion): ?array
    {
        $contentDir = $this->resolveContentDir(null, $dirVersion, $region, $locale);

        return $this->loadManifestDoc($contentDir);
    }

    public function mergeNonNullRecursive(array $base, array $override): array
    {
        foreach ($override as $k => $v) {
            if ($v === null) {
                continue;
            }

            if (is_array($v) && is_array($base[$k] ?? null)) {
                $base[$k] = $this->mergeNonNullRecursive($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }

    public function normalizeContentPackageDir(string $pkgOrVersion, ?string $region = null, ?string $locale = null): string
    {
        $pkgOrVersion = trim(str_replace('\\', '/', $pkgOrVersion), "/ \t\n\r\0\x0B");

        if (preg_match('#^default/[^/]+/[^/]+/.+#', $pkgOrVersion)) {
            return $pkgOrVersion;
        }

        if (preg_match('#^[A-Z_]+/[a-z]{2}(?:-[A-Z]{2}|-[A-Za-z0-9]+)?/.+#', $pkgOrVersion)) {
            return 'default/'.$pkgOrVersion;
        }

        if (substr_count($pkgOrVersion, '.') >= 3) {
            $parts = explode('.', $pkgOrVersion);
            if (count($parts) >= 4) {
                $region = strtoupper((string) ($parts[1] ?? 'GLOBAL'));
                $locale = (string) ($parts[2] ?? 'en');
                $version = implode('.', array_slice($parts, 3));

                return "default/{$region}/{$locale}/{$version}";
            }
        }

        $region = is_string($region) && trim($region) !== ''
            ? trim($region)
            : (string) config('content_packs.default_region', 'CN_MAINLAND');
        $locale = is_string($locale) && trim($locale) !== ''
            ? trim($locale)
            : (string) config('content_packs.default_locale', 'zh-CN');

        $region = trim(str_replace('\\', '/', $region), "/ \t\n\r\0\x0B");
        $locale = trim(str_replace('\\', '/', $locale), "/ \t\n\r\0\x0B");

        return "default/{$region}/{$locale}/{$pkgOrVersion}";
    }

    private function packIdToDirVersion(string $packId): string
    {
        if (substr_count($packId, '.') >= 3) {
            $parts = explode('.', $packId);

            return implode('.', array_slice($parts, 3));
        }

        return $packId;
    }

    private function findPackageFile(string $pkg, string $filename, int $maxDepth = 3): ?string
    {
        $pkg = $this->normalizeContentPackageDir($pkg);
        $pkg = trim($pkg, '/\\');
        $filename = trim($filename, '/\\');

        $cfgRoot = config('fap.content_packages_dir', null);
        $cfgRoot = is_string($cfgRoot) && $cfgRoot !== '' ? rtrim($cfgRoot, '/') : null;
        $packsRoot = rtrim((string) config('content_packs.root', ''), '/');
        $packsRoot = $packsRoot !== '' ? "{$packsRoot}/{$pkg}" : null;

        $roots = array_values(array_filter([
            $packsRoot,
            storage_path("app/private/content_packages/{$pkg}"),
            storage_path("app/content_packages/{$pkg}"),
            base_path("../content_packages/{$pkg}"),
            base_path("content_packages/{$pkg}"),
            $cfgRoot ? "{$cfgRoot}/{$pkg}" : null,
        ], fn ($p) => is_string($p) && $p !== '' && is_dir($p)));

        if (empty($roots)) {
            return null;
        }

        foreach ($roots as $root) {
            $direct = $root.DIRECTORY_SEPARATOR.$filename;
            if (is_file($direct)) {
                return $direct;
            }

            try {
                $dirIter = new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                );
                $iter = new \RecursiveIteratorIterator($dirIter, \RecursiveIteratorIterator::SELF_FIRST);
                $iter->setMaxDepth($maxDepth);

                foreach ($iter as $fileInfo) {
                    if (! $fileInfo->isFile()) {
                        continue;
                    }
                    if ($fileInfo->getFilename() !== $filename) {
                        continue;
                    }

                    $relDir = str_replace($root, '', $fileInfo->getPath());
                    $relDir = trim(str_replace('\\', '/', $relDir), '/');
                    $depth = ($relDir === '') ? 0 : substr_count($relDir, '/') + 1;

                    if ($depth <= $maxDepth) {
                        return $fileInfo->getPathname();
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('LEGACY_MBTI_PACKAGE_FILE_PROBE_DEGRADED', [
                    'path' => $root,
                    'exception' => $e,
                ]);

                continue;
            }
        }

        return null;
    }
}
