<?php

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Services\Report\HighlightBuilder;
use App\Services\Content\ContentStore;
use App\Services\Content\ContentPackResolver;

class HighlightBuilderBuildFromStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 1) 强行指定 content_packages 根目录（CI/别人机器不靠 .env）
        config()->set('content.packs_root', base_path('../content_packages'));

        // 2) 强行指定 resolver/pack contract 依赖的 key（按你项目实际 key 为准）
        config()->set('fap.content.scale', 'default');
        config()->set('fap.content.region', 'CN_MAINLAND');
        config()->set('fap.content.locale', 'zh-CN');
        config()->set('fap.content.content_package_version', 'MBTI-CN-v0.2.1-TEST');

        // 3) 如果这些是 singleton：必须 forget，否则仍用旧实例
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

        $min = (int)($expect['min'] ?? 3);
        $max = (int)($expect['max'] ?? 4);

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

        $bid = (string)($blindspots[0]['id'] ?? '');
        $this->assertNotSame('', $bid);

        // 1) 禁止双前缀
        $this->assertStringNotContainsString('hl.blindspot.hl.', $bid, "bad blindspot id found: {$bid}");
        // 2) 禁止出现 borderline
        $this->assertStringNotContainsString('borderline', $bid, "blindspot id must not contain borderline: {$bid}");

        // 只有在“AT borderline 场景”才强约束 AT_ 前缀（避免把逻辑写死）
        if (!empty($expect['require_blindspot_at_prefix'])) {
            $this->assertMatchesRegularExpression('/^hl\.blindspot\.AT_/', $bid, "blindspot id must match format hl.blindspot.AT_* : {$bid}");
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
            // 1) AT borderline（你现在这组）：继续强约束 blindspot 必须 AT_，且 strength 不能 generated
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

            // 2) 所有轴都 clear 且 delta 足够大：确保能稳定选出“非 generated 的 strength”
            'All clear with large deltas' => [
                [
                    'profile' => ['type_code' => 'INTJ-A'],
                    'scores_pct' => ['EI' => 80, 'SN' => 20, 'TF' => 75, 'JP' => 78, 'AT' => 70],
                    'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
                ],
                [
                    'min' => 3,
                    'max' => 4,
                    'require_blindspot_at_prefix' => false, // 不要写死
                    'require_strength' => true,
                    'require_strength_from_template' => true,
                ],
            ],

            // 3) （可选）所有轴都 borderline：目标是“不崩 + 有 blindspot/action + 数量正确”
            //    这组通常更容易走 generated fallback，所以不要强卡 strength_from_template
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
                    'require_strength' => false,               // 这组不强求有 strength（看你业务是否保证）
                    'require_strength_from_template' => false, // 不强求非 generated
                ],
            ],
        ];
    }

    /**
     * 第3步：强制爆炸模式：禁止 scan + 禁止 legacy loader
     * 故意把 manifest 的一个 asset 路径改成不存在，必须抛 RuntimeException
     */
    public function test_store_must_throw_when_asset_missing_and_fallbacks_forbidden(): void
{
    $origRoot = base_path('../content_packages');
    $tmpRoot  = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'fap_content_packages_' . uniqid('', true);

    // resolver 合同路径所需 4 个维度
    $scale   = (string) config('fap.content.scale', 'default');
    $region  = (string) config('fap.content.region', 'CN_MAINLAND');
    $locale  = (string) config('fap.content.locale', 'zh-CN');
    $version = (string) config('fap.content.content_package_version');

    // 只复制 resolver 真正会读的 pack 目录（避免整棵树拷贝引入不确定性）
    $srcPackDir = $origRoot . DIRECTORY_SEPARATOR . $scale . DIRECTORY_SEPARATOR . $region . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $version;
    $dstPackDir = $tmpRoot  . DIRECTORY_SEPARATOR . $scale . DIRECTORY_SEPARATOR . $region . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $version;

    if (!is_file($srcPackDir . DIRECTORY_SEPARATOR . 'manifest.json')) {
        throw new \RuntimeException("source manifest.json not found: " . $srcPackDir . DIRECTORY_SEPARATOR . 'manifest.json');
    }

    $this->copyDir($srcPackDir, $dstPackDir);

    // 改坏复制后的 manifest（让某个 asset 指向不存在的路径）
    $this->breakOneAssetPathInManifest($dstPackDir . DIRECTORY_SEPARATOR . 'manifest.json');

    // packs_root 指到 tmpRoot，并重新解析实例
    config()->set('content.packs_root', $tmpRoot);
    $this->app->forgetInstance(ContentStore::class);
    $this->app->forgetInstance(ContentPackResolver::class);

    // 禁止兜底
    putenv('FAP_FORBID_STORE_ASSET_SCAN=1');
    putenv('FAP_FORBID_LEGACY_CTX_LOADER=1');

    try {
        /** @var ContentStore $store */
        $this->expectException(\RuntimeException::class);

        $store = app(ContentStore::class);
        $store->loadHighlights();
    } finally {
        putenv('FAP_FORBID_STORE_ASSET_SCAN');
        putenv('FAP_FORBID_LEGACY_CTX_LOADER');
        $this->rmDir($tmpRoot);
    }
}

    // -------------------------
    // helpers
    // -------------------------

    private function copyDir(string $src, string $dst): void
    {
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
            /** @var \RecursiveDirectoryIterator $sub */
$sub = $it->getSubIterator();

$target = $dst . DIRECTORY_SEPARATOR . $sub->getSubPathname();
    if ($item->isDir()) {
        if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
            throw new \RuntimeException("copyDir: cannot mkdir: {$target}");
        }
    } else {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        if (!copy($item->getPathname(), $target)) {
            throw new \RuntimeException("copyDir: copy failed: {$item->getPathname()} -> {$target}");
        }
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

    // highlights 必须是 list 或 map；我们只改里面的“路径字符串”，不改变结构
    if (!is_array($h)) {
        throw new \RuntimeException("manifest.assets.highlights is not array/list or object/map: {$manifestPath}");
    }

    if (array_is_list($h)) {
        if (empty($h)) {
            throw new \RuntimeException("manifest.assets.highlights list is empty: {$manifestPath}");
        }
        $h[0] = $this->breakAssetEntry($h[0]);
    } else {
        $k = array_key_first($h);
        if ($k === null) {
            throw new \RuntimeException("manifest.assets.highlights map is empty: {$manifestPath}");
        }
        $h[$k] = $this->breakAssetEntry($h[$k]);
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

    // 1) entry 直接是 path 字符串
    if (is_string($entry)) {
        return $broken;
    }

    // 2) entry 是对象/数组：优先改常见字段 path/file
    if (is_array($entry)) {
        foreach (['path', 'file'] as $key) {
            if (array_key_exists($key, $entry) && is_string($entry[$key])) {
                $entry[$key] = $broken;
                return $entry;
            }
        }

        // 3) 兜底：在 entry 内部“找到第一个看起来像路径的字符串字段”并替换
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

        // 实在找不到就保持原样（宁可不改，也不要改坏合同）
        return $entry;
    }

    // 其它类型不动，避免破坏合同
    return $entry;
}
}