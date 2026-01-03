<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FapSelfCheck extends Command
{
    /**
     * Examples:
     *  php artisan fap:self-check --pkg=MBTI/CN_MAINLAND/zh-CN/v0.2.1-TEST
     *  php artisan fap:self-check --path=../content_packages/MBTI/CN_MAINLAND/zh-CN/v0.2.1-TEST/manifest.json
     *  php artisan fap:self-check --pack_id=MBTI.cn-mainland.zh-CN.v0.2.1-TEST
     */
    protected $signature = 'fap:self-check
    {--pkg= : Relative folder under content_packages (e.g. MBTI/CN_MAINLAND/zh-CN/v0.2.1-TEST)}
    {--path= : Path to manifest.json}
    {--pack_id= : Resolve manifest.json by pack_id (scan content_packages)}
    {--strict-assets : Fail if known files exist in package dir but are not declared in manifest.assets}';

    protected $description = 'Self-check: manifest contract + assets existence/schema + key JSON validations + unified overrides rule validation';

    public function handle(): int
    {
        // 1) locate manifest
        $manifestPath = $this->resolveManifestPath();
        if (!$manifestPath) {
            $this->error("❌ cannot resolve manifest.json (use --path or --pkg or --pack_id)");
            return 1;
        }

        $manifestPath = $this->normalizePath($manifestPath);
        $this->line("== CHECK {$this->guessPackIdForDisplay($manifestPath)}");
        $this->line("   manifest: {$manifestPath}");

        $manifest = $this->readJsonFile($manifestPath);
        if (!is_array($manifest)) {
            $this->error("SELF-CHECK FAILED: manifest invalid JSON");
            return 1;
        }

        $packId  = (string)($manifest['pack_id'] ?? 'UNKNOWN_PACK');
        $baseDir = dirname($manifestPath);

        $ok = true;
        $this->line(str_repeat('-', 72));

        // 0) manifest contract + assets existence + schema alignment + (optional) id/rules scan
        [$mOk, $mMsg] = $this->checkManifestContract($manifest, $manifestPath);
        $ok = $ok && $mOk;
        $this->printSectionResult('manifest.json (contract + assets + schema)', $mOk, $mMsg);

        // build declared asset basenames set (for conditional checks)
        $declaredBasenames = $this->declaredAssetBasenames($manifest);
        // (optional) strict-assets: if certain known files exist but are not declared in manifest.assets -> fail
if ((bool)$this->option('strict-assets')) {
    [$sOk, $sMsg] = $this->checkStrictAssets($baseDir, $declaredBasenames, $packId);
    $ok = $ok && $sOk;
    $this->printSectionResult('strict-assets (undeclared known files)', $sOk, $sMsg);
}

// ✅ schema-alignment: declared JSON assets' $.schema must match manifest.schemas mapping
[$saOk, $saMsg] = $this->checkSchemaAlignment($manifest, $manifestPath, $packId);
$ok = $ok && $saOk;
$this->printSectionResult('schema-alignment (declared JSON assets)', $saOk, $saMsg);

// 可选：如果你希望 schema 不对就直接中止（减少噪音），打开下面 4 行
// if (!$saOk) {
//     $this->line(str_repeat('-', 72));
//     $this->error('❌ SELF-CHECK FAILED (see errors above)');
//     return 1;
// }

        $runIfDeclared = function (string $sectionName, string $filename, callable $runner) use (&$ok, $declaredBasenames) {
            if (!isset($declaredBasenames[$filename])) {
                $this->printSectionResult($sectionName, true, ["SKIPPED (not declared in manifest.assets): {$filename}"]);
                return;
            }
            [$sOk, $sMsg] = $runner();
            $ok = $ok && $sOk;
            $this->printSectionResult($sectionName, $sOk, $sMsg);
        };

        // 1) questions.json
        $runIfDeclared(
    'questions.json',
    'questions.json',
    fn () => $this->checkQuestions(
        $this->pathOf($baseDir, 'questions.json'),
        $packId,
        $this->expectedSchemaFor($manifest, 'questions', 'questions.json')
    )
);

        // 2) type_profiles.json
        $runIfDeclared(
            'type_profiles.json',
            'type_profiles.json',
            fn () => $this->checkTypeProfiles($this->pathOf($baseDir, 'type_profiles.json'), $packId)
        );

// 2.2) identity_cards.json (ids unique)
// 你包里实际文件名是 report_identity_cards.json（ls 已看到）
$runIfDeclared(
    'identity_cards.json',
    'report_identity_cards.json',
    fn () => $this->checkIdentityCards(
        $this->pathOf($baseDir, 'report_identity_cards.json'),
        $packId,
        $this->expectedSchemaFor($manifest, 'identity', 'report_identity_cards.json')
    )
);

// 2.3) identity_layers.json (ids unique)
// 你包里实际文件名是 identity_layers.json（ls 已看到）
$runIfDeclared(
    'identity_layers.json',
    'identity_layers.json',
    fn () => $this->checkIdentityLayers(
        $this->pathOf($baseDir, 'identity_layers.json'),
        $packId,
        $this->expectedSchemaFor($manifest, 'identity', 'identity_layers.json')
    )
);

// 2.5) report_cards_*.json (cards) —— 只校验 manifest.assets.cards
$cardFiles = $manifest['assets']['cards'] ?? null;
if (is_array($cardFiles) && !empty($cardFiles)) {
    $expectedCardsSchema = $manifest['schemas']['cards'] ?? null;

    [$cOk, $cMsg] = $this->checkCards($baseDir, $cardFiles, $packId, $expectedCardsSchema, 'cards');
    $ok = $ok && $cOk;
    $this->printSectionResult('report_cards_*.json', $cOk, $cMsg);
} else {
    $this->printSectionResult('report_cards_*.json', true, ["SKIPPED (no manifest.assets.cards)"]);
}

// 2.6) report_cards_fallback_*.json (fallback_cards) —— 单独校验 manifest.assets.fallback_cards
$fallbackCardFiles = $manifest['assets']['fallback_cards'] ?? null;
if (is_array($fallbackCardFiles) && !empty($fallbackCardFiles)) {
    $expectedFallbackCardsSchema = $manifest['schemas']['fallback_cards'] ?? null;

    [$fcOk, $fcMsg] = $this->checkCards($baseDir, $fallbackCardFiles, $packId, $expectedFallbackCardsSchema, 'fallback_cards');
    $ok = $ok && $fcOk;
    $this->printSectionResult('report_cards_fallback_*.json', $fcOk, $fcMsg);
} else {
    $this->printSectionResult('report_cards_fallback_*.json', true, ["SKIPPED (no manifest.assets.fallback_cards)"]);
}

        // 3) report_highlights_templates.json
        $runIfDeclared(
            'report_highlights_templates.json',
            'report_highlights_templates.json',
            fn () => $this->checkHighlightsTemplates($this->pathOf($baseDir, 'report_highlights_templates.json'), $packId)
        );

        // 3.5) report_highlights_pools.json  ✅ NEW
$runIfDeclared(
    'report_highlights_pools.json',
    'report_highlights_pools.json',
    fn () => $this->checkHighlightsPools(
        $this->pathOf($baseDir, 'report_highlights_pools.json'),
        $packId,
        $this->expectedSchemaFor($manifest, 'highlights', 'report_highlights_pools.json')
    )
);

// 3.6) report_highlights_rules.json  ✅ NEW（会校验引用的模板 id 必须存在 + policy + coverage）
$runIfDeclared(
    'report_highlights_rules.json',
    'report_highlights_rules.json',
    fn () => $this->checkHighlightsRules(
        $this->pathOf($baseDir, 'report_highlights_rules.json'),
        $baseDir,
        $packId,
        $this->expectedSchemaFor($manifest, 'highlights', 'report_highlights_rules.json')
    )
);

        // 4) report_highlights_overrides.json (legacy)
        $runIfDeclared(
            'report_highlights_overrides.json',
            'report_highlights_overrides.json',
            fn () => $this->checkHighlightsOverrides($this->pathOf($baseDir, 'report_highlights_overrides.json'), $packId)
        );

        // 5) report_borderline_templates.json
        $runIfDeclared(
            'report_borderline_templates.json',
            'report_borderline_templates.json',
            fn () => $this->checkBorderlineTemplates($this->pathOf($baseDir, 'report_borderline_templates.json'), $packId)
        );

        // 6) report_roles.json
        $runIfDeclared(
            'report_roles.json',
            'report_roles.json',
            fn () => $this->checkReportRoles($this->pathOf($baseDir, 'report_roles.json'), $packId)
        );

        // 7) report_strategies.json
        $runIfDeclared(
            'report_strategies.json',
            'report_strategies.json',
            fn () => $this->checkReportStrategies($this->pathOf($baseDir, 'report_strategies.json'), $packId)
        );

        // 8) report_recommended_reads.json
        $runIfDeclared(
            'report_recommended_reads.json',
            'report_recommended_reads.json',
            fn () => $this->checkRecommendedReads($this->pathOf($baseDir, 'report_recommended_reads.json'), $packId)
        );

// 8.5) report_section_policies.json (section min_cards policies + fallback coverage)
$runIfDeclared(
    'report_section_policies.json',
    'report_section_policies.json',
    fn () => $this->checkSectionPolicies(
        $this->pathOf($baseDir, 'report_section_policies.json'),
        $manifest,
        $packId,
        $this->expectedSchemaFor($manifest, 'section_policies', 'report_section_policies.json')
    )
);

// 8.6) fallback cards existence + coverage (by section policies)
// - 要求：fallback 文件存在
// - 数量：>= min_cards 通过；0 失败；(0, min_cards) 给 warning
if (isset($declaredBasenames['report_section_policies.json'])) {
    [$fOk, $fMsg] = $this->checkFallbackCardsAgainstSectionPolicies($manifest, $baseDir, $packId);
    $ok = $ok && $fOk;
    $this->printSectionResult('fallback-cards (coverage by section policies)', $fOk, $fMsg);
} else {
    $this->printSectionResult('fallback-cards (coverage by section policies)', true, ["SKIPPED (report_section_policies.json not declared)"]);
}

        // 9) report_overrides.json (unified overrides rules validation)
        $runIfDeclared(
            'report_overrides.json',
            'report_overrides.json',
            fn () => $this->checkReportOverrides($this->pathOf($baseDir, 'report_overrides.json'), $manifest, $packId)
        );

        // 10) report_rules.json (rules schema validation)
$runIfDeclared(
    'report_rules.json',
    'report_rules.json',
    fn () => $this->checkReportRules(
        $this->pathOf($baseDir, 'report_rules.json'),
        $manifest,
        $packId,
        $this->expectedSchemaFor($manifest, 'rules', 'report_rules.json')
    )
);

        $this->line(str_repeat('-', 72));
        if ($ok) {
            $this->info('✅ SELF-CHECK PASSED');
            return 0;
        }
        $this->error('❌ SELF-CHECK FAILED (see errors above)');
        return 1;
    }

    // -------------------------
    // Manifest resolving
    // -------------------------

    private function resolveManifestPath(): ?string
    {
        $path   = $this->option('path');
        $pkg    = $this->option('pkg');
        $packId = $this->option('pack_id');

        if (is_string($path) && trim($path) !== '') {
            return $path;
        }

        if (is_string($pkg) && trim($pkg) !== '') {
            return base_path("../content_packages/{$pkg}/manifest.json");
        }

        if (is_string($packId) && trim($packId) !== '') {
            return $this->findManifestByPackId(trim($packId));
        }

        // default (keep your previous convention)
        $defaultPkg = env('MBTI_CONTENT_PACKAGE', 'MBTI/CN_MAINLAND/zh-CN/v0.2.1-TEST');
        return base_path("../content_packages/{$defaultPkg}/manifest.json");
    }

    private function findManifestByPackId(string $packId): ?string
    {
        $root = $this->normalizePath(base_path('../content_packages'));
        if (!is_dir($root)) return null;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (strtolower($file->getFilename()) !== 'manifest.json') continue;
            $p = $file->getPathname();
            $json = $this->readJsonFile($p);
            if (is_array($json) && (string)($json['pack_id'] ?? '') === $packId) {
                return $p;
            }
        }

        return null;
    }

    private function guessPackIdForDisplay(string $manifestPath): string
    {
        $m = $this->readJsonFile($manifestPath);
        return is_array($m) ? (string)($m['pack_id'] ?? 'UNKNOWN_PACK') : 'UNKNOWN_PACK';
    }

    // -------------------------
    // Manifest contract + assets check
    // -------------------------

    private function checkManifestContract(array $manifest, string $manifestPath): array
    {
        $packId = (string)($manifest['pack_id'] ?? 'UNKNOWN_PACK');
        $baseDir = dirname($manifestPath);

        $errors = [];

        // 1) schema_version
        $sv = $manifest['schema_version'] ?? null;
        if ($sv !== 'pack-manifest@v1') {
            $errors[] = "pack={$packId} file={$manifestPath} path=$.schema_version :: must be 'pack-manifest@v1', got=" . var_export($sv, true);
        }

        // 2) required fields
        $required = ['scale_code', 'region', 'locale', 'content_package_version', 'pack_id', 'assets', 'schemas', 'capabilities', 'fallback'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $manifest)) {
                $errors[] = "pack={$packId} file={$manifestPath} path=$.{$k} :: missing required field";
            }
        }

        // 3) basic shapes
        if (isset($manifest['fallback']) && !is_array($manifest['fallback'])) {
            $errors[] = "pack={$packId} file={$manifestPath} path=$.fallback :: must be array(list)";
        }

        if (isset($manifest['capabilities']) && !is_array($manifest['capabilities'])) {
            $errors[] = "pack={$packId} file={$manifestPath} path=$.capabilities :: must be object(map)";
        }

        $schemas = $manifest['schemas'] ?? null;
        if (!is_array($schemas)) {
            $errors[] = "pack={$packId} file={$manifestPath} path=$.schemas :: must be object(map)";
        }

        $assets = $manifest['assets'] ?? null;
        if (!is_array($assets)) {
            $errors[] = "pack={$packId} file={$manifestPath} path=$.assets :: must be object(map)";
            return [false, $errors];
        }

        // 4) assets existence + collect JSON files
        $jsonFiles = []; // abs paths
        foreach ($assets as $assetKey => $paths) {
            if (!is_array($paths)) {
                $errors[] = "pack={$packId} file={$manifestPath} path=$.assets.{$assetKey} :: must be array(list) or object(map for overrides)";
                continue;
            }

            // overrides can be object with buckets + order
            if ($assetKey === 'overrides' && $this->isAssocArray($paths)) {
                // order
                if (!isset($paths['order']) || !is_array($paths['order'])) {
                    $errors[] = "pack={$packId} file={$manifestPath} path=$.assets.overrides.order :: must be array(list of bucket names)";
                } else {
                    foreach ($paths['order'] as $i => $bucket) {
                        if (!is_string($bucket) || trim($bucket) === '') {
                            $errors[] = "pack={$packId} file={$manifestPath} path=$.assets.overrides.order[{$i}] :: must be non-empty string";
                        }
                        if (!array_key_exists($bucket, $paths)) {
                            $errors[] = "pack={$packId} file={$manifestPath} path=$.assets.overrides :: bucket '{$bucket}' declared in order but missing in overrides object";
                        }
                    }
                }

                // buckets
                foreach ($paths as $bucket => $v) {
                    if ($bucket === 'order') continue;
                    $list = is_array($v) ? $v : [$v];
                    foreach ($list as $i => $rel) {
                        if (!is_string($rel) || trim($rel) === '') {
                            $errors[] = "pack={$packId} file={$manifestPath} path=$.assets.overrides.{$bucket}[{$i}] :: must be non-empty string path";
                            continue;
                        }
                        $abs = $this->pathOf($baseDir, $rel);
                        if (!is_file($abs)) {
                            $errors[] = "pack={$packId} file={$manifestPath} path=$.assets.overrides.{$bucket}[{$i}] :: file not found: {$abs}";
                            continue;
                        }
                        if ($this->isJsonFile($abs)) $jsonFiles[] = $abs;
                    }
                }
                continue;
            }

            // normal list assets
            foreach ($paths as $i => $rel) {
                if (!is_string($rel) || trim($rel) === '') {
                    $errors[] = "pack={$packId} file={$manifestPath} path=$.assets.{$assetKey}[{$i}] :: must be non-empty string path";
                    continue;
                }

                $abs = $this->pathOf($baseDir, $rel);

                // dir entry (ends with '/')
                if (str_ends_with($rel, '/') || str_ends_with($rel, DIRECTORY_SEPARATOR)) {
                    if (!is_dir($abs)) {
                        $errors[] = "pack={$packId} file={$manifestPath} path=$.assets.{$assetKey}[{$i}] :: dir not found: {$abs}";
                    }
                    continue;
                }

                if (!is_file($abs)) {
                    $errors[] = "pack={$packId} file={$manifestPath} path=$.assets.{$assetKey}[{$i}] :: file not found: {$abs}";
                    continue;
                }

                if ($this->isJsonFile($abs)) $jsonFiles[] = $abs;
            }
        }

        // 6) optional: minimal JSON parse check for every declared json file
        foreach (array_values(array_unique($jsonFiles)) as $abs) {
            $doc = $this->readJsonFile($abs);
            if (!is_array($doc)) {
                $errors[] = "pack={$packId} file={$abs} :: invalid JSON";
            }
        }

        if (!empty($errors)) return [false, $errors];

        return [true, [
            "OK (schema_version/required fields/assets exist/schema match)",
            "pack_id={$packId} version=" . (string)($manifest['content_package_version'] ?? ''),
        ]];
    }

    private function checkAssetsSchemasAgainstManifest(array $manifest, string $baseDir, string $packId): array
{
    $errs = [];

    $schemas = $manifest['schemas'] ?? null;
    $assets  = $manifest['assets'] ?? null;

    if (!is_array($schemas) || !is_array($assets)) return $errs;

    // 记录哪些 schemaKey 被 assets 实际用到了（用于做覆盖检查/提示）
    $usedSchemaKeys = [];

    $readSchema = function (string $rel) use ($baseDir) {
        $abs = $this->pathOf($baseDir, $rel);
        if (!is_file($abs)) return [null, null, $abs];
        if (!$this->isJsonFile($abs)) return [null, null, $abs];

        $doc = $this->readJsonFile($abs);
        $got = is_array($doc) ? ($doc['schema'] ?? null) : null;
        return [$doc, $got, $abs];
    };

    $assertOne = function (string $assetLabel, string $assetKey, string $rel) use (&$errs, &$usedSchemaKeys, $manifest, $schemas, $readSchema, $packId) {
        if (!is_string($rel) || trim($rel) === '') return;
        if (str_ends_with($rel, '/')) return;

    // refine label for better error messages (identity -> identity_cards/identity_layers/roles/strategies)
        $label = $assetLabel;
        if ($assetKey === 'identity') {
            $bn = basename($rel);
            if (str_contains($bn, 'identity_cards'))       $label = 'identity_cards';
            elseif (str_contains($bn, 'identity_layers'))  $label = 'identity_layers';
            elseif (str_contains($bn, 'roles'))            $label = 'roles';
            elseif (str_contains($bn, 'strategies'))       $label = 'strategies';
        }

        // 只对 JSON 做 schema 对齐
        [$doc, $got, $abs] = $readSchema($rel);
        if ($doc === null && $got === null) {
            // 不是 JSON 或文件不存在（存在性在上层 assets check 已经报过，这里不重复）
            return;
        }
        if (!$this->isJsonFile($abs)) return;

        // 通过 manifest + assetKey + 文件名，推导“应该用哪个 schema”
        $expected = $this->expectedSchemaFor($manifest, $assetKey, basename($rel));
        if ($expected === null) {
$errs[] = "pack={$packId} file={$abs} :: missing manifest.schemas mapping (asset={$label})";            return;
        }

        // 记录 used schemaKey（从 expected 字符串反推 schemaKey 不可靠，所以这里用 expectedSchemaFor 的“存在性”即可）
        // 更严格的覆盖检查放到后面：通过 assets 重新推 schemaKey
        // 这里先不做 usedSchemaKeys 统计，避免反推误差

        if (!is_string($got) || trim((string)$got) === '') {
$errs[] = "pack={$packId} file={$abs} path=$.schema :: missing schema field (asset={$label}) want=" . var_export($expected, true);            return;
        }

        if ($got !== $expected) {
            $errs[] = "pack={$packId} file={$abs} path=$.schema :: schema mismatch got="
    . var_export($got, true)
    . " want="
    . var_export($expected, true)
    . " (asset={$label})";
        }
    };

    // 1) 遍历 assets：每个 JSON 都必须能映射到 schema，且 schema 必须一致
    foreach ($assets as $assetKey => $paths) {
        // overrides: object(map) with buckets + order
        if ($assetKey === 'overrides' && is_array($paths) && $this->isAssocArray($paths)) {
            foreach ($paths as $bucket => $list) {
                if ($bucket === 'order') continue;

                $list = is_array($list) ? $list : [$list];

                // bucket -> “伪 assetKey”，让 expectedSchemaFor 能识别
                $bucketKey = null;
                if ($bucket === 'unified') $bucketKey = 'overrides_unified';
                if ($bucket === 'highlights_legacy') $bucketKey = 'overrides_highlights_legacy';

                foreach ($list as $rel) {
                    $assertOne("overrides.{$bucket}", $bucketKey ?? 'overrides', (string)$rel);
                }
            }
            continue;
        }

        // normal assets: list
        if (!is_array($paths)) continue;

        foreach ($paths as $rel) {
            if (!is_string($rel)) continue;
            $assertOne((string)$assetKey, (string)$assetKey, $rel);
        }
    }

    // 2) 覆盖检查：assets 中出现的 schemaKey 必须在 manifest.schemas 有声明
    //    （这个检查实际已经被 assertOne 的 expectedSchemaFor -> null 覆盖了）
    //    但我们额外检查 “manifest.schemas 里声明了但 assets 没用到”的情况，给 warning（不 fail）
    //    你如果想强制 fail，把 warn 改成 errs 即可。
    $knownSchemaKeys = array_keys(is_array($schemas) ? $schemas : []);
    // 根据 assets 重新推导 schemaKey（比 usedSchemaKeys 更准确）
    $used = [];
    $collectSchemaKey = function (string $assetKey, string $file) use (&$used) {
        // 与 expectedSchemaFor 保持同样的分类口径（只收集 schemaKey，不做值判断）
        $file = (string)$file;

        if (in_array($assetKey, ['questions','type_profiles','cards','fallback_cards','highlights','reads','rules'], true)) {
           $used[$assetKey] = true;
           return;
        }

        if ($assetKey === 'borderline') {
            if (str_contains($file, 'borderline_templates')) $used['borderline_templates'] = true;
            if (str_contains($file, 'borderline_notes')) $used['borderline_notes'] = true;
            return;
        }

        if ($assetKey === 'identity') {
            if (str_contains($file, 'identity_cards'))  $used['identity_cards'] = true;
            if (str_contains($file, 'identity_layers')) $used['identity_layers'] = true;
            if (str_contains($file, 'roles'))           $used['roles'] = true;
            if (str_contains($file, 'strategies'))      $used['strategies'] = true;
            return;
        }

        if ($assetKey === 'overrides_unified') $used['overrides_unified'] = true;
        if ($assetKey === 'overrides_highlights_legacy') $used['overrides_highlights_legacy'] = true;
    };

    foreach ($assets as $assetKey => $paths) {
        if ($assetKey === 'overrides' && is_array($paths) && $this->isAssocArray($paths)) {
            foreach ($paths as $bucket => $list) {
                if ($bucket === 'order') continue;
                $list = is_array($list) ? $list : [$list];

                $bucketKey = null;
                if ($bucket === 'unified') $bucketKey = 'overrides_unified';
                if ($bucket === 'highlights_legacy') $bucketKey = 'overrides_highlights_legacy';

                foreach ($list as $rel) {
                    if (!is_string($rel)) continue;
                    $collectSchemaKey($bucketKey ?? 'overrides', basename($rel));
                }
            }
            continue;
        }

        if (!is_array($paths)) continue;
        foreach ($paths as $rel) {
            if (!is_string($rel)) continue;
            $collectSchemaKey((string)$assetKey, basename($rel));
        }
    }

    $unusedSchemaKeys = array_values(array_diff($knownSchemaKeys, array_keys($used)));
    if (!empty($unusedSchemaKeys)) {
        // 不 fail，只提示（如果你想 fail，把这一段改成 $errs[]）
        // $errs[] = "pack={$packId} file=manifest.json path=$.schemas :: unused schema keys: " . implode(', ', $unusedSchemaKeys);
        // 这里选择不加 errs，避免你未来扩展 schemas 时被卡死
    }

    return $errs;
}

private function checkSchemaAlignment(array $manifest, string $manifestPath, string $packId): array
{
    $baseDir = dirname($manifestPath);

    $errs = $this->checkAssetsSchemasAgainstManifest($manifest, $baseDir, $packId);

    if (!empty($errs)) {
        return [false, array_merge(
            ["Schema alignment invalid: " . count($errs)],
            array_slice($errs, 0, 120)
        )];
    }

    return [true, ["OK (declared JSON assets schema aligned)"]];
}

// -------------------------
// Unified overrides rules validation
// -------------------------

private function checkReportOverrides(string $path, array $manifest, string $packId, ?string $expectedSchema = null): array
{
    if (!is_file($path)) {
        return [false, ["pack={$packId} file={$path} :: File not found"]];
    }

    $doc = $this->readJsonFile($path);
    if (!is_array($doc)) {
        return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];
    }

        if ($errs = $this->checkSchemaField($doc, $expectedSchema, $packId, $path, 'overrides_unified')) {
        return [false, $errs];
    }

    // ------------------------------------------------------------------
    // ✅ Legacy highlights overrides support
    // schema = fap.report.highlights.overrides.v1
    // structure is {schema, items:{...}} (NOT {rules:[...]} / {overrides:[...]})
    // ------------------------------------------------------------------
    $schema = (string)($doc['schema'] ?? '');
    $base   = basename($path);

    if ($schema === 'fap.report.highlights.overrides.v1' || $base === 'report_highlights_overrides.json') {
        $items = $doc['items'] ?? null;

        // items must be object(map), not list
        $isList = is_array($items) && array_keys($items) === range(0, count($items) - 1);
        if (!is_array($items) || $items === [] || $isList) {
            return [false, [
                "pack={$packId} file={$path} path=$.items :: legacy highlights overrides requires items object(map)"
            ]];
        }

        // optional: ensure per-type blocks are arrays/objects (lightweight sanity)
        foreach ($items as $typeCode => $node) {
            if (!is_array($node)) {
                return [false, [
                    "pack={$packId} file={$path} path=$.items.{$typeCode} :: legacy highlights overrides item must be object(map)"
                ]];
            }
        }

        return [true, ["OK (legacy highlights overrides valid; items_types=" . count($items) . ")"]];
    }

    // ------------------------------------------------------------------
    // Unified overrides: accept both keys; prefer overrides
    // ------------------------------------------------------------------
    $listKey = null;
    $rules = null;
    if (is_array($doc['overrides'] ?? null)) { $listKey = 'overrides'; $rules = $doc['overrides']; }
    elseif (is_array($doc['rules'] ?? null)) { $listKey = 'rules'; $rules = $doc['rules']; }

    if (!is_array($rules)) {
        return [false, ["pack={$packId} file={$path} path=$.overrides/$.rules :: missing overrides list (expect overrides:[] or rules:[])"]];
    }

    $allowedTargets = ['highlights', 'cards', 'reads'];
    $allowedModes   = ['append', 'patch', 'replace', 'remove'];

    $seenRuleIds = [];
    $errors = [];

    foreach ($rules as $i => $r) {
        $basePath = '$.' . $listKey . "[{$i}]";

        if (!is_array($r) || !$this->isAssocArray($r)) {
            $errors[] = "ERR pack={$packId} file={$path} path={$basePath} :: rule must be object";
            continue;
        }

        $rid = (string)($r['id'] ?? '');
        if ($rid === '') {
            $errors[] = "ERR pack={$packId} file={$path} path={$basePath}.id :: rule.id missing";
        } else {
            if (isset($seenRuleIds[$rid])) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.id :: duplicate rule.id (first at {$seenRuleIds[$rid]})";
            } else {
                $seenRuleIds[$rid] = $basePath;
            }
        }

        $target = (string)($r['target'] ?? '');
        if ($target === '' || !in_array($target, $allowedTargets, true)) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.target :: invalid target (allowed: " . implode(',', $allowedTargets) . ")";
        }

        $mode = (string)($r['mode'] ?? '');
        if ($mode === '' || !in_array($mode, $allowedModes, true)) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.mode :: invalid mode (allowed: " . implode(',', $allowedModes) . ")";
        }

        if (array_key_exists('match', $r)) {
    if (!is_array($r['match']) || !$this->isAssocArray($r['match'])) {
        $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match :: match must be object(map)";
    } else {
        $this->validateOverrideMatch($r['match'], $packId, $path, $basePath, $rid, $errors);
    }
}

        // required payloads (compatible with your current applier behavior)
        $hasItem    = array_key_exists('item', $r) && is_array($r['item']);
        $hasItems   = array_key_exists('items', $r) && is_array($r['items']);
        $hasPatch   = array_key_exists('patch', $r) && is_array($r['patch']);
        $hasReplace = array_key_exists('replace', $r) && (is_array($r['replace']) || is_string($r['replace']));

        if ($mode === 'append') {
            // ✅ append should be items/item/replace (NOT patch)
            if (!$hasItems && !$hasItem && !$hasReplace) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.items / {$basePath}.item / {$basePath}.replace :: append mode requires items/item/replace";
            }
        } elseif ($mode === 'patch') {
            if (!$hasPatch) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.patch :: patch mode requires patch:{}";
            }
        } elseif ($mode === 'replace') {
            if (!$hasReplace && !$hasPatch && !$hasItems && !$hasItem) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.replace / {$basePath}.patch / {$basePath}.items / {$basePath}.item :: replace mode requires replace/patch/items/item";
            }
        } elseif ($mode === 'remove') {
            if (!array_key_exists('match', $r)) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match :: remove mode requires match:{}";
            }
        }
    }

    if (!empty($errors)) {
        return [false, array_merge(["Unified overrides invalid: " . count($errors)], array_slice($errors, 0, 120))];
    }

    return [true, ["OK (unified overrides rules valid; rules_count=" . count($rules) . ")"]];
}

// -------------------------
// Report rules validation (v1)
// -------------------------

private function checkReportRules(string $path, array $manifest, string $packId, ?string $expectedSchema = null): array
{
    if (!is_file($path)) {
        return [false, ["pack={$packId} file={$path} :: File not found"]];
    }

    $doc = $this->readJsonFile($path);
    if (!is_array($doc)) {
        return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];
    }

    // ✅ 0) schema field check
    if ($errs = $this->checkSchemaField($doc, $expectedSchema, $packId, $path, 'rules')) {
        return [false, $errs];
    }

    $errors = [];

    // ✅ 1) must have rules:[]
    $rules = $doc['rules'] ?? null;
    if (!is_array($rules)) {
        return [false, ["pack={$packId} file={$path} path=$.rules :: missing/invalid rules (expect array(list))"]];
    }
    // list vs map check: must be list (not assoc)
    if ($this->isAssocArray($rules)) {
        return [false, ["pack={$packId} file={$path} path=$.rules :: rules must be array(list), not object(map)"]];
    }

    $allowedTargets = ['cards', 'highlights', 'reads'];
    $allowedMode    = ['filter'];
    $allowedAction  = ['keep', 'drop'];

    $seenIds = [];

    // helpers (local)
    $isList = function ($v): bool {
        return is_array($v) && array_keys($v) === range(0, count($v) - 1);
    };

    $isIntLike = function ($v): bool {
        if (is_int($v)) return true;
        if (is_string($v) && trim($v) !== '' && preg_match('/^-?\d+$/', $v)) return true;
        if (is_numeric($v) && (int)$v == $v) return true;
        return false;
    };

    $checkListOfNonEmptyString = function ($v, string $where) use (&$errors, $packId, $path, $isList): void {
        if (!is_array($v) || !$isList($v)) {
            $errors[] = "pack={$packId} file={$path} {$where} :: must be array(list of non-empty strings)";
            return;
        }
        foreach ($v as $i => $s) {
            if (!is_string($s) || trim($s) === '') {
                $errors[] = "pack={$packId} file={$path} {$where}[{$i}] :: must be non-empty string";
            }
        }
    };

    foreach ($rules as $i => $r) {
        $base = "$.rules[{$i}]";

        if (!is_array($r) || !$this->isAssocArray($r)) {
            $errors[] = "pack={$packId} file={$path} path={$base} :: rule must be object(map)";
            continue;
        }

        // id: non-empty + unique
        $rid = $r['id'] ?? null;
        if (!is_string($rid) || trim($rid) === '') {
            $errors[] = "pack={$packId} file={$path} path={$base}.id :: missing/invalid id (non-empty string required)";
            $rid = ""; // keep running to collect more errors
        } else {
            $rid = trim($rid);
            if (isset($seenIds[$rid])) {
                $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.id :: duplicate id (first at {$seenIds[$rid]})";
            } else {
                $seenIds[$rid] = $base;
            }
        }

        // target: enum
        $target = $r['target'] ?? null;
        if (!is_string($target) || !in_array($target, $allowedTargets, true)) {
            $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.target :: invalid target (allowed: " . implode(',', $allowedTargets) . ")";
        }

        // priority: optional int
        if (array_key_exists('priority', $r)) {
            if (!$isIntLike($r['priority'])) {
                $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.priority :: must be integer if present";
            }
        }

        // weight: optional number
        if (array_key_exists('weight', $r)) {
            if (!is_numeric($r['weight'])) {
                $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.weight :: must be number if present";
            }
        }

        // mode: must be "filter"
        $mode = $r['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, $allowedMode, true)) {
            $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.mode :: invalid mode (allowed: filter)";
        }

        // match: optional object; allow keys section/type_code/item, each must be list[str]
        if (array_key_exists('match', $r)) {
            $match = $r['match'];

            if (!is_array($match) || !$this->isAssocArray($match)) {
                $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.match :: match must be object(map)";
            } else {
                $allowedMatchKeys = ['section', 'type_code', 'item'];

                foreach ($match as $k => $_) {
                    if (!in_array((string)$k, $allowedMatchKeys, true)) {
                        $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.match.{$k} :: unknown match key (allowed: " . implode(',', $allowedMatchKeys) . ")";
                    }
                }

                foreach (['section','type_code','item'] as $k) {
                    if (array_key_exists($k, $match)) {
                        $checkListOfNonEmptyString($match[$k], "path={$base}.match.{$k}");
                    }
                }
            }
        }

        // require_all / require_any / forbid: optional list[str], non-empty elements
        foreach (['require_all','require_any','forbid'] as $k) {
            if (array_key_exists($k, $r)) {
                $checkListOfNonEmptyString($r[$k], "path={$base}.{$k}");
            }
        }

        // min_match: optional int + constraint with require_any
        if (array_key_exists('min_match', $r)) {
            if (!$isIntLike($r['min_match'])) {
                $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.min_match :: must be integer if present";
            } else {
                $mm = (int)$r['min_match'];
                if ($mm < 1) {
                    $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.min_match :: must be >= 1";
                } else {
                    $ra = $r['require_any'] ?? null;
                    if (!is_array($ra) || !$isList($ra) || count($ra) === 0) {
                        $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.min_match :: require_any must be non-empty array(list) when min_match is set";
                    } else {
                        $n = count($ra);
                        if ($mm > $n) {
                            $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.min_match :: must be <= count(require_any) (= {$n})";
                        }
                    }
                }
            }
        }

        // effect.action OR action (top-level): enum keep/drop
        $action = null;

        if (array_key_exists('effect', $r)) {
            $eff = $r['effect'];
            if (!is_array($eff) || !$this->isAssocArray($eff)) {
                $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.effect :: must be object(map) if present";
            } else {
                $action = $eff['action'] ?? null;
                if ($action === null) {
                    $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.effect.action :: missing action (allowed: keep/drop)";
                } elseif (!is_string($action) || !in_array($action, $allowedAction, true)) {
                    $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.effect.action :: invalid action (allowed: keep/drop)";
                }
            }
        } elseif (array_key_exists('action', $r)) {
            $action = $r['action'];
            if (!is_string($action) || !in_array($action, $allowedAction, true)) {
                $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.action :: invalid action (allowed: keep/drop)";
            }
        } else {
            // v1: allow missing (default keep), so no error
        }
    }

    if (!empty($errors)) {
        return [false, array_merge(
            ["Report rules invalid: " . count($errors)],
            array_slice($errors, 0, 160)
        )];
    }

    return [true, ["OK (rules valid; count=" . count($rules) . ")"]];
}

    // -------------------------
    // File checks
    // -------------------------

private function checkSectionPolicies(string $path, array $manifest, string $packId, ?string $expectedSchema = null): array
{
    if (!is_file($path)) {
        return [false, ["pack={$packId} file={$path} :: File not found"]];
    }

    $doc = $this->readJsonFile($path);
    if (!is_array($doc)) {
        return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];
    }

    if ($errs = $this->checkSchemaField($doc, $expectedSchema, $packId, $path, 'section_policies')) {
        return [false, $errs];
    }

    $items = null;
    if (is_array($doc['items'] ?? null)) $items = $doc['items'];
    else $items = $doc;

    if (!is_array($items) || $items === [] || !$this->isAssocArray($items)) {
        return [false, ["pack={$packId} file={$path} path=$.items :: must be object(map of section => policy)"]];
    }

    $requiredSections = ['traits', 'career', 'growth', 'relationships'];
    $errors = [];

    foreach ($requiredSections as $s) {
        if (!array_key_exists($s, $items)) {
            $errors[] = "pack={$packId} file={$path} path=$.items.{$s} :: missing required section policy";
        }
    }

    foreach ($items as $sec => $pol) {
        $base = "path=$.items.{$sec}";
        if (!is_string($sec) || trim($sec) === '') {
            $errors[] = "pack={$packId} file={$path} {$base} :: section key must be non-empty string";
            continue;
        }
        if (!is_array($pol) || !$this->isAssocArray($pol)) {
            $errors[] = "pack={$packId} file={$path} {$base} :: policy must be object(map)";
            continue;
        }

        // min_cards: required int >= 0
        if (!array_key_exists('min_cards', $pol)) {
            $errors[] = "pack={$packId} file={$path} {$base}.min_cards :: missing required min_cards";
        } else {
            $v = $pol['min_cards'];
            if (!is_int($v) && !(is_numeric($v) && (int)$v == $v)) {
                $errors[] = "pack={$packId} file={$path} {$base}.min_cards :: must be integer >= 0";
            } elseif ((int)$v < 0) {
                $errors[] = "pack={$packId} file={$path} {$base}.min_cards :: must be >= 0";
            }
        }

        // allow_fallback: optional bool
        if (array_key_exists('allow_fallback', $pol) && !is_bool($pol['allow_fallback'])) {
            $errors[] = "pack={$packId} file={$path} {$base}.allow_fallback :: must be boolean if present";
        }

        // fallback_file: optional string (relative path)
        if (array_key_exists('fallback_file', $pol) && !(is_string($pol['fallback_file']) && trim($pol['fallback_file']) !== '')) {
            $errors[] = "pack={$packId} file={$path} {$base}.fallback_file :: must be non-empty string if present";
        }
    }

    if (!empty($errors)) {
        return [false, array_merge(["Section policies invalid: " . count($errors)], array_slice($errors, 0, 120))];
    }

    return [true, ["OK (section policies valid; sections=" . count($items) . ")"]];
}

private function checkFallbackCardsAgainstSectionPolicies(array $manifest, string $baseDir, string $packId): array
{
    $polPath = $this->pathOf($baseDir, 'report_section_policies.json');
    if (!is_file($polPath)) {
        return [false, ["pack={$packId} file={$polPath} :: report_section_policies.json not found"]];
    }

    $polDoc = $this->readJsonFile($polPath);
    if (!is_array($polDoc)) {
        return [false, ["pack={$packId} file={$polPath} :: report_section_policies.json invalid JSON"]];
    }

    $items = is_array($polDoc['items'] ?? null) ? $polDoc['items'] : $polDoc;
    if (!is_array($items) || !$this->isAssocArray($items)) {
        return [false, ["pack={$packId} file={$polPath} path=$.items :: must be object(map)"]];
    }

    // flatten declared asset rel paths (excluding dirs)
    $rels = $this->flattenDeclaredAssetRelPaths($manifest);

    $errors = [];
    $notes  = [];

    foreach ($items as $secKey => $pol) {
        if (!is_string($secKey) || trim($secKey) === '') continue;
        if (!is_array($pol)) $pol = [];

        $minCards = (int)($pol['min_cards'] ?? 0);
        $allowFallback = array_key_exists('allow_fallback', $pol) ? (bool)$pol['allow_fallback'] : true;

        // 如果不允许 fallback，就只做存在性提示（不强制）
        if (!$allowFallback) {
            $notes[] = "OK section={$secKey} allow_fallback=false (skip fallback coverage check)";
            continue;
        }

        // 1) resolve fallback file
        $rel = null;

        // (a) policy 指定 fallback_file 优先
        if (is_string($pol['fallback_file'] ?? null) && trim((string)$pol['fallback_file']) !== '') {
            $rel = trim((string)$pol['fallback_file']);
        } else {
            // (b) 否则从 manifest.assets 里猜：文件名同时包含 fallback + sectionKey
            $cands = [];
            foreach ($rels as $r) {
                $bn = strtolower(basename($r));
                $sk = strtolower($secKey);

                $hasFallback = str_contains($bn, 'fallback');
                $hasSection  = str_contains($bn, $sk);

                if ($hasFallback && $hasSection && str_ends_with($bn, '.json')) {
                    $cands[] = $r;
                }
            }

            // deterministic pick: prefer exact-ish names
            $score = function (string $r) use ($secKey): int {
                $bn = strtolower(basename($r));
                $sk = strtolower($secKey);
                $s = 0;
                if ($bn === "fallback_cards_{$sk}.json") $s += 100;
                if ($bn === "report_fallback_cards_{$sk}.json") $s += 95;
                if ($bn === "report_cards_fallback_{$sk}.json") $s += 90;
                if (str_contains($bn, "fallback_cards_{$sk}")) $s += 80;
                if (str_contains($bn, "{$sk}_fallback")) $s += 60;
                if (str_contains($bn, "fallback_{$sk}")) $s += 60;
                // shorter filename slightly preferred
                $s += max(0, 30 - strlen($bn));
                return $s;
            };

            if (count($cands) === 1) {
                $rel = $cands[0];
            } elseif (count($cands) > 1) {
                usort($cands, fn($a,$b) => $score($b) <=> $score($a));
                $rel = $cands[0];
            }
        }

        if (!$rel) {
            $errors[] = "pack={$packId} section={$secKey} :: fallback file not found in manifest.assets (need a declared json containing 'fallback' + section name, or set policies.items.{$secKey}.fallback_file)";
            continue;
        }

        $abs = $this->pathOf($baseDir, $rel);
        if (!is_file($abs)) {
            $errors[] = "pack={$packId} section={$secKey} file={$abs} :: fallback file not found on disk";
            continue;
        }

        $doc = $this->readJsonFile($abs);
        if (!is_array($doc)) {
            $errors[] = "pack={$packId} section={$secKey} file={$abs} :: fallback invalid JSON";
            continue;
        }

        // 2) extract items list
        $list = null;
        if (is_array($doc['items'] ?? null)) $list = $doc['items'];
        else $list = $doc;

        // normalize to list
        if (!is_array($list)) $list = [];
        if ($this->isAssocArray($list)) $list = array_values($list);

        $cnt = 0;
        foreach ($list as $i => $row) {
            if (!is_array($row)) continue;
            $cnt++;
        }

        // 3) coverage rule
        if ($cnt <= 0) {
            $errors[] = "pack={$packId} section={$secKey} file={$abs} :: fallback has 0 items (must be >0)";
            continue;
        }

        if ($minCards > 0 && $cnt < $minCards) {
            // ✅ 你要求：不足 min_cards 但 >0 -> warning
            $notes[] = "WARN section={$secKey} fallback_count={$cnt} < min_cards={$minCards} (file=" . basename($abs) . ")";
        } else {
            $notes[] = "OK section={$secKey} fallback_count={$cnt} min_cards={$minCards} (file=" . basename($abs) . ")";
        }
    }

    if (!empty($errors)) {
        return [false, array_merge(["Fallback coverage invalid: " . count($errors)], array_slice($errors, 0, 120))];
    }

    return [true, $notes ?: ["OK (fallback coverage checked)"]];
}

/**
 * 把 manifest.assets 全部 flatten 成 relpath 列表（排除 dir entries）
 */
private function flattenDeclaredAssetRelPaths(array $manifest): array
{
    $assets = $manifest['assets'] ?? null;
    if (!is_array($assets)) return [];

    $out = [];

    foreach ($assets as $assetKey => $paths) {
        // overrides: object(map) with buckets + order
        if ($assetKey === 'overrides' && is_array($paths) && $this->isAssocArray($paths)) {
            foreach ($paths as $bucket => $list) {
                if ($bucket === 'order') continue;
                $list = is_array($list) ? $list : [$list];
                foreach ($list as $rel) {
                    if (!is_string($rel) || trim($rel) === '') continue;
                    if (str_ends_with($rel, '/')) continue;
                    $out[] = $rel;
                }
            }
            continue;
        }

        // normal list
        if (!is_array($paths)) continue;

        foreach ($paths as $rel) {
            if (!is_string($rel) || trim($rel) === '') continue;
            if (str_ends_with($rel, '/')) continue;
            $out[] = $rel;
        }
    }

    // unique
    $out = array_values(array_unique($out));
    return $out;
}

    private function checkQuestions(string $path, string $packId, ?string $expectedSchema = null): array
{
    if (!is_file($path)) {
        return [false, ["pack={$packId} file={$path} :: File not found"]];
    }

    $json = $this->readJsonFile($path);
    if (!is_array($json)) {
        return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];
    }

    $errors = [];

    // ✅ 0) schema field check (so this section can fail too, not only manifest section)
    if ($expectedSchema !== null && trim($expectedSchema) !== '') {
        $schemaErrs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'questions');
        if (is_array($schemaErrs) && !empty($schemaErrs)) {
            $errors = array_merge($errors, $schemaErrs);
        }
    }

    $items = isset($json['items']) ? $json['items'] : $json;
    if (!is_array($items)) {
        $errors[] = "pack={$packId} file={$path} path=$.items :: Invalid items structure (expect array or {items:[]})";
        return [false, array_slice($errors, 0, 120)];
    }

    $items = array_values(array_filter($items, fn ($q) => ($q['is_active'] ?? true) === true));
    usort($items, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

    if (count($items) !== 144) {
        $errors[] = "pack={$packId} file={$path} :: Active questions must be 144, got " . count($items);
    }

    $validDims = ['EI', 'SN', 'TF', 'JP', 'AT'];
    $seenQid = [];
    $orders = [];

    foreach ($items as $i => $q) {
        $qid = $q['question_id'] ?? null;

        if (!$qid) $errors[] = "pack={$packId} file={$path} path=$.items[{$i}].question_id :: missing question_id";
        if ($qid && isset($seenQid[$qid])) $errors[] = "pack={$packId} file={$path} :: duplicate question_id {$qid}";
        if ($qid) $seenQid[$qid] = true;

        $order = $q['order'] ?? null;
        if (!is_int($order) && !is_numeric($order)) $errors[] = "pack={$packId} file={$path} path=$.items[{$i}].order :: invalid order";
        if (is_numeric($order)) $orders[] = (int)$order;

        $dim = $q['dimension'] ?? null;
        if (!in_array($dim, $validDims, true)) $errors[] = "pack={$packId} file={$path} :: invalid dimension {$dim} (qid={$qid})";

        $text = $q['text'] ?? null;
        if (!is_string($text) || trim($text) === '') $errors[] = "pack={$packId} file={$path} :: missing text (qid={$qid})";

        $keyPole = $q['key_pole'] ?? null;
        if (!is_string($keyPole) || $keyPole === '') $errors[] = "pack={$packId} file={$path} :: missing key_pole (qid={$qid})";

        $direction = $q['direction'] ?? null;
        if (!in_array((int)$direction, [1, -1], true)) $errors[] = "pack={$packId} file={$path} :: direction must be 1 or -1 (qid={$qid})";

        $opts = $q['options'] ?? null;
        if (!is_array($opts) || count($opts) < 2) {
            $errors[] = "pack={$packId} file={$path} :: options invalid (qid={$qid})";
            continue;
        }

        $needCodes = ['A','B','C','D','E'];
        $optMap = [];
        foreach ($opts as $o) {
            $c = strtoupper((string)($o['code'] ?? ''));
            if ($c !== '') $optMap[$c] = $o;
        }

        foreach ($needCodes as $c) {
            if (!isset($optMap[$c])) {
                $errors[] = "pack={$packId} file={$path} :: missing option {$c} (qid={$qid})";
                continue;
            }
            $t = $optMap[$c]['text'] ?? null;
            if (!is_string($t) || trim($t) === '') $errors[] = "pack={$packId} file={$path} :: option {$c} missing text (qid={$qid})";
            if (!array_key_exists('score', $optMap[$c]) || !is_numeric($optMap[$c]['score'])) {
                $errors[] = "pack={$packId} file={$path} :: option {$c} missing numeric score (qid={$qid})";
            }
        }
    }

    if (count($orders) > 0) {
        $min = min($orders);
        $max = max($orders);
        if ($min !== 1 || $max !== 144) $errors[] = "pack={$packId} file={$path} :: order range should be 1..144, got {$min}..{$max}";
    }

    if (!empty($errors)) return [false, array_slice($errors, 0, 120)];

    return [true, ['OK (144 active questions, A~E options + scoring fields present)']];
}

    private function checkTypeProfiles(string $path, string $packId, ?string $expectedSchema = null): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

        if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'type_profiles')) {
            return [false, $errs];
        }        

        $items = $json['items'] ?? null;
        if (!is_array($items)) return [false, ["pack={$packId} file={$path} path=$.items :: Invalid items (expect {items:{...}})"]];

        $errors = [];

        $expected = $this->expectedTypeCodes32();
        $keys = array_keys($items);

        foreach ($keys as $k) {
            if (!preg_match('/^(E|I)(S|N)(T|F)(J|P)-(A|T)$/', $k)) {
                $errors[] = "pack={$packId} file={$path} :: Invalid type key format: {$k}";
            }
        }

        $missing = array_values(array_diff($expected, $keys));
        $extra   = array_values(array_diff($keys, $expected));
        if ($missing) $errors[] = "pack={$packId} file={$path} :: Missing types: " . implode(', ', $missing);
        if ($extra)   $errors[] = "pack={$packId} file={$path} :: Extra types: " . implode(', ', $extra);
        if (count($items) !== 32) $errors[] = "pack={$packId} file={$path} :: items count must be 32, got " . count($items);

        foreach ($expected as $code) {
            if (!isset($items[$code]) || !is_array($items[$code])) continue;
            $p = $items[$code];

            if (($p['type_code'] ?? null) !== $code) $errors[] = "pack={$packId} file={$path} :: {$code} type_code mismatch";
            if (!isset($p['type_name']) || !is_string($p['type_name']) || trim($p['type_name']) === '') $errors[] = "pack={$packId} file={$path} :: {$code} missing type_name";
            if (!isset($p['tagline']) || !is_string($p['tagline']) || trim($p['tagline']) === '') $errors[] = "pack={$packId} file={$path} :: {$code} missing tagline";
            if (isset($p['keywords']) && !is_array($p['keywords'])) $errors[] = "pack={$packId} file={$path} :: {$code} keywords must be array";
        }

        if ($errors) return [false, array_slice($errors, 0, 120)];
        return [true, ['OK (32 types, required fields present)']];
    }

private function checkCards(
    string $baseDir,
    array $cardFiles,
    string $packId,
    ?string $expectedSchema = null,
    string $assetLabel = 'cards'
): array {
    $errors = [];

    // id -> "file"
    $seenIds = [];

    $countFiles = 0;
    $countCards = 0;

    // fallback 文件名推断 section（traits/career/growth/relationships）
    $inferSectionFromFilename = function (string $abs): ?string {
        $bn = strtolower(basename($abs));
        foreach (['traits', 'career', 'growth', 'relationships'] as $s) {
            if (str_contains($bn, $s)) return $s;
        }
        return null;
    };

    // fallback 的正文字段兼容：desc/text/body
    $pickDesc = function (array $it): ?string {
        foreach (['desc', 'text', 'body'] as $k) {
            if (isset($it[$k]) && is_string($it[$k]) && trim($it[$k]) !== '') {
                return trim($it[$k]);
            }
        }
        return null;
    };

    foreach ($cardFiles as $i => $rel) {
        if (!is_string($rel) || trim($rel) === '') {
            $errors[] = "pack={$packId} path=$.assets.{$assetLabel}[{$i}] :: must be non-empty string";
            continue;
        }

        $abs = $this->pathOf($baseDir, $rel);
        if (!is_file($abs)) {
            $errors[] = "pack={$packId} file={$abs} :: File not found ({$assetLabel})";
            continue;
        }

        $json = $this->readJsonFile($abs);
        if (!is_array($json)) {
            $errors[] = "pack={$packId} file={$abs} :: Invalid JSON ({$assetLabel})";
            continue;
        }

        // ✅ schema check
        if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $abs, $assetLabel)) {
            $errors = array_merge($errors, $errs);
            continue;
        }

        $items = $json['items'] ?? ($json['cards'] ?? null);
        if (!is_array($items)) {
            $errors[] = "pack={$packId} file={$abs} path=$.items :: Invalid items (expect {items:[...]})";
            continue;
        }

        $countFiles++;

        // 仅对 fallback_cards：允许 section 缺失（从文件名推断）
        $fallbackSection = ($assetLabel === 'fallback_cards') ? $inferSectionFromFilename($abs) : null;

        foreach ($items as $j => $it) {
            if (!is_array($it) || !$this->isAssocArray($it)) {
                $errors[] = "pack={$packId} file={$abs} path=$.items[{$j}] :: card must be object";
                continue;
            }

            $id = $it['id'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                $errors[] = "pack={$packId} file={$abs} path=$.items[{$j}].id :: missing/invalid id";
                continue;
            }
            $id = trim($id);

            // -------------------------
            // ✅ required fields:
            // - cards: section + title + desc
            // - fallback_cards: title + (desc|text|body), section 可缺失（用文件名推断）
            // -------------------------
            $title = $it['title'] ?? null;
            if (!is_string($title) || trim($title) === '') {
                $errors[] = "pack={$packId} file={$abs} path=$.items[{$j}].title :: missing/invalid title (id={$id})";
            }

            if ($assetLabel === 'fallback_cards') {
                // section: optional (fallbackSection)
                $sec = $it['section'] ?? $fallbackSection;
                if (!is_string($sec) || trim($sec) === '') {
                    // 如果文件名也推不出来，就报错（避免默默吞错）
                    $errors[] = "pack={$packId} file={$abs} path=$.items[{$j}].section :: missing/invalid section AND cannot infer from filename (id={$id})";
                }

                // desc: accept desc|text|body
                $desc = $pickDesc($it);
                if ($desc === null) {
                    $errors[] = "pack={$packId} file={$abs} path=$.items[{$j}].desc :: missing/invalid desc (accept desc|text|body) (id={$id})";
                }
            } else {
                // normal cards: strict
                $sec = $it['section'] ?? null;
                if (!is_string($sec) || trim($sec) === '') {
                    $errors[] = "pack={$packId} file={$abs} path=$.items[{$j}].section :: missing/invalid section (id={$id})";
                }

                $desc = $it['desc'] ?? null;
                if (!is_string($desc) || trim($desc) === '') {
                    $errors[] = "pack={$packId} file={$abs} path=$.items[{$j}].desc :: missing/invalid desc (id={$id})";
                }
            }

            // id uniqueness
            if (isset($seenIds[$id])) {
                $errors[] = "pack={$packId} :: Duplicate card id detected: {$id} (file={$abs}, prev={$seenIds[$id]})";
            } else {
                $seenIds[$id] = $abs;
            }

            $countCards++;
        }
    }

    if (!empty($errors)) {
        return [false, array_merge(
            [ucfirst($assetLabel) . " invalid: " . count($errors)],
            array_slice($errors, 0, 120)
        )];
    }

    return [true, ["OK ({$countFiles} {$assetLabel} files, {$countCards} items, ids unique)"]];
}

    private function checkHighlightsTemplates(string $path, string $packId, ?string $expectedSchema = null): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

        if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'highlights')) {
            return [false, $errs];
        }

        $tpl = $json['templates'] ?? null;
        if (!is_array($tpl)) return [false, ["pack={$packId} file={$path} path=$.templates :: Missing/invalid templates"]];

        $dims = ['EI','SN','TF','JP','AT'];
        $sides = [
            'EI' => ['E','I'],
            'SN' => ['S','N'],
            'TF' => ['T','F'],
            'JP' => ['J','P'],
            'AT' => ['A','T'],
        ];
        $lvls = ['clear','strong','very_strong'];

        $missing = [];
        $bad = [];

        foreach ($dims as $d) {
            foreach ($sides[$d] as $s) {
                foreach ($lvls as $l) {
                    $cell = $tpl[$d][$s][$l] ?? null;
                    if (!is_array($cell)) { $missing[] = "{$d}.{$s}.{$l}"; continue; }

                    $title = $cell['title'] ?? null;
                    $text  = $cell['text'] ?? null;
                    $tips  = $cell['tips'] ?? null;
                    $tags  = $cell['tags'] ?? null;

                    if (!is_string($title) || trim($title) === '' ||
                        !is_string($text)  || trim($text)  === '' ||
                        !is_array($tips) ||
                        !is_array($tags)
                    ) {
                        $bad[] = "{$d}.{$s}.{$l}";
                    }
                }
            }
        }

        $errors = [];
        if ($missing) {
            $errors[] = "pack={$packId} file={$path} :: Missing cells: " . count($missing);
            $errors[] = "e.g. " . implode(', ', array_slice($missing, 0, 16));
        }
        if ($bad) {
            $errors[] = "pack={$packId} file={$path} :: Bad cells (missing required fields title/text/tips/tags): " . count($bad);
            $errors[] = "e.g. " . implode(', ', array_slice($bad, 0, 16));
        }

        if ($errors) return [false, $errors];
        return [true, ['OK (5 dims × 2 sides × 3 levels present & valid)']];
    }

    // -------------------------
// Highlights pools / rules  ✅ NEW
// -------------------------

private function checkHighlightsPools(string $path, string $packId, ?string $expectedSchema = null): array
{
    if (!is_file($path)) {
        return [false, ["pack={$packId} file={$path} :: File not found"]];
    }

    $doc = $this->readJsonFile($path);
    if (!is_array($doc)) {
        return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];
    }

    if ($errs = $this->checkSchemaField($doc, $expectedSchema, $packId, $path, 'highlights_pools')) {
        return [false, $errs];
    }

    $errors = [];
    [$poolKeys, $countsByPool, $templateIds, $allIds] = $this->parseHighlightsPoolsDoc($doc, $packId, $path, $errors);

    if (!empty($errors)) {
        return [false, array_merge(["Highlights pools invalid: " . count($errors)], array_slice($errors, 0, 140))];
    }

    // ✅ 三池基本要求：strength + 另外两池都存在且非空（你可按产品收紧）
    if (!in_array('strength', $poolKeys, true)) {
        return [false, ["pack={$packId} file={$path} :: missing pool 'strength'"]];
    }
    if (count($poolKeys) < 3) {
        return [false, ["pack={$packId} file={$path} :: pools must have at least 3 pools, got=" . count($poolKeys)]];
    }

    return [true, [
        "OK (pools=" . count($poolKeys) . ", template_ids=" . count($templateIds) . ", ids=" . count($allIds) . ")",
        "pool_keys=" . implode(',', $poolKeys),
        "counts=" . json_encode($countsByPool, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    ]];
}

/**
 * report_highlights_rules.json
 * - 合法
 * - 引用的模板 id 必须存在（来自 report_highlights_pools.json）
 * - policy 合法（min/max 关系、总数约束合理）
 * - 覆盖率基本校验（三池至少都有足够模板用于 fallback）
 */
private function checkHighlightsRules(string $path, string $baseDir, string $packId, ?string $expectedSchema = null): array
{
    if (!is_file($path)) {
        return [false, ["pack={$packId} file={$path} :: File not found"]];
    }

    $doc = $this->readJsonFile($path);
    if (!is_array($doc)) {
        return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];
    }

    if ($errs = $this->checkSchemaField($doc, $expectedSchema, $packId, $path, 'highlights_rules')) {
        return [false, $errs];
    }

    // ✅ 读取 pools（rules 必须能引用它）
    $poolsPath = $this->pathOf($baseDir, 'report_highlights_pools.json');
    if (!is_file($poolsPath)) {
        return [false, ["pack={$packId} file={$path} :: report_highlights_pools.json not found (required for rules validation): {$poolsPath}"]];
    }
    $poolsDoc = $this->readJsonFile($poolsPath);
    if (!is_array($poolsDoc)) {
        return [false, ["pack={$packId} file={$poolsPath} :: report_highlights_pools.json invalid JSON"]];
    }

    $errors = [];

    [$poolKeys, $countsByPool, $templateIds, $_allIds] = $this->parseHighlightsPoolsDoc($poolsDoc, $packId, $poolsPath, $errors);

    // rules list
    $rules = $doc['rules'] ?? ($doc['items'] ?? null);
    if (!is_array($rules)) {
        return [false, ["pack={$packId} file={$path} path=$.rules/$.items :: missing/invalid rules list (expect array(list))"]];
    }
    if ($this->isAssocArray($rules)) {
        return [false, ["pack={$packId} file={$path} path=$.rules/$.items :: rules must be array(list), not object(map)"]];
    }

    // policy object（允许放在 root.policy 或 root._meta.policy）
    $policy = $doc['policy'] ?? ($doc['_meta']['policy'] ?? null);
    if (!is_array($policy) || !$this->isAssocArray($policy)) {
        $errors[] = "pack={$packId} file={$path} path=$.policy :: missing/invalid policy object";
        $policy = [];
    }

    // ✅ policy 合法性（min/max、总数约束）
    $this->validateHighlightsPolicy($policy, $countsByPool, $packId, $path, $errors);

    // ✅ rule.id 唯一 + pool key 合法 + 引用模板 id 必须存在
    $seenRuleIds = [];
    $refIds = [];

    foreach ($rules as $i => $r) {
        $base = "$.rules[$i]";

        if (!is_array($r) || !$this->isAssocArray($r)) {
            $errors[] = "pack={$packId} file={$path} path={$base} :: rule must be object(map)";
            continue;
        }

        $rid = (string)($r['id'] ?? '');
        if ($rid === '') {
            $errors[] = "pack={$packId} file={$path} path={$base}.id :: missing rule id";
        } else {
            if (isset($seenRuleIds[$rid])) {
                $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.id :: duplicate rule.id (first at {$seenRuleIds[$rid]})";
            } else {
                $seenRuleIds[$rid] = $base;
            }
        }

        // pool key (optional but recommended)
        if (isset($r['pool'])) {
            $pk = (string)$r['pool'];
            if ($pk === '' || !in_array($pk, $poolKeys, true)) {
                $errors[] = "pack={$packId} file={$path} rule_id={$rid} path={$base}.pool :: invalid pool '{$pk}' (known pools: " . implode(',', $poolKeys) . ")";
            }
        }

        // collect referenced template ids
        $this->collectTemplateRefsFromRules($r, $refIds);
    }

    // ✅ 引用模板 id 必须存在
    $refIds = array_values(array_unique(array_filter($refIds, fn($x) => is_string($x) && trim($x) !== '')));
    if (!empty($refIds)) {
        $missing = [];
        foreach ($refIds as $tid) {
            if (!isset($templateIds[$tid])) $missing[] = $tid;
        }
        if (!empty($missing)) {
            $errors[] = "pack={$packId} file={$path} :: referenced template ids not found in pools: " . count($missing)
                . " (e.g. " . implode(', ', array_slice($missing, 0, 16)) . ")";
        }
    }

    // ✅ 覆盖率基本校验：至少确保三池都有足够模板用来 fallback
    // 默认：每池至少 1；且 max_total 不超过三池总模板数
    $this->validateHighlightsCoverage($policy, $countsByPool, $packId, $path, $errors);

    if (!empty($errors)) {
        return [false, array_merge(["Highlights rules invalid: " . count($errors)], array_slice($errors, 0, 160))];
    }

    return [true, [
        "OK (rules=" . count($rules) . ", pools=" . count($poolKeys) . ", template_ids=" . count($templateIds) . ")",
        "policy=" . json_encode($policy, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    ]];
}

/**
 * 解析 report_highlights_pools.json 的 pools 结构
 * 兼容：
 * - {schema, pools:{strength:[...], weakness:[...], growth:[...]}}
 * - {schema, items:{strength:{items:[...]}, ...}}
 * - {schema, items:{strength:[...], ...}}
 */
private function parseHighlightsPoolsDoc(array $doc, string $packId, string $path, array &$errors): array
{
    $root = null;

    if (isset($doc['pools']) && is_array($doc['pools'])) $root = $doc['pools'];
    elseif (isset($doc['items']) && is_array($doc['items'])) $root = $doc['items'];
    else $root = $doc;

    if (!is_array($root) || !$this->isAssocArray($root)) {
        $errors[] = "pack={$packId} file={$path} path=$.pools/$.items :: pools must be object(map of pool_key => list/items)";
        return [[], [], [], []];
    }

    $poolKeys = array_keys($root);

    // normalize: pool_key => list[object]
    $countsByPool = [];
    $templateIds = []; // set
    $allIds = [];      // set (id uniqueness across whole pools)
    $seenIdAt = [];

    $takeTplId = function(array $it): string {
        foreach (['template_id','tpl_id','tpl','template','id'] as $k) {
            if (isset($it[$k]) && is_string($it[$k]) && trim($it[$k]) !== '') return trim($it[$k]);
        }
        return '';
    };

    foreach ($root as $poolKey => $node) {
        if (!is_string($poolKey) || trim($poolKey) === '') {
            $errors[] = "pack={$packId} file={$path} path=$.pools :: pool key must be non-empty string";
            continue;
        }
        $poolKey = trim($poolKey);

        // allow {items:[...]} wrapper
        $list = null;
        if (is_array($node) && $this->isAssocArray($node) && isset($node['items']) && is_array($node['items'])) {
            $list = $node['items'];
        } else {
            $list = $node;
        }

        if (!is_array($list)) {
            $errors[] = "pack={$packId} file={$path} path=$.pools.{$poolKey} :: pool must be array(list) or {items:[...]}";
            $countsByPool[$poolKey] = 0;
            continue;
        }
        if ($this->isAssocArray($list)) {
            // allow map -> take values
            $list = array_values($list);
        }

        $cnt = 0;
        foreach ($list as $i => $it) {
            if (!is_array($it) || !$this->isAssocArray($it)) {
                $errors[] = "pack={$packId} file={$path} path=$.pools.{$poolKey}[{$i}] :: item must be object(map)";
                continue;
            }

            $id = $it['id'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                $errors[] = "pack={$packId} file={$path} path=$.pools.{$poolKey}[{$i}].id :: missing/invalid id";
                continue;
            }
            $id = trim($id);

            if (isset($allIds[$id])) {
                $errors[] = "pack={$packId} file={$path} :: duplicate highlight id '{$id}' (first at {$seenIdAt[$id]}, again at $.pools.{$poolKey}[{$i}])";
            } else {
                $allIds[$id] = true;
                $seenIdAt[$id] = "$.pools.{$poolKey}[{$i}]";
            }

            $tplId = $takeTplId($it);
            if ($tplId === '') {
                $errors[] = "pack={$packId} file={$path} path=$.pools.{$poolKey}[{$i}] :: missing template_id (accept template_id/tpl_id/tpl/template/id)";
            } else {
                $templateIds[$tplId] = true;
            }

            $cnt++;
        }

        $countsByPool[$poolKey] = $cnt;
    }

    return [$poolKeys, $countsByPool, $templateIds, $allIds];
}

/**
 * 从 rules 节点里递归收集模板引用 id
 * 兼容字段：template_id / template_ids / templates / tpl_id / tpl_ids / tpl
 */
private function collectTemplateRefsFromRules($node, array &$out): void
{
    if (!is_array($node)) return;

    foreach ($node as $k => $v) {
        $key = is_string($k) ? strtolower($k) : '';

        if (in_array($key, ['template_id','tpl_id','tpl','template'], true)) {
            if (is_string($v) && trim($v) !== '') $out[] = trim($v);
        } elseif (in_array($key, ['template_ids','templates','tpl_ids'], true)) {
            if (is_array($v)) {
                foreach ($v as $vv) {
                    if (is_string($vv) && trim($vv) !== '') $out[] = trim($vv);
                }
            }
        } else {
            if (is_array($v)) $this->collectTemplateRefsFromRules($v, $out);
        }
    }
}

private function validateHighlightsPolicy(array $policy, array $countsByPool, string $packId, string $path, array &$errors): void
{
    $intVal = function($x): ?int {
        if (is_int($x)) return $x;
        if (is_numeric($x) && (int)$x == $x) return (int)$x;
        return null;
    };

    $minTotal = $intVal($policy['min_total'] ?? null);
    $maxTotal = $intVal($policy['max_total'] ?? null);
    $maxStrength = $intVal($policy['max_strength'] ?? null);

    if ($minTotal === null) $errors[] = "pack={$packId} file={$path} path=$.policy.min_total :: must be integer";
    if ($maxTotal === null) $errors[] = "pack={$packId} file={$path} path=$.policy.max_total :: must be integer";
    if ($maxStrength === null) $errors[] = "pack={$packId} file={$path} path=$.policy.max_strength :: must be integer";

    if ($minTotal !== null && $minTotal < 0) $errors[] = "pack={$packId} file={$path} path=$.policy.min_total :: must be >= 0";
    if ($maxTotal !== null && $maxTotal < 0) $errors[] = "pack={$packId} file={$path} path=$.policy.max_total :: must be >= 0";

    if ($minTotal !== null && $maxTotal !== null && $minTotal > $maxTotal) {
        $errors[] = "pack={$packId} file={$path} :: policy invalid: min_total({$minTotal}) > max_total({$maxTotal})";
    }

    if ($maxStrength !== null && $maxTotal !== null && $maxStrength > $maxTotal) {
        $errors[] = "pack={$packId} file={$path} :: policy invalid: max_strength({$maxStrength}) > max_total({$maxTotal})";
    }

    // 可选：min_strength
    if (array_key_exists('min_strength', $policy)) {
        $minStrength = $intVal($policy['min_strength']);
        if ($minStrength === null) {
            $errors[] = "pack={$packId} file={$path} path=$.policy.min_strength :: must be integer if present";
        } elseif ($maxStrength !== null && $minStrength > $maxStrength) {
            $errors[] = "pack={$packId} file={$path} :: policy invalid: min_strength({$minStrength}) > max_strength({$maxStrength})";
        }
    }

    // ✅ 总数约束合理：max_total 不应超过 pools 总模板数（粗校验）
    $totalTemplates = 0;
    foreach ($countsByPool as $k => $n) $totalTemplates += (int)$n;

    if ($maxTotal !== null && $totalTemplates > 0 && $maxTotal > $totalTemplates) {
        $errors[] = "pack={$packId} file={$path} :: policy invalid: max_total({$maxTotal}) > total_templates_in_pools({$totalTemplates})";
    }
}

private function validateHighlightsCoverage(array $policy, array $countsByPool, string $packId, string $path, array &$errors): void
{
    // ✅ 三池至少有模板（fallback 基本保证）
    $nonEmptyPools = 0;
    foreach ($countsByPool as $k => $n) {
        if ((int)$n > 0) $nonEmptyPools++;
    }
    if ($nonEmptyPools < 3) {
        $errors[] = "pack={$packId} file={$path} :: coverage invalid: need >=3 non-empty pools for fallback, got={$nonEmptyPools}";
    }

    // ✅ strength pool 必须有模板（因为运行时有 strength.count 限制）
    if (isset($countsByPool['strength']) && (int)$countsByPool['strength'] <= 0) {
        $errors[] = "pack={$packId} file={$path} :: coverage invalid: pool 'strength' has 0 templates";
    }

    // ✅ 若 policy.max_total 存在：要求 pools 总模板数 >= max_total（更直观）
    $maxTotal = null;
    if (isset($policy['max_total']) && (is_int($policy['max_total']) || (is_numeric($policy['max_total']) && (int)$policy['max_total'] == $policy['max_total']))) {
        $maxTotal = (int)$policy['max_total'];
    }
    if ($maxTotal !== null) {
        $sum = 0;
        foreach ($countsByPool as $k => $n) $sum += (int)$n;
        if ($sum < $maxTotal) {
            $errors[] = "pack={$packId} file={$path} :: coverage invalid: sum(pool_templates)={$sum} < policy.max_total={$maxTotal}";
        }
    }
}

    private function checkHighlightsOverrides(string $path, string $packId, ?string $expectedSchema = null): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

        if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'overrides_highlights_legacy')) {
            return [false, $errs];
        }

        $items = $json['items'] ?? null;
        if (!is_array($items)) return [false, ["pack={$packId} file={$path} path=$.items :: Missing/invalid items"]];

        $dupTypes = [];

        foreach ($items as $typeCode => $node) {
            if (!is_array($node)) continue;

            $ids = [];
            $walk = function ($x) use (&$walk, &$ids) {
                if (!is_array($x)) return;
                if (array_key_exists('id', $x) && is_string($x['id']) && $x['id'] !== '') $ids[] = $x['id'];
                foreach ($x as $v) if (is_array($v)) $walk($v);
            };
            $walk($node);

            if (!$ids) continue;

            $count = [];
            foreach ($ids as $id) $count[$id] = ($count[$id] ?? 0) + 1;

            $dups = [];
            foreach ($count as $id => $c) if ($c > 1) $dups[] = "{$id}×{$c}";
            if ($dups) $dupTypes[] = "{$typeCode}: " . implode(', ', $dups);
        }

        if ($dupTypes) {
            return [false, [
                "pack={$packId} file={$path} :: Duplicate ids detected (per-type): " . count($dupTypes),
                "e.g. " . implode(' | ', array_slice($dupTypes, 0, 6)),
            ]];
        }

        return [true, ['OK (items valid, no per-type duplicate ids)']];
    }

    private function checkBorderlineTemplates(string $path, string $packId, ?string $expectedSchema = null): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

        if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'borderline_templates')) {
            return [false, $errs];
        }

        $items = $json['items'] ?? null;
        if (!is_array($items)) return [false, ["pack={$packId} file={$path} path=$.items :: Missing/invalid items"]];

        $dims = ['EI','SN','TF','JP','AT'];
        $errors = [];

        foreach ($dims as $dim) {
            $t = $items[$dim] ?? null;
            if (!is_array($t)) { $errors[] = "pack={$packId} file={$path} path=$.items.{$dim} :: missing/invalid"; continue; }

            $title = $t['title'] ?? null;
            $text  = $t['text'] ?? null;

            if (!is_string($title) || trim($title) === '') $errors[] = "pack={$packId} file={$path} path=$.items.{$dim}.title :: must be non-empty string";
            if (!is_string($text)  || trim($text)  === '') $errors[] = "pack={$packId} file={$path} path=$.items.{$dim}.text :: must be non-empty string";

            if (!array_key_exists('examples', $t) || !is_array($t['examples'])) $errors[] = "pack={$packId} file={$path} path=$.items.{$dim}.examples :: must be array";
            if (!array_key_exists('suggestions', $t) || !is_array($t['suggestions'])) $errors[] = "pack={$packId} file={$path} path=$.items.{$dim}.suggestions :: must be array";
        }

        if ($errors) return [false, array_merge(["Borderline templates invalid: " . count($errors)], array_slice($errors, 0, 120))];
        return [true, ['OK (5 dims present & valid)']];
    }

    private function checkReportRoles(string $path, string $packId, ?string $expectedSchema = null): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

        if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'roles')) {
            return [false, $errs];
        }

        $items = $json['items'] ?? null;
        if (!is_array($items)) return [false, ["pack={$packId} file={$path} path=$.items :: Missing/invalid items"]];

        $expected = ['NT','NF','SJ','SP'];
        $errors = [];

        foreach ($expected as $k) {
            $it = $items[$k] ?? null;
            if (!is_array($it)) { $errors[] = "pack={$packId} file={$path} :: Missing item {$k}"; continue; }
            if (($it['code'] ?? null) !== $k) $errors[] = "pack={$packId} file={$path} :: {$k} code mismatch";

            foreach (['title','subtitle','desc'] as $f) {
                if (!isset($it[$f]) || !is_string($it[$f]) || trim($it[$f]) === '') $errors[] = "pack={$packId} file={$path} :: {$k} missing {$f}";
            }

            $theme = $it['theme'] ?? null;
            if (!is_array($theme) || !isset($theme['color']) || !is_string($theme['color']) || trim($theme['color']) === '') {
                $errors[] = "pack={$packId} file={$path} :: {$k} theme.color missing";
            }

            if (isset($it['tags']) && !is_array($it['tags'])) $errors[] = "pack={$packId} file={$path} :: {$k} tags must be array";
        }

        if ($errors) return [false, array_slice($errors, 0, 120)];
        return [true, ['OK (NT/NF/SJ/SP present & valid)']];
    }

    private function checkReportStrategies(string $path, string $packId, ?string $expectedSchema = null): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

        if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'strategies')) {
            return [false, $errs];
        }

        $items = $json['items'] ?? null;
        if (!is_array($items)) return [false, ["pack={$packId} file={$path} path=$.items :: Missing/invalid items"]];

        $expected = ['EA','ET','IA','IT'];
        $errors = [];

        foreach ($expected as $k) {
            $it = $items[$k] ?? null;
            if (!is_array($it)) { $errors[] = "pack={$packId} file={$path} :: Missing item {$k}"; continue; }
            if (($it['code'] ?? null) !== $k) $errors[] = "pack={$packId} file={$path} :: {$k} code mismatch";

            foreach (['title','subtitle','desc'] as $f) {
                if (!isset($it[$f]) || !is_string($it[$f]) || trim($it[$f]) === '') $errors[] = "pack={$packId} file={$path} :: {$k} missing {$f}";
            }

            if (isset($it['tags']) && !is_array($it['tags'])) $errors[] = "pack={$packId} file={$path} :: {$k} tags must be array";
        }

        if ($errors) return [false, array_slice($errors, 0, 120)];
        return [true, ['OK (EA/ET/IA/IT present & valid)']];
    }

    private function checkRecommendedReads(string $path, string $packId, ?string $expectedSchema = null): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

        if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'reads')) {
            return [false, $errs];
        }

        $items = $json['items'] ?? null;
        if (!is_array($items)) return [false, ["pack={$packId} file={$path} path=$.items :: Missing/invalid items"]];

        $requiredBuckets = ['by_type', 'by_role', 'by_strategy', 'by_top_axis', 'fallback'];
        $errors = [];

        foreach ($requiredBuckets as $k) {
            if (!array_key_exists($k, $items)) {
                $errors[] = "pack={$packId} file={$path} path=$.items.{$k} :: missing bucket";
                continue;
            }
            if ($k === 'fallback') {
                if (!is_array($items[$k])) $errors[] = "pack={$packId} file={$path} path=$.items.{$k} :: must be array(list)";
            } else {
                if (!is_array($items[$k])) $errors[] = "pack={$packId} file={$path} path=$.items.{$k} :: must be object(map)";
            }
        }

        if ($errors) {
            return [false, array_merge(["Recommended reads structure invalid: " . count($errors)], array_slice($errors, 0, 120))];
        }

        $seenIds = [];
        $dupIds  = [];
        $rowErrors = [];

        $validateRead = function (array $it, string $where) use (&$seenIds, &$dupIds, &$rowErrors, $packId, $path) {
            $reqStr = ['id','type','title','desc','url'];
            foreach ($reqStr as $f) {
                if (!isset($it[$f]) || !is_string($it[$f]) || trim($it[$f]) === '') {
                    $rowErrors[] = "pack={$packId} file={$path} {$where} :: missing/invalid {$f}";
                }
            }

            if (!array_key_exists('priority', $it) || !is_numeric($it['priority'])) {
                $rowErrors[] = "pack={$packId} file={$path} {$where} :: missing/invalid priority (number)";
            }
            if (!array_key_exists('tags', $it) || !is_array($it['tags'])) {
                $rowErrors[] = "pack={$packId} file={$path} {$where} :: missing/invalid tags (array)";
            }
            if (array_key_exists('cover', $it) && !is_string($it['cover'])) {
                $rowErrors[] = "pack={$packId} file={$path} {$where} :: cover must be string if present";
            }

            $id = (string)($it['id'] ?? '');
            if ($id !== '') {
                if (isset($seenIds[$id])) $dupIds[$id] = ($dupIds[$id] ?? 1) + 1;
                else $seenIds[$id] = true;
            }
        };

        foreach (($items['by_type'] ?? []) as $typeCode => $list) {
            if (!is_array($list)) { $rowErrors[] = "pack={$packId} file={$path} path=$.items.by_type.{$typeCode} :: must be array(list)"; continue; }
            foreach ($list as $i => $it) if (is_array($it)) $validateRead($it, "path=$.items.by_type.{$typeCode}[{$i}]"); else $rowErrors[] = "pack={$packId} file={$path} path=$.items.by_type.{$typeCode}[{$i}] :: must be object";
        }
        foreach (($items['by_role'] ?? []) as $role => $list) {
            if (!is_array($list)) { $rowErrors[] = "pack={$packId} file={$path} path=$.items.by_role.{$role} :: must be array(list)"; continue; }
            foreach ($list as $i => $it) if (is_array($it)) $validateRead($it, "path=$.items.by_role.{$role}[{$i}]"); else $rowErrors[] = "pack={$packId} file={$path} path=$.items.by_role.{$role}[{$i}] :: must be object";
        }
        foreach (($items['by_strategy'] ?? []) as $st => $list) {
            if (!is_array($list)) { $rowErrors[] = "pack={$packId} file={$path} path=$.items.by_strategy.{$st} :: must be array(list)"; continue; }
            foreach ($list as $i => $it) if (is_array($it)) $validateRead($it, "path=$.items.by_strategy.{$st}[{$i}]"); else $rowErrors[] = "pack={$packId} file={$path} path=$.items.by_strategy.{$st}[{$i}] :: must be object";
        }
        foreach (($items['by_top_axis'] ?? []) as $axisKey => $list) {
            if (!is_array($list)) { $rowErrors[] = "pack={$packId} file={$path} path=$.items.by_top_axis.{$axisKey} :: must be array(list)"; continue; }
            foreach ($list as $i => $it) if (is_array($it)) $validateRead($it, "path=$.items.by_top_axis.{$axisKey}[{$i}]"); else $rowErrors[] = "pack={$packId} file={$path} path=$.items.by_top_axis.{$axisKey}[{$i}] :: must be object";
        }
        foreach (($items['fallback'] ?? []) as $i => $it) {
            if (is_array($it)) $validateRead($it, "path=$.items.fallback[{$i}]");
            else $rowErrors[] = "pack={$packId} file={$path} path=$.items.fallback[{$i}] :: must be object";
        }

        if ($dupIds) {
            $pairs = [];
            foreach ($dupIds as $id => $n) $pairs[] = "{$id}×{$n}";
            $rowErrors[] = "pack={$packId} file={$path} :: Duplicate read ids detected: " . count($dupIds) . " (e.g. " . implode(', ', array_slice($pairs, 0, 12)) . ")";
        }

        if ($rowErrors) {
            return [false, array_merge(["Recommended reads invalid: " . count($rowErrors)], array_slice($rowErrors, 0, 120))];
        }

        return [true, ['OK (structure valid, required fields present, ids unique)']];
    }

    private function checkIdentityCards(string $path, string $packId, ?string $expectedSchema = null): array
{
    if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

    $json = $this->readJsonFile($path);
    if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

    if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'identity_cards')) {
        return [false, $errs];
    }

    // items can be list OR object(map)
$items = $json['items'] ?? null;
if (!is_array($items) || $items === []) {
    return [false, ["pack={$packId} file={$path} path=$.items :: must be array(list) or object(map)"]];
}

// list vs map
$isList = array_keys($items) === range(0, count($items) - 1);

$errors = [];
$seenIds = [];
$count = 0;

$takeId = function ($key, $node) {
    // map: id defaults to key; list: key is numeric index, so must rely on node.id
    if (is_array($node) && isset($node['id']) && is_string($node['id']) && trim($node['id']) !== '') {
        return trim($node['id']);
    }
    if (is_string($key) && trim($key) !== '') return trim($key);
    return '';
};

if ($isList) {
    foreach ($items as $i => $node) {
        if (!is_array($node) || !$this->isAssocArray($node)) {
            $errors[] = "pack={$packId} file={$path} path=$.items[{$i}] :: identity card must be object";
            continue;
        }
        $id = $takeId((string)$i, $node);
        if ($id === '') {
            $errors[] = "pack={$packId} file={$path} path=$.items[{$i}].id :: missing/invalid id";
            continue;
        }
        if (isset($seenIds[$id])) {
            $errors[] = "pack={$packId} file={$path} :: Duplicate identity_card id detected: {$id} (prev={$seenIds[$id]}, cur=$.items[{$i}])";
        } else {
            $seenIds[$id] = "$.items[{$i}]";
        }
        $count++;
    }
} else {
    // object(map): items = { "ENFJ-A": {...}, ... }
    foreach ($items as $k => $node) {
        if (!is_string($k) || trim($k) === '') {
            $errors[] = "pack={$packId} file={$path} path=$.items :: map key must be non-empty string";
            continue;
        }
        if (!is_array($node) || !$this->isAssocArray($node)) {
            $errors[] = "pack={$packId} file={$path} path=$.items.{$k} :: identity card must be object";
            continue;
        }
        $id = $takeId($k, $node); // ✅ dict 时优先 node.id，否则用 key
        if ($id === '') {
            $errors[] = "pack={$packId} file={$path} path=$.items.{$k} :: missing/invalid id (need node.id or non-empty key)";
            continue;
        }
        if (isset($seenIds[$id])) {
            $errors[] = "pack={$packId} file={$path} :: Duplicate identity_card id detected: {$id} (prev={$seenIds[$id]}, cur=$.items.{$k})";
        } else {
            $seenIds[$id] = "$.items.{$k}";
        }
        $count++;
    }
}

if (!empty($errors)) {
    return [false, array_merge(
        ["Identity cards invalid: " . count($errors)],
        array_slice($errors, 0, 120)
    )];
}

return [true, ["OK (identity_cards ids unique; count={$count})"]];
}

private function checkIdentityLayers(string $path, string $packId, ?string $expectedSchema = null): array
{
    if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

    $json = $this->readJsonFile($path);
    if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

    if ($errs = $this->checkSchemaField($json, $expectedSchema, $packId, $path, 'identity_layers')) {
        return [false, $errs];
    }

    $items = $json['items'] ?? ($json['layers'] ?? null);
    if (!is_array($items)) {
        return [false, ["pack={$packId} file={$path} path=$.items :: Invalid items (expect {items:[...]})"]];
    }

    // 允许 list 或 map：如果是 map，用 key 当 id（也顺便验证 value.id 一致性）
    $seen = [];
    $errs = [];

    if ($this->isAssocArray($items)) {
        foreach ($items as $k => $it) {
            if (!is_array($it) || !$this->isAssocArray($it)) {
                $errs[] = "pack={$packId} file={$path} path=$.items.{$k} :: item must be object";
                continue;
            }
            $id = $it['id'] ?? (string)$k;
            if (!is_string($id) || trim($id) === '') {
                $errs[] = "pack={$packId} file={$path} path=$.items.{$k}.id :: missing/invalid id";
                continue;
            }
            if (isset($seen[$id])) {
                $errs[] = "pack={$packId} file={$path} :: Duplicate identity_layer id detected: {$id} (first at {$seen[$id]})";
            } else {
                $seen[$id] = "$.items.{$k}";
            }
        }
        if ($errs) return [false, array_merge(["Identity layers invalid: " . count($errs)], array_slice($errs, 0, 120))];
        return [true, ["OK (identity_layers ids unique; count=" . count($items) . ")"]];
    }

    // list
    foreach ($items as $i => $it) {
        if (!is_array($it) || !$this->isAssocArray($it)) {
            $errs[] = "pack={$packId} file={$path} path=$.items[{$i}] :: item must be object";
            continue;
        }
        $id = $it['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            $errs[] = "pack={$packId} file={$path} path=$.items[{$i}].id :: missing/invalid id";
            continue;
        }
        if (isset($seen[$id])) {
            $errs[] = "pack={$packId} file={$path} :: Duplicate identity_layer id detected: {$id} (first at {$seen[$id]})";
        } else {
            $seen[$id] = "$.items[{$i}]";
        }
    }

    if ($errs) return [false, array_merge(["Identity layers invalid: " . count($errs)], array_slice($errs, 0, 120))];
    return [true, ["OK (identity_layers ids unique; count=" . count($items) . ")"]];
}

    // -------------------------
    // Helpers
    // -------------------------

    private function readJsonFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return null;
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    private function isAssocArray(array $a): bool
    {
        if ($a === []) return false;
        return array_keys($a) !== range(0, count($a) - 1);
    }

    private function checkSchemaField(array $doc, ?string $expectedSchema, string $packId, string $path, string $assetLabel): ?array
{
    if ($expectedSchema === null || trim($expectedSchema) === '') return null;

    $got = $doc['schema'] ?? null;

    if ($got === null || $got === '') {
        return [
            "pack={$packId} file={$path} path=$.schema :: missing schema field (asset={$assetLabel}) want=" . var_export($expectedSchema, true)
        ];
    }

    if ($got !== $expectedSchema) {
        return [
            "pack={$packId} file={$path} path=$.schema :: schema mismatch got=" . var_export($got, true)
            . " want=" . var_export($expectedSchema, true)
            . " (asset={$assetLabel})"
        ];
    }

    return null;
}

    private function declaredAssetBasenames(array $manifest): array
    {
        $out = [];
        $assets = $manifest['assets'] ?? null;
        if (!is_array($assets)) return $out;

        foreach ($assets as $assetKey => $paths) {
            if (!is_array($paths)) continue;

            if ($assetKey === 'overrides' && $this->isAssocArray($paths)) {
                foreach ($paths as $k => $v) {
                    if ($k === 'order') continue;
                    $list = is_array($v) ? $v : [$v];
                    foreach ($list as $rel) {
                        if (!is_string($rel) || trim($rel) === '') continue;
                        $out[basename($rel)] = true;
                    }
                }
                continue;
            }

            foreach ($paths as $rel) {
                if (!is_string($rel) || trim($rel) === '') continue;
                $out[basename($rel)] = true;
            }
        }

        return $out;
    }

    private function checkStrictAssets(string $baseDir, array $declaredBasenames, string $packId): array
{
    // files that, if present on disk, must be declared in manifest.assets (when strict-assets is enabled)
    $known = [
        'report_highlights_overrides.json',
        'identity_cards.json',
        'report_identity_layers.json',
    ];

    $errors = [];
    $notes  = [];

    foreach ($known as $fname) {
        $abs = $this->pathOf($baseDir, $fname);
        if (!is_file($abs)) continue;

        if (!isset($declaredBasenames[$fname])) {
            $errors[] = "pack={$packId} file={$abs} :: exists on disk but NOT declared in manifest.assets (strict-assets)";
        } else {
            $notes[] = "OK declared: {$fname}";
        }
    }

    if (!empty($errors)) return [false, array_merge(["Undeclared known files found: " . count($errors)], $errors)];
    return [true, $notes ?: ["OK (no undeclared known files found)"]];
}

    private function expectedTypeCodes32(): array
    {
        $first = ['E', 'I'];
        $second = ['S', 'N'];
        $third = ['T', 'F'];
        $fourth = ['J', 'P'];
        $suffix = ['A', 'T'];

        $out = [];
        foreach ($first as $a) {
            foreach ($second as $b) {
                foreach ($third as $c) {
                    foreach ($fourth as $d) {
                        foreach ($suffix as $s) {
                            $out[] = "{$a}{$b}{$c}{$d}-{$s}";
                        }
                    }
                }
            }
        }

        sort($out);
        return $out;
    }

    private function printSectionResult(string $name, bool $ok, array $messages): void
    {
        if ($ok) {
            $this->info("✅ {$name}");
            foreach ($messages as $m) $this->line("  - {$m}");
            return;
        }

        $this->error("❌ {$name}");
        foreach ($messages as $m) $this->line("  - {$m}");
    }

    private function pathOf(string $baseDir, string $rel): string
    {
        $rel = ltrim($rel, "/\\");
        return rtrim($baseDir, "/\\") . DIRECTORY_SEPARATOR . $rel;
    }

    private function isJsonFile(string $abs): bool
    {
        return str_ends_with(strtolower($abs), '.json');
    }

    private function normalizePath(string $p): string
    {
        // keep relative if realpath fails
        $rp = @realpath($p);
        return $rp !== false ? $rp : $p;
    }

    private function expectedSchemaFor(array $manifest, string $assetKey, string $file): ?string
{
    $schemas = $manifest['schemas'] ?? [];
    $file = (string)$file;

    // ✅ 0) filename-first mapping (so pools/rules won't be mistaken as "highlights")
    if (str_contains($file, 'report_highlights_pools')) {
        return $schemas['highlights_pools'] ?? null;
    }
    if (str_contains($file, 'report_highlights_rules')) {
        return $schemas['highlights_rules'] ?? null;
    }

    // 1) 绝大多数 asset：一类一个 schema
    $direct = [
        'questions'        => 'questions',
        'type_profiles'    => 'type_profiles',
        'cards'            => 'cards',
        'fallback_cards'   => 'fallback_cards',
        'highlights'       => 'highlights',
        'reads'            => 'reads',
        'rules'            => 'rules',
        'section_policies' => 'section_policies',
    ];
    if (isset($direct[$assetKey])) {
        return $schemas[$direct[$assetKey]] ?? null;
    }

    // 2) borderline：templates / notes
    if ($assetKey === 'borderline') {
        if (str_contains($file, 'borderline_templates')) return $schemas['borderline_templates'] ?? null;
        if (str_contains($file, 'borderline_notes'))     return $schemas['borderline_notes'] ?? null;
        return null;
    }

    // 3) identity：cards / layers / roles / strategies（兼容 report_ 前缀与不带前缀）
    if ($assetKey === 'identity') {
        if (str_contains($file, 'identity_cards'))  return $schemas['identity_cards'] ?? null;
        if (str_contains($file, 'identity_layers')) return $schemas['identity_layers'] ?? null;
        if (str_contains($file, 'roles'))           return $schemas['roles'] ?? null;
        if (str_contains($file, 'strategies'))      return $schemas['strategies'] ?? null;
        return null;
    }

    // 4) overrides buckets
    if ($assetKey === 'overrides_unified') {
        return $schemas['overrides_unified'] ?? null;
    }
    if ($assetKey === 'overrides_highlights_legacy') {
        return $schemas['overrides_highlights_legacy'] ?? null;
    }

    return null;
}

private function validateOverrideMatch(
    array $match,
    string $packId,
    string $path,
    string $basePath,
    string $rid,
    array &$errors
): void {
    // 允许的 match keys
    $allowed = ['require_all', 'require_any', 'forbid', 'min_match', 'section', 'item'];

    // 1) unknown key 拦截
    foreach ($match as $k => $_) {
        if (!in_array((string)$k, $allowed, true)) {
            $errors[] =
                "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.{$k} :: unknown match key (allowed: "
                . implode(',', $allowed)
                . ")";
        }
    }

    // ✅ token 白名单（最小可用版：支持 type/role/strategy/top_axis）
    $tokenAllowed = function (string $tok) use (&$errors, $packId, $path, $basePath, $rid): void {
        $t = trim($tok);

        // 允许前缀
        if (!preg_match('/^(type|role|strategy|top_axis)[=:].+$/', $t)) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match :: token not allowed: {$t}";
            return;
        }

        // type=ENFJ-A
        if (preg_match('/^type[=:](.+)$/', $t, $m)) {
            $v = $m[1];
            if (!preg_match('/^(E|I)(S|N)(T|F)(J|P)-(A|T)$/', $v)) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match :: invalid type token: {$t}";
            }
            return;
        }

        // role=NT/NF/SJ/SP
        if (preg_match('/^role[=:](.+)$/', $t, $m)) {
            $v = $m[1];
            if (!in_array($v, ['NT','NF','SJ','SP'], true)) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match :: invalid role token: {$t}";
            }
            return;
        }

        // strategy=EA/ET/IA/IT
        if (preg_match('/^strategy[=:](.+)$/', $t, $m)) {
            $v = $m[1];
            if (!in_array($v, ['EA','ET','IA','IT'], true)) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match :: invalid strategy token: {$t}";
            }
            return;
        }

        // top_axis=EI/SN/TF/JP/AT
        if (preg_match('/^top_axis[=:](.+)$/', $t, $m)) {
            $v = $m[1];
            if (!in_array($v, ['EI','SN','TF','JP','AT'], true)) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match :: invalid top_axis token: {$t}";
            }
            return;
        }
    };

    // 2) require_* / forbid 必须是 list[str]，且 token 必须在白名单
    $checkStringList = function (string $key) use ($match, $packId, $path, $basePath, $rid, &$errors, $tokenAllowed): void {
        if (!array_key_exists($key, $match)) return;

        $v = $match[$key];

        // 必须是 list（非 assoc）
        if (!is_array($v) || $this->isAssocArray($v)) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.{$key} :: must be array(list of strings)";
            return;
        }

        // ✅ require_any 不允许空数组
        if ($key === 'require_any' && count($v) === 0) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.require_any :: must be non-empty array(list of strings)";
            return;
        }

        $seen = [];
        foreach ($v as $i => $s) {
            if (!is_string($s) || trim($s) === '') {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.{$key}[{$i}] :: must be non-empty string";
                continue;
            }
            $ss = trim($s);

            if (isset($seen[$ss])) {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.{$key} :: duplicate entry '{$ss}'";
            }
            $seen[$ss] = true;

            // ✅ token 白名单
            $tokenAllowed($ss);
        }
    };

    $checkStringList('require_all');
    $checkStringList('require_any');
    $checkStringList('forbid');

    // 2.5) section / item: allow string OR list[string]
    $checkStringOrList = function (string $key) use ($match, $packId, $path, $basePath, $rid, &$errors): void {
        if (!array_key_exists($key, $match)) return;

        $v = $match[$key];

        // allow scalar string
        if (is_string($v)) {
            if (trim($v) === '') {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.{$key} :: must be non-empty string or array(list of strings)";
            }
            return;
        }

        // allow list[str]
        if (!is_array($v) || $this->isAssocArray($v)) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.{$key} :: must be non-empty string or array(list of strings)";
            return;
        }

        foreach ($v as $i => $s) {
            if (!is_string($s) || trim($s) === '') {
                $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.{$key}[{$i}] :: must be non-empty string";
            }
        }
    };

    $checkStringOrList('section');
    $checkStringOrList('item');

    // 3) min_match：必须是 integer 且 >=1；并且必须配合 require_any(非空 list)；且 <= count(require_any)
    if (array_key_exists('min_match', $match)) {
        $mm = $match['min_match'];

        if (!is_int($mm) && !(is_numeric($mm) && (int)$mm == $mm)) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.min_match :: must be integer";
            return;
        }

        $mm = (int)$mm;
        if ($mm < 1) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.min_match :: must be >= 1";
            return;
        }

        $ra = $match['require_any'] ?? null;
        if (!is_array($ra) || $this->isAssocArray($ra) || count($ra) === 0) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.min_match :: require_any must be non-empty when min_match is set";
            return;
        }

        $n = count($ra);
        if ($mm > $n) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match.min_match :: must be <= count(require_any) (= {$n})";
        }
    }
}
}