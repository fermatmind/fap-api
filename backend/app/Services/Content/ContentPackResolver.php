<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\File;
use RuntimeException;

class ContentPackResolver
{
    public function __construct(
        private readonly string $packsRoot,
    ) {}

    public static function make(): self
    {
        $root = config('content.packs_root');
        if (!is_string($root) || trim($root) === '') {
            throw new RuntimeException("Missing config: content.packs_root");
        }
        return new self($root);
    }

    /**
     * 按 scale/region/locale/version 找 pack（manifest.json 必须存在）
     */
    public function resolve(string $scaleCode, string $region, string $locale, ?string $version = null): ContentPack
    {
        $version = $version ?: (config("content.default_versions.$scaleCode") ?? null);
        if (!$version) {
            throw new RuntimeException("No default content_package_version configured for scale=$scaleCode");
        }

        $basePath = $this->packsRoot
            . DIRECTORY_SEPARATOR . $scaleCode
            . DIRECTORY_SEPARATOR . $region
            . DIRECTORY_SEPARATOR . $locale
            . DIRECTORY_SEPARATOR . $version;

        $manifestPath = $basePath . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!File::exists($manifestPath)) {
            throw new RuntimeException("manifest.json not found: $manifestPath");
        }

        $manifest = json_decode(File::get($manifestPath), true);
        if (!is_array($manifest)) {
            throw new RuntimeException("manifest.json invalid json: $manifestPath");
        }

        // ✅ 核心：contract 校验（便宜但硬）
        $this->assertManifestContract($manifest, $manifestPath);

        $packId = $manifest['pack_id'] ?? null;
        if (!$packId) {
            // 理论上 assertManifestContract 已经保证 pack_id 存在
            $packId = $this->makePackId($scaleCode, $region, $locale, $version);
        }

        return new ContentPack(
            packId: $packId,
            scaleCode: $manifest['scale_code'] ?? $scaleCode,
            region: $manifest['region'] ?? $region,
            locale: $manifest['locale'] ?? $locale,
            version: $manifest['content_package_version'] ?? $version,
            basePath: $basePath,
            manifest: $manifest,
        );
    }

    /**
     * 递归解析 fallback 链（最多 5 层，防循环）
     * 返回：[$primaryPack, ...$fallbackPacks]
     */
    public function resolveWithFallbackChain(string $scaleCode, string $region, string $locale, ?string $version = null, int $maxDepth = 5): array
    {
        $primary = $this->resolve($scaleCode, $region, $locale, $version);

        // ✅ 用 getter（你现在 ContentPack 已经有 packId()）
        $seen = [$primary->packId() => true];
        $chain = [$primary];

        $this->expandFallback($primary, $chain, $seen, $maxDepth);

        return $chain;
    }

    private function expandFallback(ContentPack $pack, array &$chain, array &$seen, int $depthLeft): void
    {
        if ($depthLeft <= 0) return;

        foreach ($pack->fallbackPackIds() as $fallbackPackId) {
            if (isset($seen[$fallbackPackId])) continue;
            $seen[$fallbackPackId] = true;

            $parsed = $this->parsePackIdToPath($fallbackPackId);
            if (!$parsed) continue;

            [$scale, $region, $locale, $version] = $parsed;

            try {
                $fb = $this->resolve($scale, $region, $locale, $version);
                $chain[] = $fb;
                $this->expandFallback($fb, $chain, $seen, $depthLeft - 1);
            } catch (\Throwable $e) {
                // 找不到 fallback pack 就跳过，不阻塞主流程
                continue;
            }
        }
    }

    /**
     * manifest contract 最小校验（运行时校验：强约束 + 低成本）
     */
    private function assertManifestContract(array $manifest, string $manifestPath): void
    {
        $errors = [];

        $schema = $manifest['schema_version'] ?? null;
        if ($schema !== 'pack-manifest@v1') {
            $errors[] = "schema_version must be 'pack-manifest@v1', got: " . var_export($schema, true);
        }

        $required = ['scale_code', 'region', 'locale', 'content_package_version', 'pack_id', 'assets'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $manifest)) {
                $errors[] = "Missing required field: {$k}";
            }
        }

        if (isset($manifest['fallback'])) {
            if (!is_array($manifest['fallback'])) {
                $errors[] = "fallback must be array(list of pack_id strings)";
            } else {
                foreach ($manifest['fallback'] as $i => $v) {
                    if (!is_string($v) || trim($v) === '') {
                        $errors[] = "fallback[{$i}] must be non-empty string";
                    }
                }
            }
        }

        $assets = $manifest['assets'] ?? null;
        if (!is_array($assets)) {
            $errors[] = "assets must be object(map) of key => [paths...]";
        } else {
            $baseDir = dirname($manifestPath);

            foreach ($assets as $assetKey => $paths) {
                if (!is_array($paths)) {
                    $errors[] = "assets.{$assetKey} must be array(list) or object(map)";
                    continue;
                }

                // overrides 支持对象结构：{order:[], unified:"x.json", highlights_legacy:"y.json"}
                if ($assetKey === 'overrides' && $this->isAssocArray($paths)) {
                    if (isset($paths['order']) && !is_array($paths['order'])) {
                        $errors[] = "assets.overrides.order must be array(list)";
                    }

                    foreach ($paths as $k => $v) {
                        if ($k === 'order') continue;

                        $list = is_array($v) ? $v : [$v];
                        foreach ($list as $i => $rel) {
                            if (!is_string($rel) || trim($rel) === '') {
                                $errors[] = "assets.overrides.{$k}[{$i}] must be non-empty string path";
                                continue;
                            }
                            $abs = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                            if (!File::exists($abs) || !File::isFile($abs)) {
                                $errors[] = "assets.overrides.{$k}[{$i}] file not found: {$abs}";
                            }
                        }
                    }
                    continue;
                }

                // 普通 assets：list of paths
                foreach ($paths as $i => $rel) {
                    if (!is_string($rel) || trim($rel) === '') {
                        $errors[] = "assets.{$assetKey}[{$i}] must be non-empty string path";
                        continue;
                    }

                    $abs = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;

                    // 目录 marker：以 / 结尾
                    if (str_ends_with($rel, '/') || str_ends_with($rel, DIRECTORY_SEPARATOR)) {
                        if (!File::isDirectory($abs)) {
                            $errors[] = "assets.{$assetKey}[{$i}] dir not found: {$abs}";
                        }
                        continue;
                    }

                    if (!File::isFile($abs)) {
                        $errors[] = "assets.{$assetKey}[{$i}] file not found: {$abs}";
                    }
                }
            }
        }

        if (!empty($errors)) {
            throw new RuntimeException("manifest contract invalid: {$manifestPath}\n- " . implode("\n- ", $errors));
        }
    }

    private function makePackId(string $scale, string $region, string $locale, string $version): string
    {
        $regionSlug = strtolower(str_replace('_', '-', $region));
        return "{$scale}.{$regionSlug}.{$locale}.{$version}";
    }

    /**
     * pack_id -> 路径 (scale/region/locale/version)
     */
    private function parsePackIdToPath(string $packId): ?array
    {
        $parts = explode('.', $packId);
        if (count($parts) < 4) return null;

        $scale = $parts[0];
        $regionSlug = $parts[1];
        $locale = $parts[2];
        $version = implode('.', array_slice($parts, 3));

        $region = strtoupper(str_replace('-', '_', $regionSlug));
        return [$scale, $region, $locale, $version];
    }

    private function isAssocArray(array $a): bool
    {
        if ($a === []) return false;
        return array_keys($a) !== range(0, count($a) - 1);
    }
}
