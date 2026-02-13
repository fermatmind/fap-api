<?php

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Services\Report\HighlightBuilder;
use App\Services\Content\ContentStore;
use App\Services\Content\ContentPackResolver;
use App\Services\ContentPackage;

class HighlightBuilderBuildFromStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 旧 resolver / AppServiceProvider 默认值用的是 content_packs.*（不设就会 GLOBAL/en）
config()->set('content_packs.default_region', 'CN_MAINLAND');
config()->set('content_packs.default_locale', 'zh-CN');

// forget 旧 resolver（你现在只 forget 了 App\Services\Content\ContentPackResolver）
$this->app->forgetInstance(\App\Services\ContentPackResolver::class);

        // 固定住 locale + pack 选择（避免跑去 default/GLOBAL/en）
        $scale   = 'default';
        $region  = 'CN_MAINLAND';
        $locale  = 'zh-CN';
        $version = 'MBTI-CN-v0.2.1-TEST';

        // ✅ 关键：很多 legacy 代码/Service 仍然从 env('MBTI_CONTENT_PACKAGE') 取 pack
        // 必须用“路径风格”：scale/region/locale/version
        $pkgPath = "{$scale}/{$region}/{$locale}/{$version}";
        putenv("MBTI_CONTENT_PACKAGE={$pkgPath}");
        $_ENV['MBTI_CONTENT_PACKAGE'] = $pkgPath;
        $_SERVER['MBTI_CONTENT_PACKAGE'] = $pkgPath;

        // 1) 强行指定 content_packages 根目录（CI/别人机器不靠 .env）
        config()->set('content.packs_root', base_path('../content_packages'));

        // 2) Resolver 在 version 为空时读 content.default_versions.<scaleCode>
        config()->set("content.default_versions.{$scale}", $version);
        // 额外兜底：有些地方可能用 default_versions.default
        config()->set('content.default_versions.default', $version);

        // 3) 兼容两套 key：旧代码可能读 fap.content.*，也可能读 fap.content_package_version
        config()->set('fap.content.scale', $scale);
        config()->set('fap.content.region', $region);
        config()->set('fap.content.locale', $locale);
        config()->set('fap.content.content_package_version', $version);
        config()->set('fap.content_package_version', $version); // MbtiController / some call-sites

        // 4) 再兜底：如果有地方直接读 content.scale/content.region/content.locale
        config()->set('content.scale', $scale);
        config()->set('content.region', $region);
        config()->set('content.locale', $locale);

        // 5) 强制 locale（避免 app.locale 默认 en 影响）
        config()->set('app.locale', $locale);
        app()->setLocale($locale);

        // 6) 如果这些是 singleton：必须 forget，否则仍用旧实例 / 旧 env
        $this->app->forgetInstance(ContentPackage::class);
        $this->app->forgetInstance(ContentStore::class);
        $this->app->forgetInstance(ContentPackResolver::class);
    }

    #[DataProvider('reportsProvider')]
    public function test_build_from_store_uses_real_pack_and_outputs_valid_items(array $report, array $expect): void
    {
        /** @var ContentStore $store */
        $store = app(ContentStore::class);

        // 覆盖 resolver + manifest + assets：能读到 highlights doc
        $doc = $store->loadHighlights();
        $this->assertIsArray($doc);
        $this->assertSame('fap.report.highlights.v1', $doc['schema'] ?? null, 'highlights doc schema mismatch');
        $this->assertTrue(isset($doc['templates']) && is_array($doc['templates']), 'highlights doc must have templates[]');

        /** @var HighlightBuilder $hb */
        $hb = app(HighlightBuilder::class);

        $min = (int) ($expect['min'] ?? 3);
        $max = (int) ($expect['max'] ?? 4);

        $out = $hb->buildFromStore($report, $store, $min, $max, []);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('items', $out);
        $this->assertIsArray($out['items']);

        // 数量必须在 [min,max]
        $this->assertGreaterThanOrEqual($min, count($out['items']));
        $this->assertLessThanOrEqual($max, count($out['items']));

        // 必须包含 blindspot/action（你 pipeline 的合同要求）
        $kinds = array_values(array_filter(array_map(fn ($x) => $x['kind'] ?? null, $out['items'])));
        $this->assertTrue(in_array('blindspot', $kinds, true), 'must contain blindspot');
        $this->assertTrue(in_array('action', $kinds, true), 'must contain action');

        // blindspot id 基础规则
        $blindspots = array_values(array_filter(
            $out['items'],
            fn ($x) => is_array($x) && (($x['kind'] ?? '') === 'blindspot')
        ));
        $this->assertNotEmpty($blindspots, 'must contain blindspot item');

        $bid = (string) ($blindspots[0]['id'] ?? '');
        $this->assertNotSame('', $bid);

        // 1) 禁止双前缀
        $this->assertStringNotContainsString('hl.blindspot.hl.', $bid, "bad blindspot id found: {$bid}");
        // 2) 禁止出现 borderline
        $this->assertStringNotContainsString('borderline', $bid, "blindspot id must not contain borderline: {$bid}");

        // 只有在“AT borderline 场景”才强约束 AT_ 前缀（避免把逻辑写死）
        if (!empty($expect['require_blindspot_at_prefix'])) {
            $this->assertMatchesRegularExpression('/^hl\\.blindspot\\.AT_/', $bid, "blindspot id must match format hl.blindspot.AT_* : {$bid}");
        }

        // strength 校验（按场景开关）
        $strengths = array_values(array_filter(
            $out['items'],
            fn ($x) => is_array($x) && (($x['kind'] ?? '') === 'strength')
        ));

        if (!empty($expect['require_strength'])) {
            $this->assertNotEmpty($strengths, 'must contain at least 1 strength');
        }

        if (!empty($expect['require_strength_from_template'])) {
            $this->assertNotEmpty($strengths, 'must contain at least 1 strength');

            $strengthIds = array_values(array_filter(array_map(fn ($x) => $x['id'] ?? null, $strengths)));
            $this->assertNotEmpty($strengthIds);

            foreach ($strengthIds as $sid) {
                $this->assertIsString($sid);
                $this->assertStringNotContainsString('hl.strength.generated_', $sid, "strength should not be fallback-generated: {$sid}");
            }
        }
    }

    public static function reportsProvider(): array
    {
        return [
            'AT borderline (existing)' => [
                [
                    'profile' => ['type_code' => 'ESTJ-A'],
                    'scores_pct' => ['EI' => 64, 'SN' => 55, 'TF' => 71, 'JP' => 62, 'AT' => 52],
                    'axis_states' => ['EI' => 'clear', 'SN' => 'borderline', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'borderline'],
                ],
                [
                    'min' => 3,
                    'max' => 4,
                    'require_blindspot_at_prefix' => true,
                    'require_strength' => true,
                    'require_strength_from_template' => true,
                ],
            ],

            'All clear with large deltas' => [
                [
                    'profile' => ['type_code' => 'INTJ-A'],
                    'scores_pct' => ['EI' => 80, 'SN' => 20, 'TF' => 75, 'JP' => 78, 'AT' => 70],
                    'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
                ],
                [
                    'min' => 3,
                    'max' => 4,
                    'require_blindspot_at_prefix' => false,
                    'require_strength' => true,
                    'require_strength_from_template' => true,
                ],
            ],

            'All borderline (optional safety)' => [
                [
                    'profile' => ['type_code' => 'INFP-T'],
                    'scores_pct' => ['EI' => 52, 'SN' => 49, 'TF' => 51, 'JP' => 48, 'AT' => 51],
                    'axis_states' => ['EI' => 'borderline', 'SN' => 'borderline', 'TF' => 'borderline', 'JP' => 'borderline', 'AT' => 'borderline'],
                ],
                [
                    'min' => 3,
                    'max' => 4,
                    'require_blindspot_at_prefix' => false,
                    'require_strength' => false,
                    'require_strength_from_template' => false,
                ],
            ],
        ];
    }

    public function test_store_must_throw_when_asset_missing_and_fallbacks_forbidden(): void
    {
        $origRoot = base_path('../content_packages');
        $tmpRoot  = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'fap_content_packages_' . uniqid('', true);

        $scale   = (string) config('fap.content.scale', 'default');
        $region  = (string) config('fap.content.region', 'CN_MAINLAND');
        $locale  = (string) config('fap.content.locale', 'zh-CN');
        $version = (string) config('fap.content.content_package_version', 'MBTI-CN-v0.2.1-TEST');

        $srcPackDir = $origRoot . DIRECTORY_SEPARATOR . $scale . DIRECTORY_SEPARATOR . $region . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $version;
        $dstPackDir = $tmpRoot  . DIRECTORY_SEPARATOR . $scale . DIRECTORY_SEPARATOR . $region . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $version;

        if (!is_file($srcPackDir . DIRECTORY_SEPARATOR . 'manifest.json')) {
            throw new \RuntimeException('source manifest.json not found: ' . $srcPackDir . DIRECTORY_SEPARATOR . 'manifest.json');
        }

        $this->copyDir($srcPackDir, $dstPackDir);
        $this->breakOneAssetPathInManifest($dstPackDir . DIRECTORY_SEPARATOR . 'manifest.json');

        config()->set('content.packs_root', $tmpRoot);
        config()->set('content_packs.root', $tmpRoot);
        config()->set('content_packs.default_dir_version', $version);
        $this->app->forgetInstance(ContentStore::class);
        $this->app->forgetInstance(ContentPackResolver::class);

        putenv('FAP_FORBID_STORE_ASSET_SCAN=1');
        putenv('FAP_FORBID_LEGACY_CTX_LOADER=1');
        config()->set('fap.runtime.FAP_FORBID_STORE_ASSET_SCAN', '1');
        config()->set('fap.runtime.FAP_FORBID_LEGACY_CTX_LOADER', '1');

        try {
            $this->expectException(\RuntimeException::class);

            /** @var ContentStore $store */
            $store = app(ContentStore::class);
            $store->loadHighlights();
        } finally {
            putenv('FAP_FORBID_STORE_ASSET_SCAN');
            putenv('FAP_FORBID_LEGACY_CTX_LOADER');
            config()->set('fap.runtime.FAP_FORBID_STORE_ASSET_SCAN', null);
            config()->set('fap.runtime.FAP_FORBID_LEGACY_CTX_LOADER', null);
            $this->rmDir($tmpRoot);
        }
    }

    // helpers ...

    private function copyDir(string $src, string $dst): void
    {
        $src = rtrim($src, DIRECTORY_SEPARATOR);
        $dst = rtrim($dst, DIRECTORY_SEPARATOR);

        if (!is_dir($src)) {
            throw new \RuntimeException("copyDir: src not found: {$src}");
        }
        if (!is_dir($dst) && !mkdir($dst, 0777, true) && !is_dir($dst)) {
            throw new \RuntimeException("copyDir: cannot mkdir: {$dst}");
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $item) {
            // Build a stable relative path without relying on getSubPathname()/getSubPathName().
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $target = $dst . DIRECTORY_SEPARATOR . $rel;

            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
                    throw new \RuntimeException("copyDir: cannot mkdir: {$target}");
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new \RuntimeException("copyDir: cannot mkdir parent: {$parent}");
            }

            if (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException("copyDir: copy failed: {$item->getPathname()} -> {$target}");
            }
        }
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) return;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function breakOneAssetPathInManifest(string $manifestPath): void
    {
        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new \RuntimeException("cannot read manifest: {$manifestPath}");
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException("manifest json invalid: {$manifestPath}");
        }

        if (!isset($json['assets']) || !is_array($json['assets'])) {
            throw new \RuntimeException("manifest has no assets{}: {$manifestPath}");
        }

        if (!array_key_exists('highlights', $json['assets'])) {
            throw new \RuntimeException("manifest.assets.highlights missing: {$manifestPath}");
        }

        $h = $json['assets']['highlights'];
        if (!is_array($h)) {
            throw new \RuntimeException("manifest.assets.highlights is not array/list or object/map: {$manifestPath}");
        }

        $targetBasename = 'report_highlights_templates.json';
        $brokeTarget = false;

        if (array_is_list($h)) {
            if (empty($h)) {
                throw new \RuntimeException("manifest.assets.highlights list is empty: {$manifestPath}");
            }

            foreach ($h as $i => $entry) {
                if (!$this->assetEntryMatchesBasename($entry, $targetBasename)) {
                    continue;
                }

                $h[$i] = $this->breakAssetEntry($entry);
                $brokeTarget = true;
                break;
            }

            if (!$brokeTarget) {
                $h[0] = $this->breakAssetEntry($h[0]);
                $brokeTarget = true;
            }
        } else {
            $k = array_key_first($h);
            if ($k === null) {
                throw new \RuntimeException("manifest.assets.highlights map is empty: {$manifestPath}");
            }

            foreach ($h as $key => $entry) {
                if (!$this->assetEntryMatchesBasename($entry, $targetBasename)) {
                    continue;
                }

                $h[$key] = $this->breakAssetEntry($entry);
                $brokeTarget = true;
                break;
            }

            if (!$brokeTarget) {
                $h[$k] = $this->breakAssetEntry($h[$k]);
                $brokeTarget = true;
            }
        }

        if (!$brokeTarget) {
            throw new \RuntimeException("failed to break highlights asset entry: {$manifestPath}");
        }

        $json['assets']['highlights'] = $h;

        $newRaw = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($newRaw === false) {
            throw new \RuntimeException("manifest json_encode failed: {$manifestPath}");
        }
        if (file_put_contents($manifestPath, $newRaw) === false) {
            throw new \RuntimeException("cannot write manifest: {$manifestPath}");
        }
    }

    private function breakAssetEntry(mixed $entry): mixed
    {
        $broken = '__MISSING__/__INTENTIONALLY_BROKEN__.json';

        if (is_string($entry)) {
            return $broken;
        }

        if (is_array($entry)) {
            foreach (['path', 'file'] as $key) {
                if (array_key_exists($key, $entry) && is_string($entry[$key])) {
                    $entry[$key] = $broken;
                    return $entry;
                }
            }

            foreach ($entry as $k => $v) {
                if (is_string($v) && (str_contains($v, '/') || str_ends_with($v, '.json'))) {
                    $entry[$k] = $broken;
                    return $entry;
                }
                if (is_array($v)) {
                    $entry[$k] = $this->breakAssetEntry($v);
                    return $entry;
                }
            }
        }

        return $entry;
    }

    private function assetEntryMatchesBasename(mixed $entry, string $basename): bool
    {
        if (is_string($entry)) {
            return basename($entry) === $basename;
        }

        if (!is_array($entry)) {
            return false;
        }

        foreach (['path', 'file'] as $key) {
            if (!array_key_exists($key, $entry) || !is_string($entry[$key])) {
                continue;
            }

            if (basename($entry[$key]) === $basename) {
                return true;
            }
        }

        foreach ($entry as $value) {
            if ($this->assetEntryMatchesBasename($value, $basename)) {
                return true;
            }
        }

        return false;
    }
}
