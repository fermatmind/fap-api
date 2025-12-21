<?php

namespace App\Services\Report;

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
        $maxItems    = (int)($tplRules['max_items'] ?? 8);

        // ✅体验优先：最少 4 条（strength/risk/action/axis）
        $minItems    = (int)($tplRules['min_items'] ?? 3);
        if ($minItems < 4) $minItems = 4;

        // delta：0..50 的阈值（推荐 10~20）
        $minDelta    = (int)($tplRules['min_delta'] ?? 15);
        $minLevel    = (string)($tplRules['min_level'] ?? 'clear');

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

        // 4) build candidates from scores (模板命中才作为候选)
        $candidates = [];

        foreach ($this->dims as $dim) {
            $s = is_array($scores[$dim] ?? null) ? $scores[$dim] : null;
            if (!$s) continue;

            $side  = (string)($s['side'] ?? '');
            $level = (string)($s['state'] ?? 'moderate');
            $pct   = (int)($s['pct'] ?? 50);
            $delta = (int)($s['delta'] ?? max(0, abs($pct - 50)));

            if ($side === '') continue;

            // gate: allowed levels
            if (!in_array($level, $allowedLvls, true)) continue;

            // gate: min_level by level_order
            $idxLevel = array_search($level, $levelOrder, true);
            $idxMin   = array_search($minLevel, $levelOrder, true);
            if ($idxLevel === false || $idxMin === false || $idxLevel < $idxMin) continue;

            // gate: min_delta
            if ($delta < $minDelta) continue;

            // template hit
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

        // 如果模板完全没命中：用 old highlights
        if (empty($candidates)) {
            $take = max($minItems, min(max($topN, 0), max($maxItems, 0)));
            $out = $this->normalizeOldHighlights($oldPerType, $scores, $take);

            // 兜底：仍为空就返回空（允许上层处理）
            if (empty($out)) return [];

            // 给 old 也做 kind 标注 + 最少 4 条（必要时才 fallback）
            $out = $this->composeHighlightsForUX($out, $typeCode, $scores, $minItems);
            $out = $this->normalizeHighlightKinds($out);
            $out = $this->sortHighlightsForUX($out);
            return array_slice($out, 0, 8);
        }

        // 6) 体验组装：strength / risk / action / axis（必要时才 fallback）
        $out = $this->composeHighlightsForUX($candidates, $typeCode, $scores, $minItems);

        // 7) normalize + UX sort
        $out = $this->dedupeById($out);
        $out = $this->normalizeHighlightKinds($out);
        $out = $this->sortHighlightsForUX($out);

        return array_slice($out, 0, 8);
    }

    // =========================
    // UX Composer（核心体验逻辑）
    // =========================

    private function composeHighlightsForUX(array $candidates, string $typeCode, array $scores, int $minItems = 4): array
    {
        $minItems = max(4, $minItems);

        $axisInfo = $this->buildAxisInfo($scores);

        // bestByDim：每个 dim 只保留 delta 最大的那张（避免同轴重复）
        $bestByDim = $this->bestByDim($candidates);

        $availableDims = array_values(array_keys($bestByDim));
        if (empty($availableDims)) {
            // 没模板卡：只能 fallback
            return $this->fallbackOnly($typeCode, $axisInfo, $minItems);
        }

        // strengthDim：delta 最大（尽量不选 AT）
        $strengthDim = $this->pickStrongestDim($axisInfo, $availableDims, true);

        // riskDim：delta 最小，且避开 strengthDim，尽量不选 AT
        $riskDim = $this->pickWeakestDim($axisInfo, $availableDims, [$strengthDim], true);

        // actionDim：优先 AT（模板卡），否则选一个未使用的“次强”
        $actionDim = in_array('AT', $availableDims, true) ? 'AT' : $this->pickNextStrongDim($axisInfo, $availableDims, [$strengthDim, $riskDim]);

        $out = [];
        $usedDims = [];

        // 1) strength（优先模板卡，否则 fallback）
        $out[] = $this->takeOrFallback($bestByDim, $axisInfo, $typeCode, 'strength', $strengthDim);
        $usedDims[$strengthDim] = true;

        // 2) risk
        if ($riskDim !== '' && !isset($usedDims[$riskDim])) {
            $out[] = $this->takeOrFallback($bestByDim, $axisInfo, $typeCode, 'risk', $riskDim);
            $usedDims[$riskDim] = true;
        } else {
            // 再兜底选一个不同轴
            $altRisk = $this->pickWeakestDim($axisInfo, $availableDims, array_keys($usedDims), true);
            if ($altRisk !== '' && !isset($usedDims[$altRisk])) {
                $out[] = $this->takeOrFallback($bestByDim, $axisInfo, $typeCode, 'risk', $altRisk);
                $usedDims[$altRisk] = true;
            }
        }

        // 3) action（优先 AT）
        if ($actionDim !== '' && !isset($usedDims[$actionDim])) {
            $out[] = $this->takeOrFallback($bestByDim, $axisInfo, $typeCode, 'action', $actionDim);
            $usedDims[$actionDim] = true;
        } else {
            $altAction = $this->pickNextStrongDim($axisInfo, $availableDims, array_keys($usedDims));
            if ($altAction !== '' && !isset($usedDims[$altAction])) {
                $out[] = $this->takeOrFallback($bestByDim, $axisInfo, $typeCode, 'action', $altAction);
                $usedDims[$altAction] = true;
            } else {
                // 最后兜底：用 AT fallback（即使没有模板卡）
                $out[] = $this->makeFallback('action', $typeCode, $axisInfo['AT'] ?? []);
            }
        }

        // 4) 额外补一条 axis（模板卡）凑到 >=4
        $dimsByDeltaDesc = $this->dimsByDeltaDesc($axisInfo, $availableDims);
        foreach ($dimsByDeltaDesc as $dim) {
            if (count($out) >= $minItems) break;
            if (isset($usedDims[$dim])) continue;
            $card = $bestByDim[$dim] ?? null;
            if (is_array($card)) {
                // 没 kind 的会在 normalizeHighlightKinds 里补 kind:axis
                $out[] = $card;
                $usedDims[$dim] = true;
            }
        }

        // 如果还不够：才补 fallback（不会固定每次加 3 条）
        while (count($out) < $minItems) {
            $dim = $this->pickNextStrongDim($axisInfo, $this->dims, array_keys($usedDims));
            if ($dim === '') break;
            $out[] = $this->makeFallback('axis', $typeCode, $axisInfo[$dim] ?? []);
            $usedDims[$dim] = true;
        }

        return $this->dedupeById($out);
    }

    private function takeOrFallback(array $bestByDim, array $axisInfo, string $typeCode, string $kind, string $dim): array
    {
        $card = $bestByDim[$dim] ?? null;
        if (is_array($card)) {
            $this->forceSetKind($card, $kind);
            return $card;
        }

        $pick = $axisInfo[$dim] ?? null;
        if (is_array($pick) && ($pick['side'] ?? '') !== '') {
            return $this->makeFallback($kind, $typeCode, $pick);
        }

        // 极端兜底：用 strongest fallback
        $strongest = $this->pickStrongestDim($axisInfo, $this->dims, false);
        return $this->makeFallback($kind, $typeCode, $axisInfo[$strongest] ?? []);
    }

    private function fallbackOnly(string $typeCode, array $axisInfo, int $minItems): array
    {
        $out = [];
        $strength = $this->pickStrongestDim($axisInfo, $this->dims, true);
        $risk     = $this->pickWeakestDim($axisInfo, $this->dims, [$strength], true);

        $out[] = $this->makeFallback('strength', $typeCode, $axisInfo[$strength] ?? []);
        $out[] = $this->makeFallback('risk',     $typeCode, $axisInfo[$risk] ?? []);
        $out[] = $this->makeFallback('action',   $typeCode, $axisInfo['AT'] ?? []);

        while (count($out) < max(4, $minItems)) {
            $dim = $this->pickNextStrongDim($axisInfo, $this->dims, []);
            if ($dim === '') break;
            $out[] = $this->makeFallback('axis', $typeCode, $axisInfo[$dim] ?? []);
        }

        return $this->dedupeById($out);
    }

    private function forceSetKind(array &$card, string $kind): void
    {
        $tags = is_array($card['tags'] ?? null) ? $card['tags'] : [];
        $tags = array_values(array_filter($tags, fn($t) => !(is_string($t) && str_starts_with($t, 'kind:'))));
        $tags[] = "kind:{$kind}";
        $card['tags'] = $tags;
    }

    private function buildAxisInfo(array $scores): array
    {
        $axisInfo = [];
        foreach ($this->dims as $dim) {
            $s = is_array($scores[$dim] ?? null) ? $scores[$dim] : [];
            $axisInfo[$dim] = [
                'dim'   => $dim,
                'side'  => (string)($s['side'] ?? ''),
                'pct'   => (int)($s['pct'] ?? 50),
                'delta' => (int)($s['delta'] ?? max(0, abs(((int)($s['pct'] ?? 50)) - 50))),
                'level' => (string)($s['state'] ?? 'moderate'),
            ];
        }
        return $axisInfo;
    }

    private function bestByDim(array $candidates): array
    {
        $best = [];
        foreach ($candidates as $c) {
            if (!is_array($c)) continue;
            $dim = (string)($c['dim'] ?? '');
            if ($dim === '') continue;

            $d = (int)($c['delta'] ?? 0);
            if (!isset($best[$dim]) || $d > (int)($best[$dim]['delta'] ?? 0)) {
                $best[$dim] = $c;
            }
        }
        return $best;
    }

    private function pickStrongestDim(array $axisInfo, array $dims, bool $avoidAT): string
    {
        $pool = $dims;
        if ($avoidAT) {
            $pool = array_values(array_filter($pool, fn($d) => $d !== 'AT'));
            if (empty($pool)) $pool = $dims;
        }

        $bestDim = $pool[0] ?? 'EI';
        $best = -1;
        foreach ($pool as $dim) {
            $delta = (int)($axisInfo[$dim]['delta'] ?? 0);
            if ($delta > $best) {
                $best = $delta;
                $bestDim = $dim;
            }
        }
        return $bestDim;
    }

    private function pickWeakestDim(array $axisInfo, array $dims, array $excludeDims, bool $avoidAT): string
    {
        $pool = array_values(array_filter($dims, fn($d) => !in_array($d, $excludeDims, true)));
        if ($avoidAT) {
            $poolNoAT = array_values(array_filter($pool, fn($d) => $d !== 'AT'));
            if (!empty($poolNoAT)) $pool = $poolNoAT;
        }
        if (empty($pool)) return '';

        $bestDim = $pool[0];
        $best = PHP_INT_MAX;
        foreach ($pool as $dim) {
            $delta = (int)($axisInfo[$dim]['delta'] ?? 0);
            if ($delta < $best) {
                $best = $delta;
                $bestDim = $dim;
            }
        }
        return $bestDim;
    }

    private function pickNextStrongDim(array $axisInfo, array $dims, array $excludeDims): string
    {
        $pool = array_values(array_filter($dims, fn($d) => !in_array($d, $excludeDims, true)));
        if (empty($pool)) return '';

        usort($pool, function ($a, $b) use ($axisInfo) {
            return (int)($axisInfo[$b]['delta'] ?? 0) <=> (int)($axisInfo[$a]['delta'] ?? 0);
        });

        return (string)($pool[0] ?? '');
    }

    private function dimsByDeltaDesc(array $axisInfo, array $dims): array
    {
        $pool = $dims;
        usort($pool, function ($a, $b) use ($axisInfo) {
            return (int)($axisInfo[$b]['delta'] ?? 0) <=> (int)($axisInfo[$a]['delta'] ?? 0);
        });
        return $pool;
    }

    // =========================
    // Old highlights normalize
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

    // =========================
    // Fallback builder
    // =========================

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
            'action'   => "建议：把{$dimName}优势用对地方",
            default    => "提示：{$dimName}是你的一条关键轴",
        };

        $text = match ($kind) {
            'strength' => "你在「{$dimName}」更偏 {$side}（强度 {$pct}%）：{$hint}。这会让你在相关场景里更容易做出高质量决策与行动。",
            'risk'     => "在「{$dimName}」上，你更偏 {$side}（强度 {$pct}%）。优势用过头时可能变成惯性：建议在关键场景加入一次“反向校验”，避免单一路径误判。",
            'action'   => "你在「{$dimName}」更偏 {$side}（强度 {$pct}%）。给自己加一个小流程：先写下第一反应，再补一个反向备选，然后再做决定/表达，输出会更稳。",
            default    => "你在「{$dimName}」更偏 {$side}（强度 {$pct}%）：{$hint}。",
        };

        $tips = match ($kind) {
            'strength' => ["把这个优势固定成你的“常用模板/流程”", "在团队里明确：你负责哪类决策最擅长"],
            'risk'     => ["重要决定前写一个“反方理由”", "找一个互补型的人做 2 分钟校验"],
            'action'   => ["第一反应写下来，再补一个反向备选", "给重要决定加 10 分钟冷却/复盘"],
            default    => [],
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

    // =========================
    // Normalize + UX sorting
    // =========================

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