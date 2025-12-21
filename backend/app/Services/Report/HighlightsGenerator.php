<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\Log;

class HighlightsGenerator
{
    /**
     * 固定 5 轴
     */
    private array $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

    /**
     * generate
     *
     * @param string $contentPackageVersion  内容包版本（目录名）
     * @param string $typeCode               如 "ENFJ-A"
     * @param array  $scores                 report.scores 结构：scores[dim] = ['pct'=>50..100,'state'=>..,'side'=>..,'delta'=>0..50]
     * @param array  $context                可选：role_card/strategy_card/tags 等
     */
    public function generate(string $contentPackageVersion, string $typeCode, array $scores, array $context = []): array
    {
        // 1) load templates + overrides
        $tpl = $this->loadReportAssetJson($contentPackageVersion, 'report_highlights_templates.json');
        $ovr = $this->loadReportAssetJson($contentPackageVersion, 'report_highlights_overrides.json');

        // fallback: old static highlights by type (report_highlights.json)
        $oldItems   = $this->loadReportAssetItems($contentPackageVersion, 'report_highlights.json');
        $oldPerType = is_array($oldItems[$typeCode] ?? null) ? $oldItems[$typeCode] : [];

        $tplRules     = is_array($tpl['rules'] ?? null) ? $tpl['rules'] : [];
        $tplTemplates = is_array($tpl['templates'] ?? null) ? $tpl['templates'] : [];

        // 2) rules with defaults
        $topN        = (int)($tplRules['top_n'] ?? 2);
        $maxItems    = (int)($tplRules['max_items'] ?? 2);

        // M3 硬保证：最少 3 条
        $minItems    = (int)($tplRules['min_items'] ?? 3);
        if ($minItems < 3) $minItems = 3;

        // delta：0..50 的阈值（推荐 10~20）
        $minDelta    = (int)($tplRules['min_delta'] ?? 15);
        $minLevel    = (string)($tplRules['min_level'] ?? 'clear');
        $allowEmpty  = (bool)($tplRules['allow_empty'] ?? true);

        $allowedLvls = is_array($tplRules['allowed_levels'] ?? null)
            ? $tplRules['allowed_levels']
            : ['clear', 'strong', 'very_strong'];

        $levelOrder  = is_array($tplRules['level_order'] ?? null)
            ? $tplRules['level_order']
            : ['very_weak', 'weak', 'moderate', 'clear', 'strong', 'very_strong'];

        $idFormat    = (string)($tplRules['id_format'] ?? '${dim}_${side}_${level}');

        // overrides 语义：replace_fields = ['tags','tips']（替换不合并）
        $replaceFields = is_array($tplRules['replace_fields'] ?? null)
            ? $tplRules['replace_fields']
            : ['tags', 'tips'];

        // 3) per-type overrides
        $ovrItems = is_array($ovr['items'] ?? null) ? $ovr['items'] : [];
        $perType  = is_array($ovrItems[$typeCode] ?? null) ? $ovrItems[$typeCode] : [];

        // 4) build candidates from scores
        $candidates = [];

        foreach ($this->dims as $dim) {
            $s = is_array($scores[$dim] ?? null) ? $scores[$dim] : null;
            if (!$s) continue;

            $side  = (string)($s['side'] ?? '');
            $level = (string)($s['state'] ?? 'moderate'); // 你 AxisScore 输出的是 state
            $pct   = (int)($s['pct'] ?? 50);              // 50..100
            $delta = (int)($s['delta'] ?? max(0, abs($pct - 50))); // 0..50

            if ($side === '') continue;

            // gate: allowed levels
            if (!in_array($level, $allowedLvls, true)) continue;

            // gate: min_level by level_order
            $idxLevel = array_search($level, $levelOrder, true);
            $idxMin   = array_search($minLevel, $levelOrder, true);
            if ($idxLevel === false || $idxMin === false || $idxLevel < $idxMin) continue;

            // gate: min_delta
            if ($delta < $minDelta) continue;

            // template hit: templates[dim][side][level]
            $hit = $tplTemplates[$dim][$side][$level] ?? null;
            if (!is_array($hit)) continue;

            // ensure id
            $id = (string)($hit['id'] ?? '');
            if ($id === '') {
                $id = str_replace(['${dim}', '${side}', '${level}'], [$dim, $side, $level], $idFormat);
            }

            $card = [
                'id'    => $id,
                'dim'   => $dim,
                'side'  => $side,
                'level' => $level,
                'pct'   => $pct,
                'delta' => $delta,
                'title' => (string)($hit['title'] ?? ''),
                'text'  => (string)($hit['text'] ?? ''),
                'tips'  => is_array($hit['tips'] ?? null) ? $hit['tips'] : [],
                'tags'  => is_array($hit['tags'] ?? null) ? $hit['tags'] : [],
            ];

            // 5) overrides (two forms)
            $override = null;

            // (1) by card_id
            if (isset($perType[$id]) && is_array($perType[$id])) {
                $override = $perType[$id];
            }

            // (2) by dim/side/level
            if ($override === null) {
                $o2 = $perType[$dim][$side][$level] ?? null;
                if (is_array($o2)) $override = $o2;
            }

            if (is_array($override)) {
                $card = array_replace_recursive($card, $override);

                // ✅ replace_fields: 整段覆盖
                foreach ($replaceFields as $rf) {
                    if (!is_string($rf) || $rf === '') continue;
                    if (array_key_exists($rf, $override)) {
                        $card[$rf] = is_array($override[$rf] ?? null) ? $override[$rf] : [];
                    }
                }

                // normalize
                if (!is_array($card['tips'] ?? null)) $card['tips'] = [];
                if (!is_array($card['tags'] ?? null)) $card['tags'] = [];
            }

            $candidates[] = $card;
        }

        // sort by delta desc
        usort($candidates, fn($a, $b) => (int)($b['delta'] ?? 0) <=> (int)($a['delta'] ?? 0));

        // 6) choose take count
        $take = max($minItems, min(max($topN, 0), max($maxItems, 0)));
        if ($take < $minItems) $take = $minItems;

        $out = array_slice($candidates, 0, $take);
        $out = $this->forceIncludeDim($out, $candidates, 'AT', $take);

        // 7) fallback: old static highlights if template miss
        if (empty($out)) {
            $out = $this->normalizeOldHighlights($oldPerType, $scores, $take);
        }

        // 8) hard guarantee: >=3, must have strength/risk/action
        // ✅关键改动：优先“在已有 out 里标注 kind”，只有缺失时才追加 hl_fallback_*
        $out = $this->dedupeById($out);
        $out = $this->ensureKinds($out, $typeCode, $scores);

        // 9) normalize kind/axis tags, UX sort, limit
        $out = $this->normalizeHighlightKinds($out);
        $out = $this->sortHighlightsForUX($out);

        return array_slice($out, 0, 8);
    }

    // =========================
    // Helpers
    // =========================

    private function normalizeOldHighlights(array $oldPerType, array $scores, int $take): array
    {
        $norm = [];

        if (!is_array($oldPerType) || empty($oldPerType)) {
            return [];
        }

        foreach (array_values($oldPerType) as $c) {
            if (!is_array($c)) continue;

            $id = (string)($c['id'] ?? '');
            if ($id === '') continue;

            $dim   = $c['dim']   ?? null;
            $side  = $c['side']  ?? null;
            $level = $c['level'] ?? null;

            // 尝试从 id 解析：EI_E_clear / AT_A_very_strong
            if ((!$dim || !$side || !$level)
                && preg_match('/^(EI|SN|TF|JP|AT)_([EISNTFJPA])_(clear|strong|very_strong)$/', $id, $m)) {
                $dim   = $m[1];
                $side  = $m[2];
                $level = $m[3];
            }

            if (!$dim || !$side || !$level) continue;

            $s = is_array($scores[$dim] ?? null) ? $scores[$dim] : null;
            $pct   = (int)($s['pct'] ?? 50);
            $delta = (int)($s['delta'] ?? max(0, abs($pct - 50)));

            $title = (string)($c['title'] ?? '');
            $text  = (string)($c['text']  ?? $title);

            $norm[] = [
                'id'    => $id,
                'dim'   => (string)$dim,
                'side'  => (string)$side,
                'level' => (string)$level,
                'pct'   => $pct,
                'delta' => $delta,
                'title' => $title,
                'text'  => $text,
                'tips'  => is_array($c['tips'] ?? null) ? $c['tips'] : [],
                'tags'  => is_array($c['tags'] ?? null) ? $c['tags'] : [],
            ];
        }

        usort($norm, fn($a, $b) => (int)($b['delta'] ?? 0) <=> (int)($a['delta'] ?? 0));

        return array_slice($norm, 0, $take);
    }

    private function forceIncludeDim(array $out, array $candidates, string $dim, int $take): array
{
    // 已经有就不动
    $has = false;
    foreach ($out as $h) {
        if (is_array($h) && (($h['dim'] ?? null) === $dim)) { $has = true; break; }
    }
    if ($has) return $out;

    // 找 candidates 里该 dim 的最佳卡（delta 最大）
    $best = null;
    $bestDelta = -1;
    foreach ($candidates as $c) {
        if (!is_array($c)) continue;
        if (($c['dim'] ?? null) !== $dim) continue;
        $d = (int)($c['delta'] ?? 0);
        if ($d > $bestDelta) { $bestDelta = $d; $best = $c; }
    }
    if (!$best) return $out;

    // 保持长度不变：用它替换掉 out 最后一张（避免无限增多）
    if (count($out) >= $take && $take > 0) {
        array_pop($out);
    }
    $out[] = $best;

    // 去重
    $out = $this->dedupeById($out);

    // 仍超长就截断
    if ($take > 0 && count($out) > $take) {
        $out = array_slice($out, 0, $take);
    }

    return $out;
}

    /**
     * ✅改动重点在这里：
     * - 如果 out 里已经有足够 axis 卡（模板命中），就从 out 中选 3 张分别标注为 strength/risk/action
     * - risk 会避开 strength 的 dim，避免“同轴强项+风险”
     * - 只有缺对应 dim 的卡时才 append fallback
     */
    private function ensureKinds(array $out, string $typeCode, array $scores): array
    {
        $need = ['strength', 'risk', 'action'];

        // 当前已存在的 kinds（如果某些模板/override 已经写了 kind:*，就尊重）
        $has = [];
        foreach ($out as $h) {
            $tags = is_array($h['tags'] ?? null) ? $h['tags'] : [];
            foreach ($tags as $t) {
                if (is_string($t) && str_starts_with($t, 'kind:')) {
                    $has[substr($t, 5)] = true;
                }
            }
        }

        // axisInfo（来自 scores）
        $axisInfo = [];
        foreach ($this->dims as $dim) {
            $s = is_array($scores[$dim] ?? null) ? $scores[$dim] : [];
            $axisInfo[$dim] = [
                'dim'   => $dim,
                'side'  => (string)($s['side'] ?? ''),
                'pct'   => (int)($s['pct'] ?? 50),
                'delta' => (int)($s['delta'] ?? 0),
                'level' => (string)($s['state'] ?? 'moderate'),
            ];
        }

        // out 里目前有哪些 dim（模板命中的 dim）
        $presentDims = [];
        foreach ($out as $h) {
            $d = (string)($h['dim'] ?? '');
            if ($d !== '' && in_array($d, $this->dims, true) && !in_array($d, $presentDims, true)) {
                $presentDims[] = $d;
            }
        }

        // 选择 strength/risk/action 的 dim（优先选 present 的）
        $strengthDim = $this->pickStrongestDim($axisInfo, $presentDims);
        $riskDim     = $this->pickWeakestDim($axisInfo, $presentDims, [$strengthDim]); // ✅避开 strengthDim
        $actionDim   = in_array('AT', $presentDims, true) ? 'AT' : 'AT'; // 仍然优先 AT（即使不 present，后面会 fallback）

        // 标注 kind 时避免复用同一张卡
        $usedIds = [];

        // 1) strength
        if (!isset($has['strength'])) {
            $ok = $this->markKindOnOut($out, 'strength', $strengthDim, $usedIds);
            if (!$ok) {
                $pick = $axisInfo[$strengthDim] ?? null;
                if ($pick && ($pick['side'] ?? '') !== '') $out[] = $this->makeFallback('strength', $typeCode, $pick);
            }
        }

        // 2) risk（不能和 strength 同轴）
        if (!isset($has['risk'])) {
            $ok = $this->markKindOnOut($out, 'risk', $riskDim, $usedIds);
            if (!$ok) {
                $pick = $axisInfo[$riskDim] ?? null;
                if ($pick && ($pick['side'] ?? '') !== '') $out[] = $this->makeFallback('risk', $typeCode, $pick);
            }
        }

        // 3) action（优先 AT；如果 AT 不在 out 里，就 fallback）
        if (!isset($has['action'])) {
            $ok = $this->markKindOnOut($out, 'action', $actionDim, $usedIds);
            if (!$ok) {
                $pick = $axisInfo['AT'] ?? null;
                if (!$pick || ($pick['side'] ?? '') === '') {
                    // 再兜底：用 strongest
                    $pick = $axisInfo[$strengthDim] ?? null;
                }
                if ($pick && ($pick['side'] ?? '') !== '') $out[] = $this->makeFallback('action', $typeCode, $pick);
            }
        }

        // 去重
        $out = $this->dedupeById($out);

        // 仍不足 3（极端）：用 strongest 继续补（尽量不重复 dim）
        while (count($out) < 3) {
            $dim = $this->pickNextDimByDeltaDesc($axisInfo, $presentDims, $usedIds);
            $pick = $axisInfo[$dim] ?? null;
            if (!$pick || ($pick['side'] ?? '') === '') break;
            $out[] = $this->makeFallback('action', $typeCode, $pick);
            $out = $this->dedupeById($out);
        }

        // 为了 UX：先不按 delta 排，交给 sortHighlightsForUX（kind 优先）做最终排序
        return $out;
    }

    /**
     * 在 out 中找一个 dim 对应的卡，把它“标注”为 kind
     * - 不新增卡（避免 fallback 永远出现）
     * - 避免一张卡被多个 kind 占用（用 $usedIds）
     */
    private function markKindOnOut(array &$out, string $kind, string $dim, array &$usedIds): bool
    {
        if ($dim === '') return false;

        // 选择 out 里该 dim 中 delta 最大的一张（更合理）
        $bestIdx = null;
        $bestDelta = -1;

        foreach ($out as $i => $h) {
            if (!is_array($h)) continue;

            $hDim = (string)($h['dim'] ?? '');
            if ($hDim !== $dim) continue;

            $id = (string)($h['id'] ?? '');
            if ($id !== '' && in_array($id, $usedIds, true)) continue;

            $d = (int)($h['delta'] ?? 0);
            if ($d > $bestDelta) {
                $bestDelta = $d;
                $bestIdx = $i;
            }
        }

        if ($bestIdx === null) return false;

        $id = (string)($out[$bestIdx]['id'] ?? '');
        if ($id !== '') $usedIds[] = $id;

        $tags = $out[$bestIdx]['tags'] ?? [];
        if (!is_array($tags)) $tags = [];

        // 移除旧 kind:*（避免混乱）
        $tags2 = [];
        foreach ($tags as $t) {
            if (!is_string($t) || $t === '') continue;
            if (str_starts_with($t, 'kind:')) continue;
            $tags2[] = $t;
        }
        $tags2[] = "kind:{$kind}";

        $out[$bestIdx]['tags'] = $tags2;

        return true;
    }

    private function pickStrongestDim(array $axisInfo, array $preferDims = []): string
    {
        $dims = !empty($preferDims) ? $preferDims : $this->dims;

        $bestDim = $dims[0] ?? 'EI';
        $best = -1;
        foreach ($dims as $dim) {
            $abs = abs((int)($axisInfo[$dim]['delta'] ?? 0));
            if ($abs > $best) {
                $best = $abs;
                $bestDim = $dim;
            }
        }
        return $bestDim;
    }

    private function pickWeakestDim(array $axisInfo, array $preferDims = [], array $excludeDims = []): string
    {
        $dims = !empty($preferDims) ? $preferDims : $this->dims;
        $dims = array_values(array_filter($dims, fn($d) => !in_array($d, $excludeDims, true)));

        // 如果 preferDims 被排空了，就用全量 dims 再试一次
        if (empty($dims)) {
            $dims = array_values(array_filter($this->dims, fn($d) => !in_array($d, $excludeDims, true)));
        }

        $bestDim = $dims[0] ?? 'SN';
        $best = PHP_INT_MAX;

        foreach ($dims as $dim) {
            $abs = abs((int)($axisInfo[$dim]['delta'] ?? 0));
            if ($abs < $best) {
                $best = $abs;
                $bestDim = $dim;
            }
        }
        return $bestDim;
    }

    private function pickNextDimByDeltaDesc(array $axisInfo, array $preferDims, array $usedIds): string
    {
        $dims = !empty($preferDims) ? $preferDims : $this->dims;

        $list = [];
        foreach ($dims as $dim) {
            $list[] = [
                'dim' => $dim,
                'delta' => (int)($axisInfo[$dim]['delta'] ?? 0),
            ];
        }
        usort($list, fn($a, $b) => (int)$b['delta'] <=> (int)$a['delta']);

        return (string)($list[0]['dim'] ?? 'EI');
    }

    private function makeFallback(string $kind, string $typeCode, array $pick): array
    {
        $dim  = (string)($pick['dim'] ?? '');
        $side = (string)($pick['side'] ?? '');
        $pct  = (int)($pick['pct'] ?? 50);
        $delta= (int)($pick['delta'] ?? 0);
        $level= (string)($pick['level'] ?? 'moderate');

        $dimName = [
            'EI' => '能量来源',
            'SN' => '信息偏好',
            'TF' => '决策方式',
            'JP' => '行事节奏',
            'AT' => '压力姿态',
        ][$dim] ?? $dim;

        $hint = match ($dim) {
            'EI' => ($side === 'E' ? '更可能在互动中获得能量与清晰度' : '更可能在独处中恢复能量与思考质量'),
            'SN' => ($side === 'S' ? '更重视可落地的细节与现实路径' : '更擅长从趋势与可能性中抓重点'),
            'TF' => ($side === 'T' ? '更倾向用标准/逻辑来做取舍' : '更倾向用感受/价值来做取舍'),
            'JP' => ($side === 'J' ? '更喜欢计划与收束，推进更稳' : '更喜欢灵活与探索，适应更快'),
            'AT' => ($side === 'A' ? '更稳、更敢拍板' : '更敏感、更会自省与校准'),
            default => '把优势用在对的场景',
        };

        $title = match ($kind) {
            'strength' => "强项：你的{$dimName}更偏 {$side}",
            'risk'     => "盲点：{$dimName}容易出现“惯性误判”",
            default    => "建议：把{$dimName}优势用对地方",
        };

        $text = match ($kind) {
            'strength' => "你在「{$dimName}」更偏 {$side}（强度 {$pct}%）：{$hint}。这会让你在相关场景里更容易做出高质量决策与行动。",
            'risk'     => "在「{$dimName}」上，你更偏 {$side}（强度 {$pct}%）。优势用过头时可能变成惯性：建议在关键场景加入一次“反向校验”，避免单一路径误判。",
            default    => "你在「{$dimName}」更偏 {$side}（强度 {$pct}%）。给自己加一个小流程：先写下第一反应，再补一个反向备选，然后再做决定/表达，输出会更稳。",
        };

        $tips = match ($kind) {
            'strength' => ["把这个优势固定成你的“常用模板/流程”", "在团队里明确：你负责哪类决策最擅长"],
            'risk'     => ["重要决定前写一个“反方理由”", "找一个互补型的人做 2 分钟校验"],
            default    => ["第一反应写下来，再补一个反向备选", "给重要决定加 10 分钟冷却/复盘"],
        };

        return [
            'id'    => "hl_fallback_{$kind}_{$typeCode}_{$dim}_{$side}",
            'dim'   => $dim,
            'side'  => $side,
            'level' => $level,
            'pct'   => $pct,
            'delta' => $delta,
            'title' => $title,
            'text'  => $text,
            'tips'  => $tips,
            'tags'  => ["kind:{$kind}", "axis:{$dim}:{$side}", "fallback:true"],
        ];
    }

    private function normalizeHighlightKinds(array $cards): array
    {
        foreach ($cards as &$c) {
            if (!is_array($c)) continue;

            $tags = $c['tags'] ?? [];
            if (!is_array($tags)) $tags = [];

            $hasKind = false;
            foreach ($tags as $t) {
                if (is_string($t) && str_starts_with($t, 'kind:')) {
                    $hasKind = true;
                    break;
                }
            }
            if (!$hasKind) {
                $tags[] = 'kind:axis';
            }

            $dim  = (string)($c['dim'] ?? '');
            $side = (string)($c['side'] ?? '');
            if ($dim !== '' && $side !== '') {
                $axisTag = "axis:{$dim}:{$side}";
                if (!in_array($axisTag, $tags, true)) $tags[] = $axisTag;
            }

            // dedupe keep order
            $dedup = [];
            foreach ($tags as $t) {
                if (!is_string($t) || $t === '') continue;
                if (!in_array($t, $dedup, true)) $dedup[] = $t;
            }
            $c['tags'] = $dedup;

            if (!is_array($c['tips'] ?? null)) $c['tips'] = [];
        }
        unset($c);

        return array_values(array_filter($cards, fn($x) => is_array($x)));
    }

    private function sortHighlightsForUX(array $items): array
    {
        $items = array_values(array_filter($items, fn($x) => is_array($x)));

        usort($items, function ($a, $b) {
            $pa = $this->highlightKindPriority($a);
            $pb = $this->highlightKindPriority($b);

            if ($pa !== $pb) return $pa <=> $pb;

            $da = (int)($a['delta'] ?? 0);
            $db = (int)($b['delta'] ?? 0);
            if ($da !== $db) return $db <=> $da;

            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        return $items;
    }

    private function highlightKindPriority(array $h): int
    {
        $kind = null;
        $tags = is_array($h['tags'] ?? null) ? $h['tags'] : [];
        foreach ($tags as $t) {
            if (is_string($t) && str_starts_with($t, 'kind:')) {
                $kind = substr($t, 5);
                break;
            }
        }

        return match ($kind) {
            'strength' => 0,
            'risk'     => 1,
            'action'   => 2,
            'axis'     => 3,
            default    => 9,
        };
    }

    private function dedupeById(array $items): array
    {
        $items = array_values(array_filter($items, fn($x) => is_array($x)));
        $seen = [];
        $out = [];
        foreach ($items as $it) {
            $id = (string)($it['id'] ?? '');
            if ($id !== '' && isset($seen[$id])) continue;
            if ($id !== '') $seen[$id] = true;
            $out[] = $it;
        }
        return $out;
    }

    // =========================
    // Content package loaders
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

    private function loadReportAssetItems(string $contentPackageVersion, string $filename, ?string $primaryIndexKey = 'type_code'): array
    {
        static $cache = [];

        $key = $contentPackageVersion . '|' . $filename . '|ITEMS|' . ($primaryIndexKey ?? '');
        if (isset($cache[$key])) return $cache[$key];

        $json = $this->loadReportAssetJson($contentPackageVersion, $filename);
        if (!is_array($json) || empty($json)) return $cache[$key] = [];

        $items = $json['items'] ?? $json;
        if (!is_array($items)) return $cache[$key] = [];

        // if list -> index
        $keys = array_keys($items);
        $isList = (count($keys) > 0) && ($keys === range(0, count($keys) - 1));

        if ($isList) {
            $indexed = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;

                $k = null;
                if ($primaryIndexKey && isset($it[$primaryIndexKey])) $k = $it[$primaryIndexKey];
                elseif (isset($it['type_code'])) $k = $it['type_code'];
                elseif (isset($it['meta']['type_code'])) $k = $it['meta']['type_code'];
                elseif (isset($it['id'])) $k = $it['id'];
                elseif (isset($it['code'])) $k = $it['code'];

                if (!$k) continue;
                $indexed[(string)$k] = $it;
            }
            $items = $indexed;
        }

        return $cache[$key] = $items;
    }

    private function resolvePackageFile(string $contentPackageVersion, string $filename): ?string
    {
        $pkg = trim($contentPackageVersion, "/\\");

        $envRoot = env('FAP_CONTENT_PACKAGES_DIR');
        $envRoot = is_string($envRoot) && $envRoot !== '' ? rtrim($envRoot, '/') : null;

        $candidates = array_values(array_filter([
            storage_path("app/private/content_packages/{$pkg}/{$filename}"),
            storage_path("app/content_packages/{$pkg}/{$filename}"),
            base_path("../content_packages/{$pkg}/{$filename}"),
            base_path("content_packages/{$pkg}/{$filename}"),
            $envRoot ? "{$envRoot}/{$pkg}/{$filename}" : null,
        ]));

        foreach ($candidates as $p) {
            if (is_string($p) && $p !== '' && file_exists($p)) return $p;
        }

        return null;
    }
}