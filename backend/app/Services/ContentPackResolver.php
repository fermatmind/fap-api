<?php
// file: backend/app/Services/ContentPackResolver.php

namespace App\Services;

use App\DTO\ResolvedPack;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ContentPackResolver
{
    private array $indexByKey = [];   // key => ['pack_id'=>..., 'manifest_path'=>..., 'base_dir'=>..., 'manifest'=>...]
    private array $byPackId   = [];   // pack_id => same payload
    private bool  $built      = false;

    public function resolve(string $scale, string $region, string $locale, string $version): ResolvedPack
    {
        $this->buildIndexOnce();

        $scale  = trim($scale);
        $region = $this->normRegion($region);
        $locale = $this->normLocale($locale);
        $version = trim($version);

        $trace = [
            'input' => compact('scale','region','locale','version'),
            'candidates' => [],
            'picked' => null,
            'fallback_chain' => [],
        ];

        $candidates = $this->buildCandidates($scale, $region, $locale, $version);
        $trace['candidates'] = $candidates;

        $picked = null;
        foreach ($candidates as $cand) {
            $key = $this->makeKey($cand['scale'], $cand['region'], $cand['locale'], $cand['version']);
            if (isset($this->indexByKey[$key])) {
                $picked = $this->indexByKey[$key];
                $trace['picked'] = ['reason' => $cand['reason'], 'key' => $key, 'pack_id' => $picked['pack_id']];
                break;
            }
        }

        // 最终兜底：default_pack_id（最稳定）
        if ($picked === null) {
            $defaultPackId = (string)config('content_packs.default_pack_id', '');
            if ($defaultPackId !== '' && isset($this->byPackId[$defaultPackId])) {
                $picked = $this->byPackId[$defaultPackId];
                $trace['picked'] = ['reason' => 'default_pack_id', 'pack_id' => $picked['pack_id']];
            }
        }

        if ($picked === null) {
            throw new \RuntimeException("ContentPackResolver: cannot resolve pack for scale={$scale} region={$region} locale={$locale} version={$version} (no default_pack_id matched)");
        }

        // build fallback chain (manifest.fallback = [pack_id,...])
        $fallbackChain = $this->buildFallbackChain($picked['manifest'], $trace);

        // loaders: read asset with fallback
        $loaders = $this->makeLoaders($picked['base_dir'], $fallbackChain);

        return new ResolvedPack(
            packId: $picked['pack_id'],
            baseDir: $picked['base_dir'],
            manifest: $picked['manifest'],
            fallbackChain: $fallbackChain,
            trace: $trace,
            loaders: $loaders
        );
    }

    // -------------------------
    // Index building
    // -------------------------
    private function buildIndexOnce(): void
    {
        if ($this->built) return;

        $root = (string)config('content_packs.root');
        if (!is_dir($root)) {
            throw new \RuntimeException("ContentPackResolver: content pack root not found: {$root}");
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (strtolower($file->getFilename()) !== 'manifest.json') continue;

            $manifestPath = $file->getPathname();
            $manifest = $this->readJson($manifestPath);
            if (!is_array($manifest)) continue;

            $packId  = (string)($manifest['pack_id'] ?? '');
            $scale   = (string)($manifest['scale_code'] ?? '');
            $region  = $this->normRegion((string)($manifest['region'] ?? ''));
            $locale  = $this->normLocale((string)($manifest['locale'] ?? ''));
            $version = (string)($manifest['content_package_version'] ?? '');

            if ($packId === '' || $scale === '' || $region === '' || $locale === '' || $version === '') {
                continue;
            }

            $baseDir = dirname($manifestPath);

            $payload = [
                'pack_id' => $packId,
                'manifest_path' => $manifestPath,
                'base_dir' => $baseDir,
                'manifest' => $manifest,
                'scale' => $scale,
                'region' => $region,
                'locale' => $locale,
                'version' => $version,
            ];

            $key = $this->makeKey($scale, $region, $locale, $version);
            $this->indexByKey[$key] = $payload;
            $this->byPackId[$packId] = $payload;
        }

        $this->built = true;
    }

    private function makeKey(string $scale, string $region, string $locale, string $version): string
    {
        return "{$scale}|{$region}|{$locale}|{$version}";
    }

    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    private function normRegion(string $r): string
    {
        $r = strtoupper(trim($r));
        $r = str_replace('-', '_', $r);
        return $r;
    }

    private function normLocale(string $l): string
    {
        $l = trim($l);
        if ($l === '') return '';
        // normalize like zh-CN / en-US
        $l = str_replace('_', '-', $l);
        $parts = explode('-', $l);
        if (count($parts) === 1) return strtolower($parts[0]);
        return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
    }

    private function baseLocale(string $locale): string
    {
        $locale = $this->normLocale($locale);
        $p = explode('-', $locale);
        return strtolower($p[0] ?? $locale);
    }

    private function buildCandidates(string $scale, string $region, string $locale, string $version): array
    {
        $out = [];

        // 1) exact
        $out[] = ['reason'=>'exact', 'scale'=>$scale, 'region'=>$region, 'locale'=>$locale, 'version'=>$version];

        // 2) locale fallback
        if ((bool)config('content_packs.locale_fallback', true)) {
            $bl = $this->baseLocale($locale);
            if ($bl !== $locale) {
                $out[] = ['reason'=>'locale_fallback', 'scale'=>$scale, 'region'=>$region, 'locale'=>$bl, 'version'=>$version];
            }
        }

        // 3) region fallback
        $regionFallbacks = (array)config('content_packs.region_fallbacks', []);
        $chain = $regionFallbacks[$region] ?? ($regionFallbacks['*'] ?? []);
        foreach ($chain as $fr) {
            $fr = $this->normRegion((string)$fr);
            $out[] = ['reason'=>"region_fallback:{$fr}", 'scale'=>$scale, 'region'=>$fr, 'locale'=>$locale, 'version'=>$version];

            if ((bool)config('content_packs.locale_fallback', true)) {
                $bl = $this->baseLocale($locale);
                if ($bl !== $locale) {
                    $out[] = ['reason'=>"region+locale_fallback:{$fr}", 'scale'=>$scale, 'region'=>$fr, 'locale'=>$bl, 'version'=>$version];
                }
            }
        }

        // 4) final fallback (default region/locale, same version)
        $dr = $this->normRegion((string)config('content_packs.default_region', 'GLOBAL'));
        $dl = $this->normLocale((string)config('content_packs.default_locale', 'en'));
        $out[] = ['reason'=>'final_fallback', 'scale'=>$scale, 'region'=>$dr, 'locale'=>$dl, 'version'=>$version];

        return $out;
    }

    private function buildFallbackChain(array $manifest, array &$trace): array
    {
        $fallback = $manifest['fallback'] ?? [];
        if (!is_array($fallback)) $fallback = [];

        $chain = [];
        foreach ($fallback as $i => $packId) {
            if (!is_string($packId) || trim($packId) === '') continue;
            $packId = trim($packId);
            if (!isset($this->byPackId[$packId])) continue;
            $p = $this->byPackId[$packId];
            $chain[] = [
                'pack_id' => $p['pack_id'],
                'base_dir' => $p['base_dir'],
                'manifest' => $p['manifest'],
            ];
        }

        $trace['fallback_chain'] = array_map(fn($x) => $x['pack_id'], $chain);
        return $chain;
    }

    private function makeLoaders(string $baseDir, array $fallbackChain): array
    {
        $dirs = [$baseDir];
        foreach ($fallbackChain as $f) $dirs[] = $f['base_dir'];

        $readFile = function (string $rel) use ($dirs): ?string {
            $rel = ltrim($rel, "/\\");
            foreach ($dirs as $dir) {
                $abs = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $rel;
                if (is_file($abs)) {
                    $raw = @file_get_contents($abs);
                    if ($raw !== false) return $raw;
                }
            }
            return null;
        };

        $readJson = function (string $rel) use ($readFile): ?array {
            $raw = $readFile($rel);
            if ($raw === null) return null;
            $j = json_decode($raw, true);
            return is_array($j) ? $j : null;
        };

        return [
            'readFile' => $readFile,
            'readJson' => $readJson,
        ];
    }
}