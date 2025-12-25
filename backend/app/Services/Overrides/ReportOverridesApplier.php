<?php

declare(strict_types=1);

namespace App\Services\Overrides;

use Illuminate\Support\Facades\Log;

class ReportOverridesApplier
{
    /**
     * Unified entry: highlights
     */
    public function applyHighlights(string $contentPackageDir, string $typeCode, array $highlights, array $ctx = []): array
    {
        $doc = $this->loadOverridesDoc($contentPackageDir, $ctx);
        $rules = $this->filterRulesByTarget($doc, 'highlights');

        $context = [
            'target' => 'highlights',
            'type_code' => $typeCode,
            'content_package_dir' => $contentPackageDir,
            'section_key' => null,
            'ctx' => $ctx,
        ];

        return $this->applyRulesToList($highlights, $rules, $context);
    }

    /**
     * Unified entry: section cards
     */
    public function applyCards(string $contentPackageDir, string $typeCode, string $sectionKey, array $cards, array $ctx = []): array
    {
        $doc = $this->loadOverridesDoc($contentPackageDir, $ctx);
        $rules = $this->filterRulesByTarget($doc, 'cards');

        $context = [
            'target' => 'cards',
            'type_code' => $typeCode,
            'content_package_dir' => $contentPackageDir,
            'section_key' => $sectionKey,
            'ctx' => $ctx,
        ];

        return $this->applyRulesToList($cards, $rules, $context);
    }

    /**
     * Unified entry: recommended reads
     */
    public function applyReads(string $contentPackageDir, string $typeCode, array $reads, array $ctx = []): array
    {
        $doc = $this->loadOverridesDoc($contentPackageDir, $ctx);
        $rules = $this->filterRulesByTarget($doc, 'reads');

        $context = [
            'target' => 'reads',
            'type_code' => $typeCode,
            'content_package_dir' => $contentPackageDir,
            'section_key' => null,
            'ctx' => $ctx,
        ];

        return $this->applyRulesToList($reads, $rules, $context);
    }

    // ======================================================================
    // Engine internals
    // ======================================================================

    /**
     * Load report_overrides.json.
     *
     * Supported sources (best-effort):
     * - filesystem candidates (old dir / new content_packages layout)
     * - ctx loader: $ctx['loadReportAssetJson']($contentPackageDir, 'report_overrides.json')
     */
    private function loadOverridesDoc(string $contentPackageDir, array $ctx = []): ?array
    {
        $debug = (bool)($ctx['overrides_debug'] ?? false);

        // If caller passes an already-loaded doc
        if (isset($ctx['report_overrides_doc']) && is_array($ctx['report_overrides_doc'])) {
            return $ctx['report_overrides_doc'];
        }

        $repoRoot = realpath(base_path('..')) ?: dirname(base_path());

        $scaleCode = (string)($ctx['scale_code'] ?? '');
        $region    = (string)($ctx['region'] ?? '');
        $locale    = (string)($ctx['locale'] ?? '');
        $version   = (string)($ctx['content_package_version'] ?? $ctx['contentPackageVersion'] ?? '');

        $candidates = [];

        // New layout candidate (only if ctx has enough fields)
        if ($scaleCode !== '' && $region !== '' && $locale !== '' && $version !== '') {
            $tplNewRel = 'content_packages/' . $scaleCode . '/' . $region . '/' . $locale . '/' . $version . '/report_overrides.json';
            $candidates[] = base_path('../' . $tplNewRel);
            $candidates[] = $repoRoot . '/' . ltrim($tplNewRel, '/');
        }

        // Old layout candidates (relative to backend)
        $oldRel = rtrim($contentPackageDir, '/') . '/report_overrides.json';
        $candidates[] = $oldRel;                  // cwd relative (if you run inside backend and have symlink)
        $candidates[] = base_path($oldRel);        // backend/{MBTI-CN-...}/...
        $candidates[] = base_path('../' . $oldRel);// repoRoot/{MBTI-CN-...}/... (rare)

        // Extra: allow caller to inject custom candidates
        if (!empty($ctx['overrides_candidates']) && is_array($ctx['overrides_candidates'])) {
            foreach ($ctx['overrides_candidates'] as $p) {
                if (is_string($p) && $p !== '') $candidates[] = $p;
            }
        }

        if ($debug) {
            Log::info('[OVR] overrides_candidates', [
                'contentPackageDir' => $contentPackageDir,
                'repoRoot' => $repoRoot,
                'candidates' => array_map(fn($p) => [
                    'path' => $p,
                    'is_file' => is_string($p) ? is_file($p) : false,
                    'size' => (is_string($p) && is_file($p)) ? filesize($p) : null,
                ], $candidates),
            ]);
        }

        foreach ($candidates as $path) {
            if (!is_string($path) || !is_file($path)) continue;
            $raw = @file_get_contents($path);
            if ($raw === false) continue;

            $json = json_decode($raw, true);
            if (is_array($json)) {
                if ($debug) {
                    Log::info('[OVR] overrides_loaded', [
                        'path' => $path,
                        'schema' => $json['schema'] ?? null,
                        'rules_count' => is_array($json['rules'] ?? null) ? count($json['rules']) : null,
                    ]);
                }
                return $json;
            }

            if ($debug) {
                Log::warning('[OVR] overrides_json_decode_failed', [
                    'path' => $path,
                    'json_error' => json_last_error_msg(),
                ]);
            }
        }

        // Fallback: ctx loader
        if (is_callable($ctx['loadReportAssetJson'] ?? null)) {
            $raw = ($ctx['loadReportAssetJson'])($contentPackageDir, 'report_overrides.json');

            // normalize stdClass etc.
            if (is_object($raw)) {
                $raw = json_decode(json_encode($raw, JSON_UNESCAPED_UNICODE), true);
            }

            if (is_array($raw)) {
                $doc = $raw['doc'] ?? $raw['data'] ?? $raw;
                if (is_object($doc)) {
                    $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
                }
                if (is_array($doc)) {
                    if ($debug) {
                        Log::info('[OVR] overrides_loaded_from_ctx', [
                            'schema' => $doc['schema'] ?? null,
                            'rules_count' => is_array($doc['rules'] ?? null) ? count($doc['rules']) : null,
                        ]);
                    }
                    return $doc;
                }
            }
        }

        if ($debug) {
            Log::info('[OVR] overrides_doc_missing', [
                'contentPackageDir' => $contentPackageDir,
            ]);
        }

        return null;
    }

    /**
     * Select rules matching a target: highlights/cards/reads
     */
    private function filterRulesByTarget(?array $doc, string $target): array
    {
        if (!is_array($doc)) return [];

        $rules = $doc['rules'] ?? [];
        if (!is_array($rules)) return [];

        $out = [];
        foreach ($rules as $r) {
            if (!is_array($r)) continue;

            // rule may have 'target' (string) or 'targets' (array)
            $t = $r['target'] ?? null;
            $ts = $r['targets'] ?? null;

            $ok = false;
            if (is_string($t) && $t === $target) $ok = true;
            if (is_array($ts) && in_array($target, $ts, true)) $ok = true;

            if ($ok) $out[] = $r;
        }

        return $out;
    }

    /**
     * Apply rules to a list with context.
     */
    private function applyRulesToList(array $items, array $rules, array $context): array
{
    $debugPerRule = (bool)(($context['ctx']['overrides_debug'] ?? false));

    $list = $items;

    // ✅ 收集本次 target 实际改动/命中的 ids
    $appliedIds = [];

    foreach ($rules as $rule) {
        if (!is_array($rule)) continue;
        if (!$this->ruleMatchesContext($rule, $context)) continue;

        $mode = (string)($rule['mode'] ?? 'patch');
        $selector = $rule['selector'] ?? null;

        // ✅ 先算 matches（后面用于收集 ids + 执行）
        $matches = $this->selectIndexes($list, $selector);

        // ✅ 收集“这条 rule 影响到谁”
        if (!empty($matches)) {
            foreach ($matches as $idx) {
                $it = $list[$idx] ?? null;
                if (is_array($it)) {
                    $id = $it['id'] ?? null;
                    if (is_string($id) && $id !== '') $appliedIds[] = $id;
                }
            }
        } else {
            // upsert/append/prepend 这种可能是“新增项”，也收一下新增 items 的 id
            if (in_array($mode, ['append','prepend'], true) || $mode === 'upsert') {
                $newItems = $this->ruleItems($rule, $context) ?? [];
                foreach ($newItems as $x) {
                    if (!is_array($x)) continue;
                    $id = $x['id'] ?? null;
                    if (is_string($id) && $id !== '') $appliedIds[] = $id;
                }
            }
        }

        $beforeCount = count($list);

        // ✅ 用 matches 执行（下面第 3 步会改 applyRuleToList 的签名）
        $list = $this->applyRuleToList($list, $matches, $rule, $context);

        if ($debugPerRule) {
            Log::info('[OVR] rule_applied', [
                'id' => $rule['id'] ?? null,
                'target' => $context['target'] ?? null,
                'mode' => $mode,
                'before' => $beforeCount,
                'after' => count($list),
            ]);
        }
    }

    // ✅ 统一 applied 日志：不需要 overrides_debug，只要 RE_EXPLAIN=1 即可看到
    $appliedIds = array_values(array_unique(array_filter($appliedIds, fn($x)=>is_string($x) && $x !== '')));

    if (!empty($appliedIds) && $this->shouldExplain((array)($context['ctx'] ?? []))) {
        Log::info('[OVR] applied', [
            'target' => (string)($context['target'] ?? ''),
            'section_key' => $context['section_key'] ?? null,
            'type_code' => (string)($context['type_code'] ?? ''),
            'count' => count($appliedIds),
            'ids' => $appliedIds,
        ]);
    }

    return array_values($list);
}

    /**
     * Match rule against context.
     *
     * Supported match fields (best-effort):
     * - type_code (string)
     * - type_codes (array)
     * - section_key (string) / sections (array)  (only relevant for cards)
     * - locale/region/scale_code/content_package_dir (string or array forms)
     */
    private function ruleMatchesContext(array $rule, array $context): bool
    {
        $match = $rule['match'] ?? [];
        if ($match === null) return true; // allow match: null
        if (!is_array($match)) return true;

        $typeCode = (string)($context['type_code'] ?? '');
        $sectionKey = $context['section_key'] ?? null;

        // type_code
        if (isset($match['type_code']) && is_string($match['type_code'])) {
            if ($match['type_code'] !== $typeCode) return false;
        }
        if (isset($match['type_codes']) && is_array($match['type_codes'])) {
            if (!in_array($typeCode, $match['type_codes'], true)) return false;
        }

        // cards section
        if (isset($match['section_key']) && is_string($match['section_key'])) {
            if ((string)$sectionKey !== $match['section_key']) return false;
        }
        if (isset($match['sections']) && is_array($match['sections'])) {
            if (!in_array((string)$sectionKey, $match['sections'], true)) return false;
        }

        // generic string fields
        foreach (['locale', 'region', 'scale_code', 'content_package_dir'] as $k) {
            if (!array_key_exists($k, $match)) continue;

            $want = $match[$k];
            $have = (string)($context['ctx'][$k] ?? ($context[$k] ?? ''));

            if (is_string($want)) {
                if ($want !== $have) return false;
            } elseif (is_array($want)) {
                if (!in_array($have, $want, true)) return false;
            }
        }

        return true;
    }

    /**
     * Apply one rule to list based on mode.
     *
     * Modes:
     * - patch: patch + replace_fields on matched items
     * - replace: replace matched items with rule.items (or rule.item)
     * - remove: remove matched items
     * - append/prepend: add rule.items to end/start
     * - upsert: if matched -> patch, else -> append
     */
private function applyRuleToList(array $list, array $matches, array $rule, array $context): array    {
        $mode = (string)($rule['mode'] ?? 'patch');

        return match ($mode) {
            'append'  => $this->modeAppend($list, $rule, $context),
            'prepend' => $this->modePrepend($list, $rule, $context),
            'remove'  => $this->modeRemove($list, $matches),
            'replace' => $this->modeReplace($list, $matches, $rule, $context),
            'upsert'  => $this->modeUpsert($list, $matches, $rule, $context),
            default   => $this->modePatch($list, $matches, $rule, $context),
        };
    }

    // ======================================================================
    // Selector + modes
    // ======================================================================

    /**
     * selector examples:
     * - null => match all
     * - {"id":"xxx"} or {"ids":[...]}
     * - {"kind":"strength"}
     * - {"where":{"field":"kind","eq":"strength"}}
     * - {"index": 0} or {"indexes":[0,2]}
     */
    private function selectIndexes(array $list, $selector): array
    {
        $n = count($list);

        if ($selector === null) {
            return $n > 0 ? range(0, $n - 1) : [];
        }

        if (!is_array($selector)) {
            return [];
        }

        // by index
        if (isset($selector['index']) && is_int($selector['index'])) {
            $i = $selector['index'];
            return ($i >= 0 && $i < $n) ? [$i] : [];
        }
        if (isset($selector['indexes']) && is_array($selector['indexes'])) {
            $out = [];
            foreach ($selector['indexes'] as $i) {
                if (is_int($i) && $i >= 0 && $i < $n) $out[] = $i;
            }
            return array_values(array_unique($out));
        }

        // by id(s)
        $wantIds = [];
        if (isset($selector['id']) && is_string($selector['id'])) $wantIds[] = $selector['id'];
        if (isset($selector['ids']) && is_array($selector['ids'])) {
            foreach ($selector['ids'] as $id) {
                if (is_string($id) && $id !== '') $wantIds[] = $id;
            }
        }
        if (!empty($wantIds)) {
            $out = [];
            foreach ($list as $idx => $it) {
                if (!is_array($it)) continue;
                $id = $it['id'] ?? null;
                if (is_string($id) && in_array($id, $wantIds, true)) $out[] = (int)$idx;
            }
            return $out;
        }

        // by kind
        if (isset($selector['kind']) && is_string($selector['kind'])) {
            $k = $selector['kind'];
            $out = [];
            foreach ($list as $idx => $it) {
                if (!is_array($it)) continue;
                if (($it['kind'] ?? null) === $k) $out[] = (int)$idx;
            }
            return $out;
        }

        // generic where
        if (isset($selector['where']) && is_array($selector['where'])) {
            $field = $selector['where']['field'] ?? null;
            $eq    = $selector['where']['eq'] ?? null;
            if (is_string($field) && $field !== '' && $eq !== null) {
                $out = [];
                foreach ($list as $idx => $it) {
                    if (!is_array($it)) continue;
                    if (($it[$field] ?? null) === $eq) $out[] = (int)$idx;
                }
                return $out;
            }
        }

        return [];
    }

    private function modePatch(array $list, array $matches, array $rule, array $context): array
    {
        if (empty($matches)) return $list;

        $patch = $rule['patch'] ?? null;
        $replaceFields = $rule['replace_fields'] ?? null;

        foreach ($matches as $idx) {
            $it = $list[$idx] ?? null;
            if (!is_array($it)) continue;

            if (is_array($replaceFields)) {
                $it = $this->applyReplaceFields($it, $replaceFields, $context);
            }
            if (is_array($patch)) {
                $it = $this->deepMerge($it, $patch);
            }

            $list[$idx] = $it;
        }

        return $list;
    }

    private function modeRemove(array $list, array $matches): array
    {
        if (empty($matches)) return $list;
        $kill = array_flip($matches);

        $out = [];
        foreach ($list as $i => $it) {
            if (isset($kill[$i])) continue;
            $out[] = $it;
        }
        return $out;
    }

    private function modeReplace(array $list, array $matches, array $rule, array $context): array
    {
        if (empty($matches)) return $list;

        $replacement = $this->ruleItems($rule, $context);
        if ($replacement === null) return $list;

        // Replace ALL matched items with replacement sequence (once).
        // Strategy: remove matched, then insert replacement at the first matched index.
        sort($matches);
        $insertAt = $matches[0];

        $kill = array_flip($matches);
        $out = [];
        foreach ($list as $i => $it) {
            if ($i === $insertAt) {
                foreach ($replacement as $x) $out[] = $x;
            }
            if (isset($kill[$i])) continue;
            $out[] = $it;
        }

        // If insertAt was beyond bounds (shouldn't happen), append
        if ($insertAt >= count($list)) {
            foreach ($replacement as $x) $out[] = $x;
        }

        return $out;
    }

    private function modeAppend(array $list, array $rule, array $context): array
    {
        $items = $this->ruleItems($rule, $context);
        if ($items === null) return $list;

        foreach ($items as $x) $list[] = $x;
        return $list;
    }

    private function modePrepend(array $list, array $rule, array $context): array
    {
        $items = $this->ruleItems($rule, $context);
        if ($items === null) return $list;

        return array_values(array_merge($items, $list));
    }

    private function modeUpsert(array $list, array $matches, array $rule, array $context): array
    {
        // If matched -> patch; else -> append new items
        if (!empty($matches)) {
            return $this->modePatch($list, $matches, $rule, $context);
        }
        return $this->modeAppend($list, $rule, $context);
    }

    /**
     * Normalize rule's item(s) to list of arrays.
     * Allowed:
     * - rule.items: array of objects
     * - rule.item: single object
     * - rule.replace: same as above
     */
    private function ruleItems(array $rule, array $context): ?array
    {
        $items = $rule['items'] ?? null;

        if ($items === null && isset($rule['item'])) {
            $items = [$rule['item']];
        }
        if ($items === null && isset($rule['replace'])) {
            $items = $rule['replace'];
        }

        // If replace is a single object
        if (is_array($items) && $this->isAssoc($items)) {
            $items = [$items];
        }

        if (!is_array($items)) return null;

        // Ensure each item is an array and apply replace_fields/patch at item-level if provided
        $out = [];
        foreach ($items as $x) {
            if (is_object($x)) {
                $x = json_decode(json_encode($x, JSON_UNESCAPED_UNICODE), true);
            }
            if (!is_array($x)) continue;

            // optional: allow per-item patch/replace_fields via rule defaults
            if (isset($rule['replace_fields']) && is_array($rule['replace_fields'])) {
                $x = $this->applyReplaceFields($x, $rule['replace_fields'], $context);
            }
            if (isset($rule['patch']) && is_array($rule['patch'])) {
                $x = $this->deepMerge($x, $rule['patch']);
            }

            $out[] = $x;
        }

        return $out;
    }

    // ======================================================================
    // Helpers: replace_fields + deep merge
    // ======================================================================

    /**
     * replace_fields: set / overwrite fields on an item.
     * Supports simple placeholders in strings:
     * - {{type_code}}, {{section_key}}, {{content_package_dir}}
     */
    private function applyReplaceFields(array $item, array $replaceFields, array $context): array
    {
        foreach ($replaceFields as $k => $v) {
            if (!is_string($k) || $k === '') continue;

            if (is_string($v)) {
                $v = $this->renderTemplateString($v, $context);
            }

            // dot-path supported: "meta.title"
            $this->setByDotPath($item, $k, $v);
        }
        return $item;
    }

    private function renderTemplateString(string $s, array $context): string
    {
        $vars = [
            'type_code' => (string)($context['type_code'] ?? ''),
            'section_key' => (string)($context['section_key'] ?? ''),
            'content_package_dir' => (string)($context['content_package_dir'] ?? ''),
            'target' => (string)($context['target'] ?? ''),
        ];

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function ($m) use ($vars, $context) {
            $key = $m[1] ?? '';
            if ($key === '') return $m[0];

            // allow ctx.<key>
            if (str_starts_with($key, 'ctx.')) {
                $dot = substr($key, 4);
                $val = $this->getByDotPath((array)($context['ctx'] ?? []), $dot);
                return is_scalar($val) ? (string)$val : '';
            }

            return array_key_exists($key, $vars) ? (string)$vars[$key] : $m[0];
        }, $s) ?? $s;
    }

    /**
     * Deep merge: assoc arrays merge recursively; numeric arrays replace.
     */
    private function deepMerge(array $base, array $patch): array
    {
        foreach ($patch as $k => $v) {
            if (is_int($k)) {
                // numeric keys => replace behavior at this level
                return $patch;
            }

            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                if ($this->isAssoc($v) && $this->isAssoc($base[$k])) {
                    $base[$k] = $this->deepMerge($base[$k], $v);
                } else {
                    // numeric arrays => replace
                    $base[$k] = $v;
                }
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private function isAssoc(array $arr): bool
    {
        $keys = array_keys($arr);
        return $keys !== array_keys($keys);
    }

    private function getByDotPath(array $arr, string $path)
    {
        if ($path === '') return null;
        $cur = $arr;
        foreach (explode('.', $path) as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) return null;
            $cur = $cur[$p];
        }
        return $cur;
    }

    private function setByDotPath(array &$arr, string $path, $value): void
    {
        $parts = explode('.', $path);
        $cur =& $arr;

        foreach ($parts as $i => $p) {
            if ($p === '') return;

            if ($i === count($parts) - 1) {
                $cur[$p] = $value;
                return;
            }

            if (!isset($cur[$p]) || !is_array($cur[$p])) {
                $cur[$p] = [];
            }
            $cur =& $cur[$p];
        }
    }

    private function shouldExplain(array $ctx = []): bool
{
    if (!app()->environment('local')) return false;

    if ((bool)($ctx['overrides_debug'] ?? false)) return true;

    // ✅ 你现在验证时已经在用 RE_EXPLAIN=1，就复用它
    if ((bool) env('RE_EXPLAIN', false)) return true;

    // 也允许单独开关
    if ((bool) env('OVR_EXPLAIN', false)) return true;

    return false;
}
}