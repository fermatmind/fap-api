<?php

namespace App\Services\Overrides;

class HighlightsOverridesApplier
{
    /**
     * Apply highlight overrides.
     *
     * ✅ New format (recommended):
     *   {
     *     "schema": "fap.report.overrides.v1",
     *     "rules": [
     *       {
     *         "target": "highlights",
     *         "match": {
     *           "type_code": "ESTJ-A",
     *           "tags_any": ["x","y"],
     *           "item": { "kind": "strength", "id": ["EI_E_very_weak"] }
     *         },
     *         "mode": "patch",                  // patch | replace | remove | append | prepend | replace_all
     *         "replace_fields": ["tags","tips"],// optional
     *         "patch": { "text": "..." },       // for patch/replace
     *         "items": [ {...}, {...} ]         // for append/prepend/replace_all
     *       }
     *     ]
     *   }
     *
     * ✅ Legacy format (backward compatible):
     *   { "rules": { "override_mode": "...", "replace_fields": [...] }, "items": { "<TYPE>": { ... } } }
     *
     * @param string $contentPackageVersion
     * @param string $typeCode
     * @param array  $baseHighlights
     * @param array  $ctx Optional report ctx, e.g. ['tags'=> [...]]
     */
    public function apply(string $contentPackageVersion, string $typeCode, array $baseHighlights, array $ctx = []): array
    {
        $ovr = $this->loadReportAssetJson($contentPackageVersion, 'report_highlights_overrides.json');

        // ---------- NEW FORMAT ----------
        if ($this->isRuleList($ovr['rules'] ?? null)) {
            $reportCtx = [
                'type_code' => $typeCode,
                'tags'      => $this->normalizeTags($ctx['tags'] ?? []),
                // cards 会用到 section，这里先留着兼容（highlights 可不传）
                'section'   => is_string($ctx['section'] ?? null) ? (string)$ctx['section'] : null,
            ];

            $out = $this->normalizeHighlightsList($baseHighlights);

            foreach ($ovr['rules'] as $rule) {
                if (!is_array($rule)) continue;

                // target gate（默认就是 highlights）
                $target = (string)($rule['target'] ?? 'highlights');
                if ($target !== '' && $target !== 'highlights') continue;

                $match = is_array($rule['match'] ?? null) ? $rule['match'] : [];

                // 1) report-level match
                if (!$this->matchReport($match, $reportCtx)) {
                    continue;
                }

                $mode = (string)($rule['mode'] ?? 'patch');

                // list-level items payload（只从 rule.items 读；match.item 不是插入 item）
$ruleItems = [];
if (is_array($rule['items'] ?? null)) {
    $ruleItems = $this->normalizeHighlightsList($rule['items']);
}

                // per-rule replace_fields
                $replaceFields = $rule['replace_fields'] ?? null;
                if (!is_array($replaceFields)) $replaceFields = [];

                // per-rule patch payload
                $patch = is_array($rule['patch'] ?? null) ? $rule['patch'] : (is_array($rule['value'] ?? null) ? $rule['value'] : []);

                // 2) no match.item means list-level op
                $matchItem = is_array($match['item'] ?? null) ? $match['item'] : null;

                if ($matchItem === null) {
                    $out = $this->applyListMode($out, $mode, $ruleItems, $patch, $replaceFields);
                    continue;
                }

                // 3) item-level op
                $out = $this->applyItemMode($out, $mode, $matchItem, $patch, $replaceFields, $ruleItems);
            }

            return $out;
        }

        // ---------- LEGACY FORMAT (existing behavior) ----------
        $rules = is_array($ovr['rules'] ?? null) ? $ovr['rules'] : [];

        $overrideMode  = (string)($rules['override_mode'] ?? 'merge');
        $replaceFields = is_array($rules['replace_fields'] ?? null) ? $rules['replace_fields'] : ['tags', 'tips'];

        $items   = is_array($ovr['items'] ?? null) ? $ovr['items'] : [];
        $perType = is_array($items[$typeCode] ?? null) ? $items[$typeCode] : [];

        if (empty($perType) || empty($baseHighlights)) return $baseHighlights;

        $out = [];

        foreach ($baseHighlights as $h) {
            if (!is_array($h)) continue;

            $id    = (string)($h['id'] ?? '');
            $dim   = (string)($h['dim'] ?? '');
            $side  = (string)($h['side'] ?? '');
            $level = (string)($h['level'] ?? '');

            $override = null;

            // a) by id
            if ($id !== '' && isset($perType[$id]) && is_array($perType[$id])) {
                $override = $perType[$id];
            }

            // b) by dim/side/level
            if ($override === null && $dim !== '' && $side !== '' && $level !== '') {
                $o2 = $perType[$dim][$side][$level] ?? null;
                if (is_array($o2)) $override = $o2;
            }

            if (is_array($override)) {
                if ($overrideMode === 'merge') {
                    $h = array_replace_recursive($h, $override);
                } else {
                    $h = $override + $h;
                }

                foreach ($replaceFields as $rf) {
                    if (!is_string($rf) || $rf === '') continue;
                    if (array_key_exists($rf, $override)) {
                        $h[$rf] = is_array($override[$rf] ?? null) ? $override[$rf] : [];
                    }
                }
            }

            if (!is_array($h['tags'] ?? null)) $h['tags'] = [];
            if (!is_array($h['tips'] ?? null)) $h['tips'] = [];

            $out[] = $h;
        }

        return $out;
    }

    // =========================
    // NEW FORMAT: match + apply
    // =========================

    private function isRuleList($rules): bool
{
    // rules 必须是 array 且是“列表”（从 0 开始的连续整数 key）
    return is_array($rules) && array_is_list($rules);
}

    private function matchReport(array $match, array $ctx): bool
    {
        // type_code
        if (array_key_exists('type_code', $match)) {
            $want = $match['type_code'];
            $got  = (string)($ctx['type_code'] ?? '');
            if (!$this->inScalarOrList($got, $want)) return false;
        }

        // section (cards 才用；highlights 一般不传)
        if (array_key_exists('section', $match)) {
            $want = $match['section'];
            $got  = (string)($ctx['section'] ?? '');
            if ($got === '' || !$this->inScalarOrList($got, $want)) return false;
        }

        $tags = $this->normalizeTags($ctx['tags'] ?? []);

        // tags_any
        if (array_key_exists('tags_any', $match)) {
            $want = $this->normalizeTags($match['tags_any']);
            if (!$this->tagsAny($tags, $want)) return false;
        }

        // tags_all
        if (array_key_exists('tags_all', $match)) {
            $want = $this->normalizeTags($match['tags_all']);
            if (!$this->tagsAll($tags, $want)) return false;
        }

        return true;
    }

    private function matchItem(array $matchItem, array $item): bool
    {
        // id
        if (array_key_exists('id', $matchItem)) {
            $got = (string)($item['id'] ?? '');
            if ($got === '' || !$this->inScalarOrList($got, $matchItem['id'])) return false;
        }

        // kind
        if (array_key_exists('kind', $matchItem)) {
            $got = (string)($item['kind'] ?? '');
            if ($got === '' || !$this->inScalarOrList($got, $matchItem['kind'])) return false;
        }

        $tags = $this->normalizeTags($item['tags'] ?? []);

        // tags_any
        if (array_key_exists('tags_any', $matchItem)) {
            $want = $this->normalizeTags($matchItem['tags_any']);
            if (!$this->tagsAny($tags, $want)) return false;
        }

        // tags_all
        if (array_key_exists('tags_all', $matchItem)) {
            $want = $this->normalizeTags($matchItem['tags_all']);
            if (!$this->tagsAll($tags, $want)) return false;
        }

        return true;
    }

    private function applyListMode(array $list, string $mode, array $ruleItems, array $patch, array $replaceFields): array
    {
        $mode = $mode ?: 'patch';

        if ($mode === 'replace_all') {
            return $this->normalizeHighlightsList($ruleItems);
        }

        if ($mode === 'append') {
            return array_values(array_merge($list, $this->normalizeHighlightsList($ruleItems)));
        }

        if ($mode === 'prepend') {
            return array_values(array_merge($this->normalizeHighlightsList($ruleItems), $list));
        }

        // list-level patch/replace: apply to all items
        if ($mode === 'patch' || $mode === 'replace') {
            $out = [];
            foreach ($list as $it) {
                $out[] = $this->applyOneItem($it, $mode, $patch, $replaceFields);
            }
            return $out;
        }

        // list-level remove: wipe all
        if ($mode === 'remove') {
            return [];
        }

        // default: no-op
        return $list;
    }

    private function applyItemMode(
        array $list,
        string $mode,
        array $matchItem,
        array $patch,
        array $replaceFields,
        array $ruleItems
    ): array {
        $mode = $mode ?: 'patch';

        // remove matched
        if ($mode === 'remove') {
            $out = [];
            foreach ($list as $it) {
                if ($this->matchItem($matchItem, $it)) continue;
                $out[] = $it;
            }
            return $out;
        }

        // replace/patch matched
        if ($mode === 'patch' || $mode === 'replace') {
            $out = [];
            foreach ($list as $it) {
                if ($this->matchItem($matchItem, $it)) {
                    $it = $this->applyOneItem($it, $mode, $patch, $replaceFields);
                }
                $out[] = $it;
            }
            return $out;
        }

        // append/prepend：按约定只做“list-level”（不应该走到 item-level）
// 这里直接 no-op，避免误用
if ($mode === 'append' || $mode === 'prepend') {
    return $list;
}

        // default: no-op
        return $list;
    }

    private function applyOneItem(array $item, string $mode, array $patch, array $replaceFields): array
    {
        if ($mode === 'replace') {
            // replace entire item but keep id if patch misses it (optional)
            $id = $item['id'] ?? null;
            $item = $patch;
            if (!isset($item['id']) && $id !== null) $item['id'] = $id;
        } else {
            // patch (deep merge)
            $item = array_replace_recursive($item, $patch);
        }

        // replace_fields semantics (force replace arrays like tags/tips when patch provides them)
        foreach ($replaceFields as $rf) {
            if (!is_string($rf) || $rf === '') continue;
            if (array_key_exists($rf, $patch)) {
                $item[$rf] = is_array($patch[$rf] ?? null) ? $patch[$rf] : [];
            }
        }

        // normalize
        if (!is_array($item['tags'] ?? null)) $item['tags'] = [];
        if (!is_array($item['tips'] ?? null)) $item['tips'] = [];

        return $item;
    }

    private function normalizeHighlightsList($items): array
    {
        if (!is_array($items)) return [];
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (!is_array($it['tags'] ?? null)) $it['tags'] = [];
            if (!is_array($it['tips'] ?? null)) $it['tips'] = [];
            $out[] = $it;
        }
        return array_values($out);
    }

    private function inScalarOrList(string $got, $want): bool
    {
        if (is_string($want)) return $got === $want;
        if (is_array($want)) {
            foreach ($want as $x) {
                if (is_string($x) && $x === $got) return true;
            }
            return false;
        }
        return false;
    }

    private function normalizeTags($v): array
    {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $x) {
            if (!is_string($x)) continue;
            $x = trim($x);
            if ($x === '') continue;
            $out[$x] = true;
        }
        return array_keys($out);
    }

    private function tagsAny(array $have, array $want): bool
    {
        if (empty($want)) return true;
        $set = array_fill_keys($have, true);
        foreach ($want as $t) {
            if (isset($set[$t])) return true;
        }
        return false;
    }

    private function tagsAll(array $have, array $want): bool
    {
        if (empty($want)) return true;
        $set = array_fill_keys($have, true);
        foreach ($want as $t) {
            if (!isset($set[$t])) return false;
        }
        return true;
    }

    // =========================
    // package loaders (unchanged)
    // =========================

    private function loadReportAssetJson(string $contentPackageVersion, string $filename): array
    {
        static $cache = [];

        $key = $contentPackageVersion . '|' . $filename . '|RAW';
        if (isset($cache[$key])) return $cache[$key];

        $path = $this->resolvePackageFile($contentPackageVersion, $filename);
        if ($path === null) return $cache[$key] = [];

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return $cache[$key] = [];

        $json = json_decode($raw, true);
        if (!is_array($json)) return $cache[$key] = [];

        return $cache[$key] = $json;
    }

    private function resolvePackageFile(string $contentPackageVersion, string $filename): ?string
    {
        $pkg = trim($contentPackageVersion, "/\\");

        $envRoot = env('FAP_CONTENT_PACKAGES_DIR');
        $envRoot = is_string($envRoot) && $envRoot !== '' ? rtrim($envRoot, '/') : null;

        $candidates = array_values(array_filter([
            storage_path("app/private/content_packages/{$pkg}/{$filename}"),
            storage_path("app/content_packages/{$pkg}/{$filename}"),
            base_path("content_packages/{$pkg}/{$filename}"),
            $envRoot ? "{$envRoot}/{$pkg}/{$filename}" : null,
        ]));

        foreach ($candidates as $p) {
            if (is_string($p) && $p !== '' && file_exists($p)) return $p;
        }
        return null;
    }
}