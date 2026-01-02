<?php
// file: backend/app/Services/Content/ContentStore.php

namespace App\Services\Content;

use App\Services\Report\ReportContentNormalizer;
use Illuminate\Support\Facades\Log;

final class ContentStore
{
    /** @var ContentPack[] */
    private array $chain;

    // 旧兼容（如果你还需要从 ctx loader 兜底，可用）
    private array $ctx;
    private string $legacyDir;

    public function __construct(array $chain, array $ctx = [], string $legacyDir = '')
    {
        $this->chain = $chain;
        $this->ctx = $ctx;
        $this->legacyDir = $legacyDir;
    }

    // =========================
    // Public API（对外唯一入口）
    // =========================

    /** cards: 返回 “标准化后的 items(list)” */
    public function loadCards(string $section): array
    {
        $doc = $this->loadCardsDoc($section);
        return is_array($doc['items'] ?? null) ? $doc['items'] : [];
    }

    /**
     * ✅ cards doc：统一返回固定结构 ['items'=>..., 'rules'=>...]
     * - 负责：读文件 + 轻 schema check + items 标准化 + rules 缺省补齐
     *
     * items 标准化包括：
     * - tags/rules/priority/section/match/tips/bullets/title/desc 类型兜底
     * - 过滤空字符串
     * - 默认 tips 注入点（ReportContentNormalizer::fillTipsIfMissing）
     */
    public function loadCardsDoc(string $section): array
    {
        $basename = "report_cards_{$section}.json";
        $doc = $this->loadJsonByBasenamePreferAssetKey('cards', $basename);
        $this->lightSchemaCheck($doc, $basename);

         // ✅ 统一标准化（与 fallback cards 复用同一逻辑）
        return $this->normalizeCardsDocFromRawDoc($doc, $basename, $section);
    }

    // =========================
    // ✅ New: Section Policies + Fallback Cards loaders
    // =========================

    /**
 * ✅ section policies doc：
 * 文件名固定：report_section_policies.json
 *
 * 为了和 ReportComposer/Assembler 对齐，这里统一返回：
 * [
 *   'schema' => ...,
 *   'items'  => [ 'traits'=>[...], 'career'=>[...], ... ]   // ✅ 注意是 items，不是 sections
 * ]
 *
 * 兼容两种文件结构：
 * A) { "sections": { ... } }
 * B) { "items": { ... } }
 */
public function loadSectionPolicies(): array
{
    $basename = 'report_section_policies.json';

    // 优先用 policies 这个 assetKey；找不到会自动走 scan-any-asset + legacy ctx（取决于 env 开关）
    $doc = $this->loadJsonByBasenamePreferAssetKey('policies', $basename);
    $this->lightSchemaCheck($doc, $basename);

    // 兼容：sections / items
    $sections = $doc['sections'] ?? ($doc['items'] ?? null);
    if (!is_array($sections)) $sections = [];

    $out = [];
    foreach ($sections as $sec => $pol) {
        if (!is_string($sec) || trim($sec) === '') continue;
        if (!is_array($pol)) $pol = [];

        $min = isset($pol['min_cards']) && is_numeric($pol['min_cards']) ? (int)$pol['min_cards'] : 2;
        $min = max(1, $min);

        $target = isset($pol['target_cards']) && is_numeric($pol['target_cards']) ? (int)$pol['target_cards'] : $min;
        if ($target < $min) $target = $min;

        $max = isset($pol['max_cards']) && is_numeric($pol['max_cards']) ? (int)$pol['max_cards'] : max($target, $min);
        if ($max < $target) $max = $target;

        // ✅ 可选字段：allow_fallback（默认 true）
        $allowFallback = $pol['allow_fallback'] ?? true;
        $allowFallback = is_bool($allowFallback) ? $allowFallback : (bool)$allowFallback;

        $fallbackFile = $pol['fallback_file'] ?? null;
        $fallbackFile = is_string($fallbackFile) ? trim($fallbackFile) : '';
        if ($fallbackFile === '') {
            $fallbackFile = "report_cards_fallback_{$sec}.json";
        }

        $out[$sec] = [
            'min_cards'     => $min,
            'target_cards'  => $target,
            'max_cards'     => $max,
            'allow_fallback'=> $allowFallback,
            'fallback_file' => $fallbackFile,
        ];
    }

    return [
        'schema' => is_string($doc['schema'] ?? null) ? (string)$doc['schema'] : null,
        'items'  => $out, // ✅ 关键：统一成 items
    ];
}

/**
 * ✅ fallback cards（按 section 加载）
 * - 优先 policies.items[section].fallback_file
 * - 否则退化 report_cards_fallback_{section}.json
 * - 返回：标准化后的 items(list)
 */
public function loadFallbackCards(string $section): array
{
    $polDoc = $this->loadSectionPolicies();
    $policies = is_array($polDoc['items'] ?? null) ? $polDoc['items'] : [];

    $fallbackFile = $policies[$section]['fallback_file'] ?? "report_cards_fallback_{$section}.json";
    $basename = basename((string)$fallbackFile);

    // 优先用 fallback_cards 这个 assetKey；找不到会 scan-any-asset + legacy ctx（取决于 env 开关）
    $doc = $this->loadJsonByBasenamePreferAssetKey('fallback_cards', $basename);
    $this->lightSchemaCheck($doc, $basename);

    $norm = $this->normalizeCardsDocFromRawDoc($doc, $basename, $section);
    return is_array($norm['items'] ?? null) ? $norm['items'] : [];
}

public function loadSelectRules(): array
{
    // 规则文件名固定
    $filename = 'report_select_rules.json';

    // pack chain：primary -> fallback，找到第一个存在的就用
    foreach ($this->chain as $pack) {
        if (!($pack instanceof ContentPack)) continue;

        $path = rtrim($pack->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        if (is_string($path) && $path !== '' && is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw === false || trim($raw) === '') return [];

            $json = json_decode($raw, true);
            if (!is_array($json)) return [];

            // 支持两种结构：{"rules":[...]} 或 直接就是 [...]
            $rules = $json['rules'] ?? $json;
            return is_array($rules) ? $rules : [];
        }
    }

    return [];
}

    /** highlights templates doc（给 HighlightBuilder 用） */
    public function loadHighlights(): array
    {
        $doc = $this->loadJsonByBasenamePreferAssetKey('highlights', 'report_highlights_templates.json');
        $this->lightSchemaCheck($doc, 'report_highlights_templates.json');

        if ((bool) env('FAP_FORBID_MISSING_HIGHLIGHTS', false) && empty($doc)) {
            throw new \RuntimeException('STORE_HIGHLIGHTS_MISSING: report_highlights_templates.json not found');
        }

        return $doc;
    }

    /** reads doc（返回标准化后的 items 结构） */
    public function loadReads(): array
    {
        $doc = $this->loadJsonByBasenamePreferAssetKey('reads', 'report_recommended_reads.json');
        $this->lightSchemaCheck($doc, 'report_recommended_reads.json');

        if ((bool) env('FAP_FORBID_MISSING_READS', false) && empty($doc)) {
            throw new \RuntimeException('STORE_READS_MISSING: report_recommended_reads.json not found');
        }

        if (!is_array($doc['items'] ?? null)) $doc['items'] = [];

        // buckets 缺省补齐（避免业务侧各种 isset）
        $doc['items']['by_type'] = is_array($doc['items']['by_type'] ?? null) ? $doc['items']['by_type'] : [];
        $doc['items']['by_role'] = is_array($doc['items']['by_role'] ?? null) ? $doc['items']['by_role'] : [];
        $doc['items']['by_strategy'] = is_array($doc['items']['by_strategy'] ?? null) ? $doc['items']['by_strategy'] : [];
        $doc['items']['by_top_axis'] = is_array($doc['items']['by_top_axis'] ?? null) ? $doc['items']['by_top_axis'] : [];
        $doc['items']['fallback'] = is_array($doc['items']['fallback'] ?? null) ? $doc['items']['fallback'] : [];

        // 标准化每条 read item 的缺省字段
        $doc['items'] = $this->normalizeReadBuckets($doc['items']);

        return $doc;
    }

    /** overrides（返回合并后的统一 doc：{schema,rules,__src_chain}，并补默认字段） */
    public function loadOverrides(): ?array
    {
        $docs = $this->loadOverridesDocsOrderedFromChain();

        if (empty($docs)) {
            if ((bool) env('FAP_FORBID_MISSING_OVERRIDES', false)) {
                throw new \RuntimeException('STORE_OVERRIDES_MISSING: no overrides docs loaded');
            }
            return null;
        }

        $merged = [
            'schema' => 'fap.report.overrides.v1',
            'rules' => [],
            '__src_chain' => [],
        ];

        foreach ($docs as $d) {
            if (!is_array($d)) continue;

            if (is_array($d['rules'] ?? null)) {
                foreach ($d['rules'] as $r) {
                    if (!is_array($r)) continue;
                    // defaults
                    if (!isset($r['tags']) || !is_array($r['tags'])) $r['tags'] = [];
                    if (!isset($r['priority']) || !is_numeric($r['priority'])) $r['priority'] = 0;
                    if (!isset($r['rules']) || !is_array($r['rules'])) $r['rules'] = []; // 某些 target 用得到
                    $merged['rules'][] = $r;
                }
            }

            if (is_array($d['__src'] ?? null)) {
                $merged['__src_chain'][] = $d['__src'];
            }
        }

        return $merged;
    }

    /**
     * overrides order buckets（给 highlights pipeline 决定 legacy/unified 顺序）
     */
    public function overridesOrderBuckets(): array
    {
        foreach ($this->chain as $p) {
            if (!$p instanceof ContentPack) continue;
            $ov = $p->assets()['overrides'] ?? null;
            if (!is_array($ov) || $ov === []) continue;

            // list: 默认 unified
            if ($this->isListArray($ov)) return ['unified'];

            $order = $ov['order'] ?? null;
            if (is_array($order) && $order !== []) {
                $out = [];
                foreach ($order as $x) {
                    if (is_string($x) && trim($x) !== '') $out[] = $x;
                }
                return $out ?: ['highlights_legacy', 'unified'];
            }

            // no order -> keys except order
            $out = [];
            foreach ($ov as $k => $_) {
                if ($k === 'order') continue;
                if (is_string($k) && trim($k) !== '') $out[] = $k;
            }
            return $out ?: ['highlights_legacy', 'unified'];
        }

        return ['highlights_legacy', 'unified'];
    }

    // =========================
    // Internal: read/locate/normalize
    // =========================

    /**
     * ✅ 复用：把任意 cards doc（含 fallback）标准化成固定结构：
     * ['items'=>normItems, 'rules'=>rules]
     */
    private function normalizeCardsDocFromRawDoc(array $doc, string $basename, string $section): array
    {
        // items 兼容：items / cards
        $items = $doc['items'] ?? ($doc['cards'] ?? null);
        if (!is_array($items)) $items = [];

        // ✅ 标准化 items（缺省补齐 + 类型稳定 + 默认 tips 注入）
        $normItems = [];
        foreach ($items as $idx => $it) {
            if (!is_array($it)) {
                Log::warning('[STORE][CARDS] item_not_array', ['file' => $basename, 'section' => $section, 'idx' => $idx]);
                continue;
            }

            $id = $it['id'] ?? null;
            $id = is_string($id) ? trim($id) : '';
            if ($id === '') {
                Log::warning('[STORE][CARDS] item_missing_id_skip', ['file' => $basename, 'section' => $section, 'idx' => $idx]);
                continue;
            }
            $it['id'] = $id;

            // section
            if (!isset($it['section']) || !is_string($it['section']) || trim($it['section']) === '') {
                $it['section'] = $section;
            } else {
                $it['section'] = trim((string)$it['section']);
            }

            // title/desc
            $it['title'] = is_string($it['title'] ?? null) ? (string)$it['title'] : '';
            $it['desc']  = is_string($it['desc'] ?? null)  ? (string)$it['desc']  : '';

            // bullets: array[string]
            if (!is_array($it['bullets'] ?? null)) $it['bullets'] = [];
            $it['bullets'] = array_values(array_filter(
                $it['bullets'],
                fn($x) => is_string($x) && trim($x) !== ''
            ));

            // tips: array[string]
            if (!is_array($it['tips'] ?? null)) $it['tips'] = [];
            $it['tips'] = array_values(array_filter(
                $it['tips'],
                fn($x) => is_string($x) && trim($x) !== ''
            ));

            // tags: array[string]
            if (!isset($it['tags']) || !is_array($it['tags'])) $it['tags'] = [];
            $it['tags'] = array_values(array_filter(
                $it['tags'],
                fn($x) => is_string($x) && trim($x) !== ''
            ));

            // rules: array
            if (!isset($it['rules']) || !is_array($it['rules'])) $it['rules'] = [];

            // priority: int
            if (!isset($it['priority']) || !is_numeric($it['priority'])) $it['priority'] = 0;
            $it['priority'] = (int)$it['priority'];

            // match：保证键存在（generator 会读取）
            if (!array_key_exists('match', $it)) $it['match'] = null;

            // ✅ 默认 tips 注入点
            $it = ReportContentNormalizer::fillTipsIfMissing($it);

            $normItems[] = $it;
        }

        // ✅ rules 缺省补齐（fallback 文件一般也允许带 rules；没带就用默认）
        $rules = is_array($doc['rules'] ?? null) ? $doc['rules'] : [];

        $min = isset($rules['min_cards']) && is_numeric($rules['min_cards']) ? (int)$rules['min_cards'] : 2;
        $min = max(2, $min);

        $target = isset($rules['target_cards']) && is_numeric($rules['target_cards']) ? (int)$rules['target_cards'] : 3;
        if ($target < $min) $target = $min;

        $max = isset($rules['max_cards']) && is_numeric($rules['max_cards']) ? (int)$rules['max_cards'] : 6;
        if ($max < $target) $max = $target;

        $fallbackTags = $rules['fallback_tags'] ?? ['fallback', 'kind:core'];
        if (!is_array($fallbackTags)) $fallbackTags = ['fallback', 'kind:core'];
        $fallbackTags = array_values(array_filter($fallbackTags, fn($x) => is_string($x) && trim($x) !== ''));
        if ($fallbackTags === []) $fallbackTags = ['fallback', 'kind:core'];

        $rules = [
            'min_cards'     => $min,
            'target_cards'  => $target,
            'max_cards'     => $max,
            'fallback_tags' => $fallbackTags,
        ];

        return [
            'items' => $normItems,
            'rules' => $rules,
        ];
    }

    private function loadJsonByBasenamePreferAssetKey(string $assetKey, string $basename): array
    {
        // 1) 优先：assetKey 下找 basename
        $doc = $this->loadJsonFromChainByAssetKeyAndBasename($assetKey, $basename);
        if ($doc !== null) return $doc;

        // 🔥 爆炸验证 1：禁止“扫描所有 assets”兜底
        if ((bool) env('FAP_FORBID_STORE_ASSET_SCAN', false)) {
            throw new \RuntimeException("STORE_ASSET_SCAN_FORBIDDEN: asset={$assetKey} file={$basename}");
        }

        // 2) 次选：扫描所有 assets 找 basename（容错）
        $doc = $this->loadJsonFromChainByAnyAssetAndBasename($basename);
        if ($doc !== null) return $doc;

        // 🔥 爆炸验证 2：禁止 legacy ctx loader 兜底
        if (is_callable($this->ctx['loadReportAssetJson'] ?? null) && $this->legacyDir !== '') {
            if ((bool) env('FAP_FORBID_LEGACY_CTX_LOADER', false)) {
                throw new \RuntimeException("LEGACY_CTX_LOADER_FORBIDDEN: asset={$assetKey} file={$basename}");
            }

            $raw = ($this->ctx['loadReportAssetJson'])($this->legacyDir, $basename);
            if (is_object($raw)) $raw = json_decode(json_encode($raw, JSON_UNESCAPED_UNICODE), true);
            if (is_array($raw)) {
                $doc = $raw['doc'] ?? $raw['data'] ?? $raw;
                if (is_array($doc)) return $doc;
            }
        }

        Log::warning('[STORE] json_not_found', ['asset' => $assetKey, 'file' => $basename]);
        return [];
    }

    private function loadJsonFromChainByAssetKeyAndBasename(string $assetKey, string $basename): ?array
    {
        foreach ($this->chain as $p) {
            if (!$p instanceof ContentPack) continue;

            $assets = $p->assets();
            $val = $assets[$assetKey] ?? null;

            $paths = $this->flattenAssetPaths($val);
            foreach ($paths as $rel) {
                if (!is_string($rel) || trim($rel) === '') continue;
                if (basename($rel) !== $basename) continue;

                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs)) continue;

                $json = json_decode((string)file_get_contents($abs), true);
                if (!is_array($json)) continue;

                Log::info('[STORE] json_loaded', [
                    'asset' => $assetKey,
                    'file' => $basename,
                    'pack_id' => $p->packId(),
                    'version' => $p->version(),
                    'path' => $abs,
                ]);

                return $json;
            }
        }
        return null;
    }

    private function loadJsonFromChainByAnyAssetAndBasename(string $basename): ?array
    {
        foreach ($this->chain as $p) {
            if (!$p instanceof ContentPack) continue;

            foreach (($p->assets() ?? []) as $assetKey => $val) {
                $paths = $this->flattenAssetPaths($val);
                foreach ($paths as $rel) {
                    if (!is_string($rel) || trim($rel) === '') continue;
                    if (basename($rel) !== $basename) continue;

                    $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                    if (!is_file($abs)) continue;

                    $json = json_decode((string)file_get_contents($abs), true);
                    if (!is_array($json)) continue;

                    Log::info('[STORE] json_loaded_scan', [
                        'asset' => $assetKey,
                        'file' => $basename,
                        'pack_id' => $p->packId(),
                        'version' => $p->version(),
                        'path' => $abs,
                    ]);

                    return $json;
                }
            }
        }
        return null;
    }

    private function loadOverridesDocsOrderedFromChain(): array
    {
        $docs = [];
        $idx = 0;

        foreach ($this->chain as $p) {
            if (!$p instanceof ContentPack) continue;

            $assetVal = $p->assets()['overrides'] ?? null;
            if (!is_array($assetVal) || $assetVal === []) continue;

            $orderedPaths = $this->getOverridesOrderedPaths($assetVal);

            foreach ($orderedPaths as $rel) {
                if (!is_string($rel) || trim($rel) === '') continue;

                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs)) continue;

                $json = json_decode((string)file_get_contents($abs), true);
                if (!is_array($json)) continue;

                // 归一化：overrides -> rules
                if (!is_array($json['rules'] ?? null) && is_array($json['overrides'] ?? null)) {
                    $json['rules'] = $json['overrides'];
                }

                $src = [
                    'idx' => $idx,
                    'pack_id' => $p->packId(),
                    'version' => $p->version(),
                    'file' => basename($rel),
                    'rel' => $rel,
                    'path' => $abs,
                ];
                $json['__src'] = $src;

                if (is_array($json['rules'] ?? null)) {
                    foreach ($json['rules'] as &$r) {
                        if (is_array($r)) $r['__src'] = $src;
                        if (is_array($r)) {
                            if (!isset($r['tags']) || !is_array($r['tags'])) $r['tags'] = [];
                            if (!isset($r['priority']) || !is_numeric($r['priority'])) $r['priority'] = 0;
                        }
                    }
                    unset($r);
                }

                $docs[] = $json;
                $idx++;
            }
        }

        return $docs;
    }

    private function getOverridesOrderedPaths(array $assetVal): array
    {
        // list
        if ($this->isListArray($assetVal)) {
            return array_values(array_filter($assetVal, fn($x) => is_string($x) && trim($x) !== ''));
        }

        // map + order
        $order = $assetVal['order'] ?? null;
        $out = [];

        if (is_array($order) && $order !== []) {
            foreach ($order as $bucket) {
                if (!is_string($bucket) || $bucket === '') continue;
                $v = $assetVal[$bucket] ?? null;
                if (!is_array($v)) continue;
                foreach ($v as $path) {
                    if (is_string($path) && trim($path) !== '') $out[] = $path;
                }
            }
            return array_values(array_unique($out));
        }

        // no order
        foreach ($assetVal as $k => $v) {
            if ($k === 'order') continue;
            if (!is_array($v)) continue;
            foreach ($v as $path) {
                if (is_string($path) && trim($path) !== '') $out[] = $path;
            }
        }
        return array_values(array_unique($out));
    }

    private function flattenAssetPaths($assetVal): array
    {
        if (!is_array($assetVal)) return [];

        if ($this->isListArray($assetVal)) {
            return array_values(array_filter($assetVal, fn($x) => is_string($x) && trim($x) !== ''));
        }

        // map (e.g. overrides)
        $out = [];
        foreach ($assetVal as $k => $v) {
            if ($k === 'order') continue;
            $list = is_array($v) ? $v : [$v];
            foreach ($list as $x) {
                if (is_string($x) && trim($x) !== '') $out[] = $x;
            }
        }
        return array_values(array_unique($out));
    }

    private function isListArray(array $a): bool
    {
        if ($a === []) return true;
        return array_keys($a) === range(0, count($a) - 1);
    }

    private function lightSchemaCheck(array $doc, string $file): void
    {
        // 轻量：只做“存在 & 类型”校验，不做强阻断
        if (!is_array($doc)) {
            Log::warning('[STORE] schema_bad', ['file' => $file, 'reason' => 'doc_not_array']);
            return;
        }
        if (isset($doc['schema']) && !is_string($doc['schema'])) {
            Log::warning('[STORE] schema_bad', ['file' => $file, 'reason' => 'schema_not_string']);
        }
    }

    private function normalizeReadBuckets(array $items): array
    {
        $normList = function ($list) {
            if (!is_array($list)) return [];
            foreach ($list as &$it) {
                if (!is_array($it)) { $it = []; continue; }
                if (!isset($it['tags']) || !is_array($it['tags'])) $it['tags'] = [];
                if (!isset($it['priority']) || !is_numeric($it['priority'])) $it['priority'] = 0;
            }
            unset($it);
            return $list;
        };

        // by_type/by_role/by_strategy/by_top_axis 都是 map -> list
        foreach (['by_type','by_role','by_strategy','by_top_axis'] as $k) {
            $m = $items[$k] ?? [];
            if (!is_array($m)) { $items[$k] = []; continue; }
            foreach ($m as $key => $list) {
                $m[$key] = $normList($list);
            }
            $items[$k] = $m;
        }

        $items['fallback'] = $normList($items['fallback'] ?? []);

        return $items;
    }
}
