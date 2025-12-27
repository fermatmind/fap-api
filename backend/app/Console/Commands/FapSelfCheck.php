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
        {--pack_id= : Resolve manifest.json by pack_id (scan content_packages)}';

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
            fn () => $this->checkQuestions($this->pathOf($baseDir, 'questions.json'), $packId)
        );

        // 2) type_profiles.json
        $runIfDeclared(
            'type_profiles.json',
            'type_profiles.json',
            fn () => $this->checkTypeProfiles($this->pathOf($baseDir, 'type_profiles.json'), $packId)
        );

        // 3) report_highlights_templates.json
        $runIfDeclared(
            'report_highlights_templates.json',
            'report_highlights_templates.json',
            fn () => $this->checkHighlightsTemplates($this->pathOf($baseDir, 'report_highlights_templates.json'), $packId)
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

        // 9) report_overrides.json (unified overrides rules validation)
        $runIfDeclared(
            'report_overrides.json',
            'report_overrides.json',
            fn () => $this->checkReportOverrides($this->pathOf($baseDir, 'report_overrides.json'), $manifest, $packId)
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

        // 5) schema alignment: each JSON asset must have top-level .schema matching manifest.schemas mapping
        $schemaErrors = $this->checkAssetsSchemasAgainstManifest($manifest, $baseDir, $packId);
        foreach ($schemaErrors as $e) $errors[] = $e;

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

        foreach ($schemas as $schemaKey => $expectedSchema) {
            if (!is_string($expectedSchema) || trim($expectedSchema) === '') continue;

            $rels = $this->selectAssetFilesForSchemaKey($assets, (string)$schemaKey);

            foreach ($rels as $rel) {
                if (!is_string($rel) || trim($rel) === '') continue;
                if (str_ends_with($rel, '/')) continue;

                $abs = $this->pathOf($baseDir, $rel);
                if (!is_file($abs)) continue;

                $doc = $this->readJsonFile($abs);
                $got = is_array($doc) ? ($doc['schema'] ?? null) : null;

                if ($got !== $expectedSchema) {
                    $errs[] = "pack={$packId} file={$abs} path=$.schema :: schema mismatch got=" . var_export($got, true) . " want=" . var_export($expectedSchema, true);
                }
            }
        }

        return $errs;
    }

    /**
     * Map schemaKey -> asset file list
     * (must match your manifest.schemas keys)
     */
    private function selectAssetFilesForSchemaKey(array $assets, string $schemaKey): array
    {
        // direct groups
        if (in_array($schemaKey, ['questions','type_profiles','cards','highlights','reads'], true)) {
            return is_array($assets[$schemaKey] ?? null) ? $assets[$schemaKey] : [];
        }

        // borderline split
        if ($schemaKey === 'borderline_templates') {
            return $this->filterBySubstring($assets['borderline'] ?? [], 'templates');
        }
        if ($schemaKey === 'borderline_notes') {
            return $this->filterBySubstring($assets['borderline'] ?? [], 'notes');
        }

        // identity split
        if ($schemaKey === 'identity_cards') {
            return $this->filterBySubstring($assets['identity'] ?? [], 'identity_cards');
        }
        if ($schemaKey === 'identity_layers') {
            return $this->filterBySubstring($assets['identity'] ?? [], 'identity_layers');
        }
        if ($schemaKey === 'roles') {
            return $this->filterBySubstring($assets['identity'] ?? [], 'roles');
        }
        if ($schemaKey === 'strategies') {
            return $this->filterBySubstring($assets['identity'] ?? [], 'strategies');
        }

        // overrides split (special object)
        if ($schemaKey === 'overrides_unified') {
            $ov = $assets['overrides'] ?? null;
            return (is_array($ov) && isset($ov['unified']) && is_array($ov['unified'])) ? $ov['unified'] : [];
        }
        if ($schemaKey === 'overrides_highlights_legacy') {
            $ov = $assets['overrides'] ?? null;
            return (is_array($ov) && isset($ov['highlights_legacy']) && is_array($ov['highlights_legacy'])) ? $ov['highlights_legacy'] : [];
        }

        return [];
    }

    private function filterBySubstring($list, string $needle): array
    {
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $x) {
            if (!is_string($x)) continue;
            if (str_contains($x, $needle)) $out[] = $x;
        }
        return $out;
    }

// -------------------------
// Unified overrides rules validation
// -------------------------

private function checkReportOverrides(string $path, array $manifest, string $packId): array
{
    if (!is_file($path)) {
        return [false, ["pack={$packId} file={$path} :: File not found"]];
    }

    $doc = $this->readJsonFile($path);
    if (!is_array($doc)) {
        return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];
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

        if (array_key_exists('match', $r) && !is_array($r['match'])) {
            $errors[] = "ERR pack={$packId} file={$path} rule_id={$rid} path={$basePath}.match :: match must be object(map)";
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
    // File checks
    // -------------------------

    private function checkQuestions(string $path, string $packId): array
    {
        if (!is_file($path)) {
            return [false, ["pack={$packId} file={$path} :: File not found"]];
        }

        $json = $this->readJsonFile($path);
        if (!is_array($json)) {
            return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];
        }

        $items = isset($json['items']) ? $json['items'] : $json;
        if (!is_array($items)) {
            return [false, ["pack={$packId} file={$path} path=$.items :: Invalid items structure (expect array or {items:[]})"]];
        }

        $items = array_values(array_filter($items, fn ($q) => ($q['is_active'] ?? true) === true));
        usort($items, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        $errors = [];

        if (count($items) !== 144) {
            $errors[] = "pack={$packId} file={$path} :: Active questions must be 144, got " . count($items);
        }

        $validDims = ['EI', 'SN', 'TF', 'JP', 'AT'];
        $seenQid = [];
        $orders = [];

        foreach ($items as $i => $q) {
            $idx = $i + 1;
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

    private function checkTypeProfiles(string $path, string $packId): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

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

    private function checkHighlightsTemplates(string $path, string $packId): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

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

    private function checkHighlightsOverrides(string $path, string $packId): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

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

    private function checkBorderlineTemplates(string $path, string $packId): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

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

    private function checkReportRoles(string $path, string $packId): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

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

    private function checkReportStrategies(string $path, string $packId): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

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

    private function checkRecommendedReads(string $path, string $packId): array
    {
        if (!is_file($path)) return [false, ["pack={$packId} file={$path} :: File not found"]];

        $json = $this->readJsonFile($path);
        if (!is_array($json)) return [false, ["pack={$packId} file={$path} :: Invalid JSON"]];

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
}