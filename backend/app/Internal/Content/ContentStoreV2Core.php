<?php

namespace App\Internal\Content;

use App\Services\Content\ContentPack;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportContentNormalizer;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ContentStoreV2Core
{
    private const HOT_CACHE_TTL_SECONDS = 300;

    /** @var ContentPack[] */
    private array $chain;

    // 旧兼容（如果你还需要从 ctx loader 兜底，可用）
    private array $ctx;

    private string $legacyDir;

    // =========================
    // ✅ In-memory caches (per ContentStore instance)
    // key = kind|pack_id|locale|version
    // =========================
    private array $cacheHighlightPools = [];

    private array $cacheHighlightRules = [];

    private array $cacheHighlightPolicy = [];

    public function __construct(array $chain, array $ctx = [], string $legacyDir = '')
    {
        $this->chain = $chain;
        $this->ctx = $ctx;
        $this->legacyDir = $legacyDir;
    }

    private function hotCacheStore()
    {
        if (app()->environment(['testing', 'ci'])) {
            return Cache::store((string) config('cache.default', 'array'));
        }

        try {
            return Cache::store('hot_redis');
        } catch (\Throwable $e) {
            return Cache::store();
        }
    }

    private function shouldLogHotCache(): bool
    {
        return (bool) config('app.debug') || (bool) \App\Support\RuntimeConfig::value('FAP_CACHE_LOG', true);
    }

    private function logHotCache(string $kind, string $key, bool $hit): void
    {
        if (! $this->shouldLogHotCache()) {
            return;
        }

        $flagHit = $hit ? 1 : 0;
        $flagMiss = $hit ? 0 : 1;

        Log::info("[HOTCACHE] kind={$kind} key={$key} hit={$flagHit} miss={$flagMiss}");
    }

    private function packPathFor(ContentPack $pack): string
    {
        $basePath = str_replace(DIRECTORY_SEPARATOR, '/', $pack->basePath());

        $root = (string) config('content_packs.root', '');
        $root = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $root), '/');
        if ($root !== '' && str_starts_with($basePath, $root.'/')) {
            return substr($basePath, strlen($root) + 1);
        }

        $cacheDir = (string) config('content_packs.cache_dir', '');
        $cacheDir = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $cacheDir), '/');
        if ($cacheDir !== '' && str_starts_with($basePath, $cacheDir.'/')) {
            return substr($basePath, strlen($cacheDir) + 1);
        }

        return ltrim($basePath, '/');
    }

    private function isHotJsonAsset(string $relPath): bool
    {
        $relPath = str_replace(DIRECTORY_SEPARATOR, '/', $relPath);
        if (! str_ends_with($relPath, '.json')) {
            return false;
        }

        $basename = basename($relPath);

        // overrides 类文件会被验收脚本临时写入，不能缓存，否则会读到旧内容
        if ($basename === 'report_overrides.json' || str_contains($basename, 'overrides')) {
            return false;
        }

        $hotBasenames = [
            'manifest.json',
            'questions.json',
            'type_profiles.json',
            'identity_cards.json',
            'identity_layers.json',
        ];
        if (in_array($basename, $hotBasenames, true)) {
            return true;
        }

        if ($relPath === 'meta/landing.json' || str_ends_with($relPath, '/meta/landing.json')) {
            return true;
        }

        if (str_starts_with($relPath, 'share_templates/')) {
            return true;
        }

        return (bool) preg_match('/^report_.*\.json$/', $basename);
    }

    private function readJsonFromPath(ContentPack $pack, string $relPath, string $absPath): ?array
    {
        $relPath = str_replace(DIRECTORY_SEPARATOR, '/', $relPath);

        $useHotCache = $this->isHotJsonAsset($relPath);
        $cacheKey = '';
        $cache = null;

        if ($useHotCache) {
            $cacheKey = CacheKeys::contentAsset($this->packPathFor($pack), $relPath);
            $cache = $this->hotCacheStore();
            try {
                $cached = $cache->get($cacheKey);
            } catch (\Throwable $e) {
                try {
                    $cache = Cache::store();
                    $cached = $cache->get($cacheKey);
                } catch (\Throwable $e2) {
                    $cached = null;
                }
            }

            if (is_array($cached)) {
                $this->logHotCache('asset', $cacheKey, true);

                return $cached;
            }
        }

        $raw = @file_get_contents($absPath);
        if ($raw === false) {
            return null;
        }

        $json = json_decode((string) $raw, true);
        if (! is_array($json)) {
            return null;
        }

        if ($useHotCache && $cacheKey !== '') {
            try {
                if ($cache !== null) {
                    $cache->put($cacheKey, $json, self::HOT_CACHE_TTL_SECONDS);
                }
            } catch (\Throwable $e) {
                try {
                    Cache::store()->put($cacheKey, $json, self::HOT_CACHE_TTL_SECONDS);
                } catch (\Throwable $e2) {
                    Log::warning('CONTENT_STORE_CACHE_WRITE_FAILED', [
                        'cache_key' => $cacheKey,
                        'store' => 'default',
                        'ttl' => self::HOT_CACHE_TTL_SECONDS,
                        'exception' => $e2,
                    ]);
                }
            }

            $this->logHotCache('asset', $cacheKey, false);
        }

        return $json;
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
        $compiled = $this->loadCompiledCardsDoc($section);
        if (is_array($compiled)) {
            return $this->normalizeCardsDocFromRawDoc(
                $compiled,
                "compiled/cards.normalized.json#{$section}",
                $section
            );
        }

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
        $compiled = $this->loadCompiledSectionsSpec();
        if (is_array($compiled)) {
            $doc = $compiled;
        } else {
            $basename = 'report_section_policies.json';

            // 优先用 policies 这个 assetKey；找不到会自动走 scan-any-asset + legacy ctx（取决于 env 开关）
            $doc = $this->loadJsonByBasenamePreferAssetKey('policies', $basename);
            $this->lightSchemaCheck($doc, $basename);
        }

        // 兼容：sections / items
        $sections = $doc['sections'] ?? ($doc['items'] ?? null);
        if (! is_array($sections)) {
            $sections = [];
        }

        $out = [];
        foreach ($sections as $sec => $pol) {
            if (! is_string($sec) || trim($sec) === '') {
                continue;
            }
            if (! is_array($pol)) {
                $pol = [];
            }

            $min = isset($pol['min_cards']) && is_numeric($pol['min_cards']) ? (int) $pol['min_cards'] : 2;
            $min = max(1, $min);

            $target = isset($pol['target_cards']) && is_numeric($pol['target_cards']) ? (int) $pol['target_cards'] : $min;
            if ($target < $min) {
                $target = $min;
            }

            $max = isset($pol['max_cards']) && is_numeric($pol['max_cards']) ? (int) $pol['max_cards'] : max($target, $min);
            if ($max < $target) {
                $max = $target;
            }

            // ✅ 可选字段：allow_fallback（默认 true）
            $allowFallback = $pol['allow_fallback'] ?? true;
            $allowFallback = is_bool($allowFallback) ? $allowFallback : (bool) $allowFallback;

            $fallbackFile = $pol['fallback_file'] ?? null;
            $fallbackFile = is_string($fallbackFile) ? trim($fallbackFile) : '';
            if ($fallbackFile === '') {
                $fallbackFile = "report_cards_fallback_{$sec}.json";
            }

            $out[$sec] = [
                'min_cards' => $min,
                'target_cards' => $target,
                'max_cards' => $max,
                'allow_fallback' => $allowFallback,
                'fallback_file' => $fallbackFile,
            ];
        }

        return [
            'schema' => is_string($doc['schema'] ?? null) ? (string) $doc['schema'] : null,
            'items' => $out, // ✅ 关键：统一成 items
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
        $basename = basename((string) $fallbackFile);

        // 优先用 fallback_cards 这个 assetKey；找不到会 scan-any-asset + legacy ctx（取决于 env 开关）
        $doc = $this->loadJsonByBasenamePreferAssetKey('fallback_cards', $basename);
        $this->lightSchemaCheck($doc, $basename);

        $norm = $this->normalizeCardsDocFromRawDoc($doc, $basename, $section);

        return is_array($norm['items'] ?? null) ? $norm['items'] : [];
    }

    // =========================
    // ✅ New: Highlights Strategy loaders (pools/rules/policy)
    // =========================

    /**
     * ✅ highlight pools：
     * 文件名固定：report_highlights_pools.json
     *
     * 统一返回：
     * [
     *   'schema' => ...,
     *   'items'  => [
     *      'strength' => [tmpl...],
     *      'blindspot' => [tmpl...],
     *      'action' => [tmpl...],
     *   ]
     * ]
     */
    public function loadHighlightPools(): array
    {
        $basename = 'report_highlights_pools.json';

        $ck = $this->makePackCacheKey('highlight_pools');
        if (isset($this->cacheHighlightPools[$ck])) {
            return $this->cacheHighlightPools[$ck];
        }

        // 优先用 highlight_pools 这个 assetKey；找不到会 scan-any-asset + legacy ctx（取决于 env 开关）
        $doc = $this->loadJsonByBasenamePreferAssetKey('highlight_pools', $basename);
        $this->lightSchemaCheck($doc, $basename);

        $norm = $this->normalizeHighlightPoolsDoc($doc);

        $this->cacheHighlightPools[$ck] = $norm;

        return $norm;
    }

    /**
     * ✅ highlight rules：
     * 文件名固定：report_highlights_rules.json
     *
     * 统一返回：
     * [
     *   'schema' => ...,
     *   'rules'  => [ ... ]
     * ]
     *
     * 兼容两种结构：
     * A) { "rules": [ ... ] }
     * B) [ ... ]
     */
    public function loadHighlightRules(): array
    {
        $basename = 'report_highlights_rules.json';

        $ck = $this->makePackCacheKey('highlight_rules');
        if (isset($this->cacheHighlightRules[$ck])) {
            return $this->cacheHighlightRules[$ck];
        }

        // 优先用 highlight_rules 这个 assetKey；找不到会 scan-any-asset + legacy ctx（取决于 env 开关）
        $doc = $this->loadJsonByBasenamePreferAssetKey('highlight_rules', $basename);
        $this->lightSchemaCheck($doc, $basename);

        $norm = $this->normalizeHighlightRulesDoc($doc);

        $this->cacheHighlightRules[$ck] = $norm;

        return $norm;
    }

    /**
     * ✅ highlight policy（可选）：
     * 文件名固定：report_highlights_policy.json
     *
     * 统一返回：
     * [
     *   'schema' => ...,
     *   'items'  => [ ...policy... ]
     * ]
     *
     * 兼容两种结构：
     * A) { "items": { ... } }
     * B) { ... }  // 直接就是 policy map
     */
    public function loadHighlightPolicy(): array
    {
        $basename = 'report_highlights_policy.json';

        $ck = $this->makePackCacheKey('highlight_policy');
        if (isset($this->cacheHighlightPolicy[$ck])) {
            return $this->cacheHighlightPolicy[$ck];
        }

        // 优先用 highlight_policy 这个 assetKey；找不到会 scan-any-asset + legacy ctx（取决于 env 开关）
        $doc = $this->loadJsonByBasenamePreferAssetKey('highlight_policy', $basename);
        $this->lightSchemaCheck($doc, $basename);

        $norm = $this->normalizeHighlightPolicyDoc($doc);

        $this->cacheHighlightPolicy[$ck] = $norm;

        return $norm;
    }

    public function loadSelectRules(): array
    {
        $compiledRules = $this->loadCompiledRulesDoc();
        if (is_array($compiledRules)) {
            $rules = $compiledRules['rules'] ?? $compiledRules;

            return is_array($rules) ? $rules : [];
        }

        // 规则文件名固定
        $filename = 'report_select_rules.json';

        // pack chain：primary -> fallback，找到第一个存在的就用
        foreach ($this->chain as $pack) {
            if (! ($pack instanceof ContentPack)) {
                continue;
            }

            $path = rtrim($pack->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
            if (is_string($path) && $path !== '' && is_file($path)) {
                $json = $this->readJsonFromPath($pack, $filename, $path);
                if (! is_array($json)) {
                    return [];
                }

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

        if ((bool) \App\Support\RuntimeConfig::value('FAP_FORBID_MISSING_HIGHLIGHTS', false) && empty($doc)) {
            throw new \RuntimeException('STORE_HIGHLIGHTS_MISSING: report_highlights_templates.json not found');
        }

        return $doc;
    }

    /** reads doc（返回标准化后的 items 结构） */
    public function loadReads(): array
    {
        $doc = $this->loadJsonByBasenamePreferAssetKey('reads', 'report_recommended_reads.json');
        $this->lightSchemaCheck($doc, 'report_recommended_reads.json');

        if ((bool) config('fap.content.forbid_missing_reads', false) && empty($doc)) {
            throw new \RuntimeException('STORE_READS_MISSING: report_recommended_reads.json not found');
        }

        if (! is_array($doc['items'] ?? null)) {
            $doc['items'] = [];
        }

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

    public function loadCommercialSpec(): array
    {
        $doc = $this->loadJsonByBasenamePreferAssetKey('rules', 'commercial_spec.json');
        $this->lightSchemaCheck($doc, 'commercial_spec.json');

        $doc['variants'] = is_array($doc['variants'] ?? null) ? array_values($doc['variants']) : [];
        $doc['offers'] = is_array($doc['offers'] ?? null) ? array_values($doc['offers']) : [];

        return $doc;
    }

    /**
     * ✅ report_overrides.json（统一覆写器入口用）
     * - 必须走当前 pack chain（含 fallback chain）
     * - 与 self-check/manifest.assets 对齐（固定 basename：report_overrides.json）
     * - 若不存在：返回空规则（不抛错，避免影响线上）
     *
     * 返回固定结构：
     * [
     *   'schema' => 'fap.report.overrides.v1',
     *   'rules' => [...],
     *   '__src_chain' => [...],
     * ]
     */
    public function loadReportOverrides(): array
    {
        $basename = 'report_overrides.json';

        // ✅ 复用 overrides 的“按 chain + order bucket”加载（能保留 __src / rule.__src）
        $docs = $this->loadOverridesDocsOrderedFromChain();

        // 只取 basename = report_overrides.json 的 doc（manifest.assets 一致）
        $picked = [];
        foreach ($docs as $d) {
            if (! is_array($d)) {
                continue;
            }

            $src = $d['__src'] ?? null;
            $file = is_array($src) ? (string) ($src['file'] ?? '') : '';
            if ($file !== '' && basename($file) === $basename) {
                $picked[] = $d;

                continue;
            }

            // 兜底：有些 doc 可能没 __src.file，但 rules 里有 __src.file
            $rs = $d['rules'] ?? ($d['overrides'] ?? null);
            if (is_array($rs)) {
                foreach ($rs as $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    $rsrc = $r['__src'] ?? null;
                    $rfile = is_array($rsrc) ? (string) ($rsrc['file'] ?? '') : '';
                    if ($rfile !== '' && basename($rfile) === $basename) {
                        $picked[] = $d;
                        break;
                    }
                }
            }
        }

        // ✅ 不存在：返回空规则（不抛错）
        if (empty($picked)) {
            return [
                'schema' => 'fap.report.overrides.v1',
                'rules' => [],
                '__src_chain' => [],
            ];
        }

        // 合并（沿用你 loadOverrides() 的 merge 口径，但只合并 report_overrides.json）
        $merged = [
            'schema' => 'fap.report.overrides.v1',
            'rules' => [],
            '__src_chain' => [],
        ];

        foreach ($picked as $d) {
            if (! is_array($d)) {
                continue;
            }

            // 归一化：overrides -> rules
            if (! is_array($d['rules'] ?? null) && is_array($d['overrides'] ?? null)) {
                $d['rules'] = $d['overrides'];
            }

            if (is_array($d['rules'] ?? null)) {
                foreach ($d['rules'] as $r) {
                    if (! is_array($r)) {
                        continue;
                    }

                    // defaults
                    if (! isset($r['tags']) || ! is_array($r['tags'])) {
                        $r['tags'] = [];
                    }
                    if (! isset($r['priority']) || ! is_numeric($r['priority'])) {
                        $r['priority'] = 0;
                    }
                    if (! isset($r['rules']) || ! is_array($r['rules'])) {
                        $r['rules'] = [];
                    } // 某些 target 用得到

                    $merged['rules'][] = $r;
                }
            }

            if (is_array($d['__src'] ?? null)) {
                $merged['__src_chain'][] = $d['__src'];
            }
        }

        return $merged;
    }

    /** overrides（返回合并后的统一 doc：{schema,rules,__src_chain}，并补默认字段） */
    public function loadOverrides(): ?array
    {
        $docs = $this->loadOverridesDocsOrderedFromChain();

        if (empty($docs)) {
            if ((bool) config('fap.content.forbid_missing_overrides', false)) {
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
            if (! is_array($d)) {
                continue;
            }

            if (is_array($d['rules'] ?? null)) {
                foreach ($d['rules'] as $r) {
                    if (! is_array($r)) {
                        continue;
                    }
                    // defaults
                    if (! isset($r['tags']) || ! is_array($r['tags'])) {
                        $r['tags'] = [];
                    }
                    if (! isset($r['priority']) || ! is_numeric($r['priority'])) {
                        $r['priority'] = 0;
                    }
                    if (! isset($r['rules']) || ! is_array($r['rules'])) {
                        $r['rules'] = [];
                    } // 某些 target 用得到
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
            if (! $p instanceof ContentPack) {
                continue;
            }
            $ov = $p->assets()['overrides'] ?? null;
            if (! is_array($ov) || $ov === []) {
                continue;
            }

            // list: 默认 unified
            if ($this->isListArray($ov)) {
                return ['unified'];
            }

            $order = $ov['order'] ?? null;
            if (is_array($order) && $order !== []) {
                $out = [];
                foreach ($order as $x) {
                    if (is_string($x) && trim($x) !== '') {
                        $out[] = $x;
                    }
                }

                return $out ?: ['highlights_legacy', 'unified'];
            }

            // no order -> keys except order
            $out = [];
            foreach ($ov as $k => $_) {
                if ($k === 'order') {
                    continue;
                }
                if (is_string($k) && trim($k) !== '') {
                    $out[] = $k;
                }
            }

            return $out ?: ['highlights_legacy', 'unified'];
        }

        return ['highlights_legacy', 'unified'];
    }

    // =========================
    // Internal: highlights helpers
    // =========================

    /**
     * cache key = kind|pack_id|locale|version
     * - pack_id / version：从 chain 里第一个 ContentPack 取（primary pack）
     * - locale：尽量从 pack 上取；取不到就用 pack_id 兜底（pack_id 里通常已含 locale）
     */
    private function makePackCacheKey(string $kind): string
    {
        $packId = 'unknown_pack';
        $ver = 'unknown_ver';
        $locale = 'unknown_locale';

        foreach ($this->chain as $p) {
            if (! $p instanceof ContentPack) {
                continue;
            }

            $packId = is_string($p->packId()) ? $p->packId() : $packId;
            $ver = is_string($p->version()) ? $p->version() : $ver;

            // 尽量从 pack 上拿 locale（不强依赖方法存在）
            if (method_exists($p, 'locale')) {
                $v = $p->locale();
                if (is_string($v) && trim($v) !== '') {
                    $locale = trim($v);
                } else {
                    $locale = $packId; // 兜底：pack_id 通常已包含 locale 信息
                }
            } else {
                $locale = $packId; // 兜底
            }

            break; // primary pack only
        }

        return $kind.'|'.$packId.'|'.$locale.'|'.$ver;
    }

    private function normalizeHighlightPoolsDoc(array $doc): array
    {
        // 兼容：items / pools
        $raw = $doc['items'] ?? ($doc['pools'] ?? null);

        $out = [
            'schema' => is_string($doc['schema'] ?? null) ? (string) $doc['schema'] : null,
            'items' => [
                'strength' => [],
                'blindspot' => [],
                'action' => [],
            ],
        ];

        if (! is_array($raw)) {
            return $out;
        }

        // 结构 A：{strength:[...], blindspot:[...], action:[...]}
        if (! $this->isListArray($raw)) {
            foreach (['strength', 'blindspot', 'action'] as $pool) {
                $list = $raw[$pool] ?? [];
                if (! is_array($list)) {
                    $list = [];
                }
                $out['items'][$pool] = $this->normalizeHighlightTemplateList($list, $pool);
            }

            return $out;
        }

        // 结构 B：list[ {id,pool,title,body,tags,...}, ... ] -> regroup
        foreach ($raw as $it) {
            if (! is_array($it)) {
                continue;
            }

            $pool = $it['pool'] ?? null;
            $pool = is_string($pool) ? trim($pool) : '';
            if (! in_array($pool, ['strength', 'blindspot', 'action'], true)) {
                continue;
            }

            $norm = $this->normalizeHighlightTemplateItem($it, $pool);
            if ($norm !== null) {
                $out['items'][$pool][] = $norm;
            }
        }

        return $out;
    }

    private function normalizeHighlightRulesDoc(array $doc): array
    {
        // 支持两种：{"rules":[...]} 或 直接 list
        $rules = $doc['rules'] ?? $doc;
        if (! is_array($rules)) {
            $rules = [];
        }

        $out = [
            'schema' => is_string($doc['schema'] ?? null) ? (string) $doc['schema'] : null,
            'rules' => [],
        ];

        foreach ($rules as $idx => $r) {
            if (! is_array($r)) {
                continue;
            }

            $pool = $r['pool'] ?? null;
            $pool = is_string($pool) ? trim($pool) : '';
            if (! in_array($pool, ['strength', 'blindspot', 'action'], true)) {
                continue;
            }

            $pick = $r['pick_ids'] ?? ($r['pick'] ?? null);
            if (! is_array($pick)) {
                $pick = [];
            }
            $pick = array_values(array_filter($pick, fn ($x) => is_string($x) && trim($x) !== ''));

            if ($pick === []) {
                continue;
            }

            $explain = $r['explain'] ?? null;
            if (is_array($explain)) {
                $explain = array_values(array_filter($explain, fn ($x) => is_string($x) && trim($x) !== ''));
                $explain = $explain ?: null;
            } elseif (! is_string($explain)) {
                $explain = null;
            }

            // tags / priority 可选，沿用你现有 rules 风格
            $tags = $r['tags'] ?? [];
            if (! is_array($tags)) {
                $tags = [];
            }
            $tags = array_values(array_filter($tags, fn ($x) => is_string($x) && trim($x) !== ''));

            $priority = $r['priority'] ?? 0;
            $priority = is_numeric($priority) ? (int) $priority : 0;

            $out['rules'][] = [
                'pool' => $pool,
                'pick_ids' => $pick,
                'tags' => $tags,
                'priority' => $priority,
                'explain' => $explain,
            ];
        }

        return $out;
    }

    private function normalizeHighlightPolicyDoc(array $doc): array
    {
        // 兼容：items / policy / root map
        $items = $doc['items'] ?? ($doc['policy'] ?? null);
        if (! is_array($items)) {
            // root map 直接当 items
            $items = $doc;
            if (! is_array($items)) {
                $items = [];
            }
        }

        return [
            'schema' => is_string($doc['schema'] ?? null) ? (string) $doc['schema'] : null,
            'items' => $items,
        ];
    }

    private function normalizeHighlightTemplateList(array $list, string $pool): array
    {
        $out = [];
        foreach ($list as $it) {
            if (! is_array($it)) {
                continue;
            }
            $norm = $this->normalizeHighlightTemplateItem($it, $pool);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }

        return $out;
    }

    private function normalizeHighlightTemplateItem(array $it, string $pool): ?array
    {
        $id = $it['id'] ?? null;
        $id = is_string($id) ? trim($id) : '';
        if ($id === '') {
            return null;
        }

        $title = $it['title'] ?? '';
        $title = is_string($title) ? (string) $title : '';

        $body = $it['body'] ?? ($it['desc'] ?? '');
        $body = is_string($body) ? (string) $body : '';

        $tags = $it['tags'] ?? [];
        if (! is_array($tags)) {
            $tags = [];
        }
        $tags = array_values(array_filter($tags, fn ($x) => is_string($x) && trim($x) !== ''));

        $constraints = $it['constraints'] ?? [];
        if (! is_array($constraints)) {
            $constraints = [];
        }

        return [
            'id' => $id,
            'pool' => $pool,
            'title' => $title,
            'body' => $body,
            'tags' => $tags,
            'constraints' => $constraints,
        ];
    }

    /**
     * ✅ 复用：把任意 cards doc（含 fallback）标准化成固定结构：
     * ['items'=>normItems, 'rules'=>rules]
     */
    private function normalizeCardsDocFromRawDoc(array $doc, string $basename, string $section): array
    {
        // items 兼容：items / cards
        $items = $doc['items'] ?? ($doc['cards'] ?? null);
        if (! is_array($items)) {
            $items = [];
        }

        // ✅ 标准化 items（缺省补齐 + 类型稳定 + 默认 tips 注入）
        $normItems = [];
        foreach ($items as $idx => $it) {
            if (! is_array($it)) {
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
            if (! isset($it['section']) || ! is_string($it['section']) || trim($it['section']) === '') {
                $it['section'] = $section;
            } else {
                $it['section'] = trim((string) $it['section']);
            }

            $it['access_level'] = ReportAccess::normalizeCardAccessLevel(
                is_string($it['access_level'] ?? null) ? (string) $it['access_level'] : null
            );

            $moduleCode = trim((string) ($it['module_code'] ?? ''));
            if ($moduleCode === '') {
                $moduleCode = ReportAccess::defaultModuleCodeForSection((string) $it['section']);
            }
            $it['module_code'] = strtolower($moduleCode);

            // title/desc
            $it['title'] = is_string($it['title'] ?? null) ? (string) $it['title'] : '';
            $it['desc'] = is_string($it['desc'] ?? null) ? (string) $it['desc'] : '';

            // bullets: array[string]
            if (! is_array($it['bullets'] ?? null)) {
                $it['bullets'] = [];
            }
            $it['bullets'] = array_values(array_filter(
                $it['bullets'],
                fn ($x) => is_string($x) && trim($x) !== ''
            ));

            // tips: array[string]
            if (! is_array($it['tips'] ?? null)) {
                $it['tips'] = [];
            }
            $it['tips'] = array_values(array_filter(
                $it['tips'],
                fn ($x) => is_string($x) && trim($x) !== ''
            ));

            // tags: array[string]
            if (! isset($it['tags']) || ! is_array($it['tags'])) {
                $it['tags'] = [];
            }
            $it['tags'] = array_values(array_filter(
                $it['tags'],
                fn ($x) => is_string($x) && trim($x) !== ''
            ));

            // rules: array
            if (! isset($it['rules']) || ! is_array($it['rules'])) {
                $it['rules'] = [];
            }

            // priority: int
            if (! isset($it['priority']) || ! is_numeric($it['priority'])) {
                $it['priority'] = 0;
            }
            $it['priority'] = (int) $it['priority'];

            // match：保证键存在（generator 会读取）
            if (! array_key_exists('match', $it)) {
                $it['match'] = null;
            }

            // ✅ 默认 tips 注入点
            $it = ReportContentNormalizer::fillTipsIfMissing($it);

            $normItems[] = $it;
        }

        // ✅ rules 缺省补齐（fallback 文件一般也允许带 rules；没带就用默认）
        $rules = is_array($doc['rules'] ?? null) ? $doc['rules'] : [];

        $min = isset($rules['min_cards']) && is_numeric($rules['min_cards']) ? (int) $rules['min_cards'] : 2;
        $min = max(2, $min);

        $target = isset($rules['target_cards']) && is_numeric($rules['target_cards']) ? (int) $rules['target_cards'] : 3;
        if ($target < $min) {
            $target = $min;
        }

        $max = isset($rules['max_cards']) && is_numeric($rules['max_cards']) ? (int) $rules['max_cards'] : 6;
        if ($max < $target) {
            $max = $target;
        }

        $fallbackTags = $rules['fallback_tags'] ?? ['fallback', 'kind:core'];
        if (! is_array($fallbackTags)) {
            $fallbackTags = ['fallback', 'kind:core'];
        }
        $fallbackTags = array_values(array_filter($fallbackTags, fn ($x) => is_string($x) && trim($x) !== ''));
        if ($fallbackTags === []) {
            $fallbackTags = ['fallback', 'kind:core'];
        }

        $rules = [
            'min_cards' => $min,
            'target_cards' => $target,
            'max_cards' => $max,
            'fallback_tags' => $fallbackTags,
        ];

        return [
            'items' => $normItems,
            'rules' => $rules,
        ];
    }

    private function loadCompiledCardsDoc(string $section): ?array
    {
        foreach ($this->chain as $pack) {
            if (! ($pack instanceof ContentPack)) {
                continue;
            }

            $relPath = 'compiled/cards.normalized.json';
            $abs = rtrim($pack->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relPath;
            if (! is_file($abs)) {
                continue;
            }

            $json = $this->readJsonFromPath($pack, $relPath, $abs);
            if (! is_array($json)) {
                continue;
            }

            $sections = $json['sections'] ?? null;
            if (is_array($sections) && is_array($sections[$section] ?? null)) {
                Log::info('[STORE] compiled_cards_hit', [
                    'pack_id' => $pack->packId(),
                    'version' => $pack->version(),
                    'section' => $section,
                ]);

                return $sections[$section];
            }

            if (is_array($json['items'] ?? null) && is_array($json['rules'] ?? null)) {
                Log::info('[STORE] compiled_cards_hit_legacy', [
                    'pack_id' => $pack->packId(),
                    'version' => $pack->version(),
                    'section' => $section,
                ]);

                return $json;
            }
        }

        return null;
    }

    private function loadCompiledRulesDoc(): ?array
    {
        foreach ($this->chain as $pack) {
            if (! ($pack instanceof ContentPack)) {
                continue;
            }

            $relPath = 'compiled/rules.normalized.json';
            $abs = rtrim($pack->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relPath;
            if (! is_file($abs)) {
                continue;
            }

            $json = $this->readJsonFromPath($pack, $relPath, $abs);
            if (! is_array($json)) {
                continue;
            }

            Log::info('[STORE] compiled_rules_hit', [
                'pack_id' => $pack->packId(),
                'version' => $pack->version(),
            ]);

            return $json;
        }

        return null;
    }

    private function loadCompiledSectionsSpec(): ?array
    {
        foreach ($this->chain as $pack) {
            if (! ($pack instanceof ContentPack)) {
                continue;
            }

            $relPath = 'compiled/sections.spec.json';
            $abs = rtrim($pack->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relPath;
            if (! is_file($abs)) {
                continue;
            }

            $json = $this->readJsonFromPath($pack, $relPath, $abs);
            if (! is_array($json)) {
                continue;
            }

            Log::info('[STORE] compiled_sections_hit', [
                'pack_id' => $pack->packId(),
                'version' => $pack->version(),
            ]);

            return $json;
        }

        return null;
    }

    private function loadJsonByBasenamePreferAssetKey(string $assetKey, string $basename): array
    {
        // 1) 优先：assetKey 下找 basename
        $doc = $this->loadJsonFromChainByAssetKeyAndBasename($assetKey, $basename);
        if ($doc !== null) {
            return $doc;
        }

        // 🔥 爆炸验证 1：禁止“扫描所有 assets”兜底
        if ($this->isRuntimeEnvFlagEnabled('FAP_FORBID_STORE_ASSET_SCAN')) {
            throw new \RuntimeException("STORE_ASSET_SCAN_FORBIDDEN: asset={$assetKey} file={$basename}");
        }

        // 2) 次选：扫描所有 assets 找 basename（容错）
        $doc = $this->loadJsonFromChainByAnyAssetAndBasename($basename);
        if ($doc !== null) {
            return $doc;
        }

        // 🔥 爆炸验证 2：禁止 legacy ctx loader 兜底
        if (is_callable($this->ctx['loadReportAssetJson'] ?? null) && $this->legacyDir !== '') {
            if ($this->isRuntimeEnvFlagEnabled('FAP_FORBID_LEGACY_CTX_LOADER')) {
                throw new \RuntimeException("LEGACY_CTX_LOADER_FORBIDDEN: asset={$assetKey} file={$basename}");
            }

            $raw = ($this->ctx['loadReportAssetJson'])($this->legacyDir, $basename);
            if (is_object($raw)) {
                $raw = json_decode(json_encode($raw, JSON_UNESCAPED_UNICODE), true);
            }
            if (is_array($raw)) {
                $doc = $raw['doc'] ?? $raw['data'] ?? $raw;
                if (is_array($doc)) {
                    return $doc;
                }
            }
        }

        Log::warning('[STORE] json_not_found', ['asset' => $assetKey, 'file' => $basename]);

        return [];
    }

    private function isRuntimeEnvFlagEnabled(string $name): bool
    {
        $value = \App\Support\RuntimeConfig::raw($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        if ($value === null) {
            $value = \App\Support\RuntimeConfig::value($name, false);
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function loadJsonFromChainByAssetKeyAndBasename(string $assetKey, string $basename): ?array
    {
        foreach ($this->chain as $p) {
            if (! $p instanceof ContentPack) {
                continue;
            }

            $assets = $p->assets();
            $val = $assets[$assetKey] ?? null;

            $paths = $this->flattenAssetPaths($val);
            foreach ($paths as $rel) {
                if (! is_string($rel) || trim($rel) === '') {
                    continue;
                }
                if (basename($rel) !== $basename) {
                    continue;
                }

                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$rel;
                if (! is_file($abs)) {
                    continue;
                }

                $json = $this->readJsonFromPath($p, $rel, $abs);
                if (! is_array($json)) {
                    continue;
                }

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
            if (! $p instanceof ContentPack) {
                continue;
            }

            foreach (($p->assets() ?? []) as $assetKey => $val) {
                $paths = $this->flattenAssetPaths($val);
                foreach ($paths as $rel) {
                    if (! is_string($rel) || trim($rel) === '') {
                        continue;
                    }
                    if (basename($rel) !== $basename) {
                        continue;
                    }

                    $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$rel;
                    if (! is_file($abs)) {
                        continue;
                    }

                    $json = $this->readJsonFromPath($p, $rel, $abs);
                    if (! is_array($json)) {
                        continue;
                    }

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
            if (! $p instanceof ContentPack) {
                continue;
            }

            $assetVal = $p->assets()['overrides'] ?? null;
            if (! is_array($assetVal) || $assetVal === []) {
                continue;
            }

            $orderedPaths = $this->getOverridesOrderedPaths($assetVal);

            foreach ($orderedPaths as $rel) {
                if (! is_string($rel) || trim($rel) === '') {
                    continue;
                }

                $abs = rtrim($p->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$rel;
                if (! is_file($abs)) {
                    continue;
                }

                $json = $this->readJsonFromPath($p, $rel, $abs);
                if (! is_array($json)) {
                    continue;
                }

                // 归一化：overrides -> rules
                if (! is_array($json['rules'] ?? null) && is_array($json['overrides'] ?? null)) {
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
                        if (is_array($r)) {
                            $r['__src'] = $src;
                        }
                        if (is_array($r)) {
                            if (! isset($r['tags']) || ! is_array($r['tags'])) {
                                $r['tags'] = [];
                            }
                            if (! isset($r['priority']) || ! is_numeric($r['priority'])) {
                                $r['priority'] = 0;
                            }
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
            return array_values(array_filter($assetVal, fn ($x) => is_string($x) && trim($x) !== ''));
        }

        // map + order
        $order = $assetVal['order'] ?? null;
        $out = [];

        if (is_array($order) && $order !== []) {
            foreach ($order as $bucket) {
                if (! is_string($bucket) || $bucket === '') {
                    continue;
                }
                $v = $assetVal[$bucket] ?? null;
                if (! is_array($v)) {
                    continue;
                }
                foreach ($v as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $out[] = $path;
                    }
                }
            }

            return array_values(array_unique($out));
        }

        // no order
        foreach ($assetVal as $k => $v) {
            if ($k === 'order') {
                continue;
            }
            if (! is_array($v)) {
                continue;
            }
            foreach ($v as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $out[] = $path;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function flattenAssetPaths($assetVal): array
    {
        if (! is_array($assetVal)) {
            return [];
        }

        if ($this->isListArray($assetVal)) {
            return array_values(array_filter($assetVal, fn ($x) => is_string($x) && trim($x) !== ''));
        }

        // map (e.g. overrides)
        $out = [];
        foreach ($assetVal as $k => $v) {
            if ($k === 'order') {
                continue;
            }
            $list = is_array($v) ? $v : [$v];
            foreach ($list as $x) {
                if (is_string($x) && trim($x) !== '') {
                    $out[] = $x;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function isListArray(array $a): bool
    {
        if ($a === []) {
            return true;
        }

        return array_keys($a) === range(0, count($a) - 1);
    }

    private function lightSchemaCheck(array $doc, string $file): void
    {
        // 轻量：只做“存在 & 类型”校验，不做强阻断
        if (! is_array($doc)) {
            Log::warning('[STORE] schema_bad', ['file' => $file, 'reason' => 'doc_not_array']);

            return;
        }
        if (isset($doc['schema']) && ! is_string($doc['schema'])) {
            Log::warning('[STORE] schema_bad', ['file' => $file, 'reason' => 'schema_not_string']);
        }
    }

    private function normalizeReadBuckets(array $items): array
    {
        $normList = function ($list) {
            if (! is_array($list)) {
                return [];
            }

            $out = [];
            foreach ($list as $it) {
                if (! is_array($it)) {
                    continue;
                }

                $out[] = ReportContentNormalizer::read($it);
            }

            return $out;
        };

        // by_type/by_role/by_strategy/by_top_axis 都是 map -> list
        foreach (['by_type', 'by_role', 'by_strategy', 'by_top_axis'] as $k) {
            $m = $items[$k] ?? [];
            if (! is_array($m)) {
                $items[$k] = [];

                continue;
            }
            foreach ($m as $key => $list) {
                $m[$key] = $normList($list);
            }
            $items[$k] = $m;
        }

        $items['fallback'] = $normList($items['fallback'] ?? []);

        return $items;
    }
}
