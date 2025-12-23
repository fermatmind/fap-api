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
        return new self(config('content.packs_root'));
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

        $packId = $manifest['pack_id'] ?? null;
        if (!$packId) {
            // 兜底：没有 pack_id 就按规则拼一个
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

        $seen = [$primary->packId => true];
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

            // 解析 pack_id -> 路径
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

    private function makePackId(string $scale, string $region, string $locale, string $version): string
    {
        $regionSlug = strtolower(str_replace('_', '-', $region));
        return "{$scale}.{$regionSlug}.{$locale}.{$version}";
    }

    /**
     * 你现在的 fallback 里用了 pack_id，比如：
     * MBTI.cn-mainland.zh-CN.v0.2.1
     * MBTI.global.en.default
     *
     * 这里先实现最小解析规则：
     * - scale = 第 1 段
     * - regionSlug = 第 2 段 (cn-mainland/global)
     * - locale = 第 3 段 (zh-CN/en)
     * - version = 剩余拼回去
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
}