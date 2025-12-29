<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\Log;
use App\Services\Rules\RuleEngine;

class HighlightBuilder
{
    public function buildFromTemplatesDoc(array $report, $doc, int $min = 3, int $max = 10): array
    {
        // 0) normalize: support wrapped + stdClass -> array
        $doc = $this->normalizeDoc($doc);

Log::info('[HL] builder_recv', [
    'is_null'   => $doc === null,
    'is_array'  => is_array($doc),
    'schema'    => is_array($doc) ? ($doc['schema'] ?? null) : null,
    'tpl_dims'  => (is_array($doc) && is_array($doc['templates'] ?? null)) ? array_keys($doc['templates']) : null,
    'has_rules' => (is_array($doc) && is_array($doc['rules'] ?? null)),
]);

        Log::info('[HL] templates_doc_shape', [
            'is_null' => $doc === null,
            'keys'    => is_array($doc) ? array_slice(array_keys($doc), 0, 20) : null,
            'schema'  => is_array($doc) ? ($doc['schema'] ?? null) : null,
        ]);

                // 如果 doc 不符合 schema / templates 结构不对：允许 fallback，但若开了爆炸开关则直接炸
        $schema = is_array($doc) ? (string)($doc['schema'] ?? '') : '';
        $okSchema = in_array($schema, [
            'fap.report.highlights.templates.v1',
            'fap.report.highlights_templates.v1', // 容错：有些包会用下划线命名
        ], true);

        if (!$doc || !is_array($doc) || !$okSchema) {
            $this->forbidHighlightsFallback("bad_or_missing_doc_schema={$schema}");
            return array_slice($this->fallbackGenerated($report), 0, $max);
        }

        if (!is_array($doc['templates'] ?? null) || ($doc['templates'] ?? []) === []) {
            $this->forbidHighlightsFallback('missing_templates');
            return array_slice($this->fallbackGenerated($report), 0, $max);
        }

        $rules = $doc['rules'] ?? [];
        $tpls  = $doc['templates'] ?? [];

        $levelOrder = $rules['level_order'] ?? ["very_weak","weak","moderate","clear","strong","very_strong"];
        $allowed    = $rules['allowed_levels'] ?? $levelOrder;

        $minLevel   = $rules['min_level'] ?? 'clear';
        $minDelta   = (int)($rules['min_delta'] ?? 15);
        $topN       = (int)($rules['top_n'] ?? 2);
        $maxItems   = (int)($rules['max_items'] ?? 2);
        $allowEmpty = (bool)($rules['allow_empty'] ?? true);

        // 1) strength candidates
        $cands = [];      // 严格命中（通过 gate）
        $softCands = [];  // 降级候选（不通过 gate，但模板可用）

        foreach (['EI','SN','TF','JP','AT'] as $dim) {
            $side  = $this->inferSide($report, $dim);
            if (!$side) continue;

            $pct   = (int)($report['scores_pct'][$dim] ?? 50);
            $delta = abs($pct - 50);

            $level = $report['axis_states'][$dim] ?? null;
            if (!$level || !in_array($level, $allowed, true)) {
                $level = $this->levelFromDelta($delta);
            }

            // 先拿模板：这样即使 gate 不过，也能作为 softCands 候选
            $tpl = $this->pickTemplate($tpls, $dim, $side, $level, $levelOrder);
            if (!$tpl) continue;

            $item = [
                'kind'  => 'strength',
                'id'    => $tpl['id'] ?? "{$dim}_{$side}_{$level}",
                'title' => $tpl['title'] ?? '',
                'text'  => $tpl['text'] ?? '',
                'tips'  => $tpl['tips'] ?? [],
                'tags'  => $tpl['tags'] ?? [],

                'priority' => (int)($tpl['priority'] ?? 0),          // 没有就 0
                'rules'    => is_array($tpl['rules'] ?? null) ? $tpl['rules'] : [], // 没有就空
                '_dim'  => $dim,
                '_side' => $side,
                '_lvl'  => $level,
                '_delta'=> $delta,
            ];

            // gate：min_level + min_delta
            $gateLevelOk = $this->levelRank($level, $levelOrder) >= $this->levelRank($minLevel, $levelOrder);
            $gateDeltaOk = $delta >= $minDelta;

            if ($gateLevelOk && $gateDeltaOk) {
                $cands[] = $item;
            } else {
                $softCands[] = $item;
            }
        }

        // 2) strength：优先用严格候选；若一个都没有且 allow_empty=true，用降级候选
        $pool = $cands;
        if (empty($pool) && $allowEmpty) {
            $pool = $softCands; // ✅ 全选 C / delta=0 也能从 very_weak 模板出 strength
        }

        // ✅ priority 兜底：模板没给 priority 时，用 delta 当 priority（更符合 highlights 直觉）
foreach ($pool as &$it) {
    if (!is_array($it)) continue;
    if (!isset($it['priority']) || (int)$it['priority'] === 0) {
        $it['priority'] = (int)($it['_delta'] ?? 0);
    }
}
unset($it);

// ✅ 用 RuleEngine 做 “排序+截断+explain”
/** @var RuleEngine $re */
$re = app(RuleEngine::class);

// userSet：用 report.tags 做集合（cards/reads 也是同口径）
$userSet = [];
$rtags = $report['tags'] ?? [];
if (is_array($rtags)) {
    foreach ($rtags as $t) {
        if (is_string($t) && $t !== '') $userSet[$t] = true;
    }
}

// 兜底：至少塞一个 type tag（避免完全空集合）
$typeCode = (string)($report['profile']['type_code'] ?? '');
if ($typeCode !== '') $userSet["type:{$typeCode}"] = true;

// ✅ priority 兜底：模板没给 priority 时，用 delta 当 priority
foreach ($pool as &$it) {
    if (!is_array($it)) continue;
    if (!isset($it['priority']) || (int)$it['priority'] === 0) {
        $it['priority'] = (int)($it['_delta'] ?? 0);
    }
}
unset($it);

// take：保持你原意 topN/maxItems 取较小
$take = max(0, min((int)$topN, (int)$maxItems));

// seed：稳定（同一个 type_code 每次一致）
$seed = (int)(sprintf('%u', crc32($typeCode)));

[$selectedPool, $_evals] = $re->select(
    $pool,
    $userSet,
    [
        'ctx' => 'highlights:strength',
        'seed' => $seed,
        'max_items' => $take,
        'rejected_samples' => 5,
        'debug' => true, // ✅ 关键：强制出 [RE] explain（只要 APP_ENV=local）
    ]
);

// select() 返回 item（可能带 _re），后续 stripPrivate 会删掉
$strength = $selectedPool;

        // 3) blindspot
        $blindspot = $this->buildBlindspot($report, $tpls, $levelOrder);

        // 4) action
        $action = $this->buildAction($report, $strength);

        // 5) merge
        $out = [];
        foreach ($strength as $x) $out[] = $this->stripPrivate($x);
        if ($blindspot) $out[] = $blindspot;
        if ($action)    $out[] = $action;

        if (count($out) < $min) {
            $this->forbidHighlightsFallback('out_lt_min out=' . count($out) . " min={$min}");
            $out = array_merge($out, $this->fallbackGenerated($report));
        }

        $out = $this->dedupeById($out);

// ✅ 新增：统一补齐 title/tips/tags 等
$typeCode = (string)($report['profile']['type_code'] ?? '');
$out = $this->normalizeHighlights($out, $typeCode);

return array_slice($out, 0, $max);
    }

    /**
     * normalize doc:
     * - allow null
     * - unwrap {doc:{...}} / {data:{...}}
     * - convert stdClass/object -> array (recursive)
     */
    private function normalizeDoc($doc): ?array
    {
        if ($doc === null) return null;

        // object -> array (recursive)
        if (is_object($doc)) {
            $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
        }

        if (!is_array($doc)) return null;

        // unwrap common wrappers
        if (isset($doc['doc'])) {
            $inner = $doc['doc'];
            if (is_object($inner)) $inner = json_decode(json_encode($inner, JSON_UNESCAPED_UNICODE), true);
            if (is_array($inner)) $doc = $inner;
        } elseif (isset($doc['data'])) {
            $inner = $doc['data'];
            if (is_object($inner)) $inner = json_decode(json_encode($inner, JSON_UNESCAPED_UNICODE), true);
            if (is_array($inner)) $doc = $inner;
        }

        // still could contain nested objects: force deep normalize
        $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
        return is_array($doc) ? $doc : null;
    }

    private function inferSide(array $report, string $dim): ?string
    {
        $typeCode = $report['profile']['type_code'] ?? '';
        if (!$typeCode) return null;

        $base = explode('-', $typeCode)[0];
        $suffix = str_contains($typeCode, '-A') ? 'A' : (str_contains($typeCode, '-T') ? 'T' : '');

        return match ($dim) {
            'EI' => (str_contains($base, 'E') ? 'E' : 'I'),
            'SN' => (str_contains($base, 'S') ? 'S' : 'N'),
            'TF' => (str_contains($base, 'T') ? 'T' : 'F'),
            'JP' => (str_contains($base, 'J') ? 'J' : 'P'),
            'AT' => ($suffix ?: null),
            default => null,
        };
    }

    private function pickTemplate(array $tpls, string $dim, string $side, string $level, array $levelOrder): ?array
    {
        if (isset($tpls[$dim][$side][$level]) && is_array($tpls[$dim][$side][$level])) {
            return $tpls[$dim][$side][$level];
        }

        $idx = $this->levelRank($level, $levelOrder);
        for ($i = $idx; $i >= 0; $i--) {
            $lv = $levelOrder[$i] ?? null;
            if ($lv && isset($tpls[$dim][$side][$lv]) && is_array($tpls[$dim][$side][$lv])) {
                return $tpls[$dim][$side][$lv];
            }
        }
        return null;
    }

    private function buildBlindspot(array $report, array $tpls, array $levelOrder): array
    {
        $bestDim = null;
        $bestDelta = 999;

        foreach (['EI','SN','TF','JP','AT'] as $dim) {
            $pct = (int)($report['scores_pct'][$dim] ?? 50);
            $delta = abs($pct - 50);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $bestDim = $dim;
            }
        }

        if (!$bestDim) {
            return [
                'id' => 'hl.blindspot.generated_01',
                'kind' => 'blindspot',
                'text' => '你有一条维度更接近中间值，容易受情境影响；把“选择标准”写下来会更稳。',
            ];
        }

        $side  = $this->inferSide($report, $bestDim) ?: 'E';
        $level = $report['axis_states'][$bestDim] ?? $this->levelFromDelta($bestDelta);

        $tpl = $this->pickTemplate($tpls, $bestDim, $side, $level, $levelOrder);

        $title = $tpl['title'] ?? '';
        $text  = $tpl['text'] ?? '这一轴更接近均衡，你的状态更受场景影响。';

        return [
            'id' => 'hl.blindspot.' . ($tpl['id'] ?? "{$bestDim}_{$side}_{$level}"),
            'kind' => 'blindspot',
            'text' => "盲点提醒：{$title}。{$text}",
        ];
    }

    private function buildAction(array $report, array $strength): array
    {
        $typeCode = $report['profile']['type_code'] ?? '';
        $hint = '';

        if (!empty($strength)) {
            $top = $strength[0];
            $hint = ($top['_dim'] ?? '') . ($top['_side'] ?? '');
        }

        $text = "行动建议：把当下最重要的目标写成 1 句话，并拆成 3 个可交付节点（本周就能看到进展）。";
        if ($typeCode) $text .= "（{$typeCode}）";
        if ($hint) $text .= " 你可以把 {$hint} 的优势用在“先推进一小步”。";

        return [
            'id' => 'hl.action.generated_01',
            'kind' => 'action',
            'text' => $text,
        ];
    }

    private function forbidHighlightsFallback(string $reason): void
    {
        if ((bool) env('FAP_FORBID_HIGHLIGHTS_FALLBACK', false)) {
            throw new \RuntimeException('HIGHLIGHTS_FALLBACK_USED: ' . $reason);
        }
    }

    private function fallbackGenerated(array $report): array
    {
        $this->forbidHighlightsFallback('fallbackGenerated');

        $typeCode = $report['profile']['type_code'] ?? '';
        return [
            [
                'id' => 'hl.strength.generated_01',
                'kind' => 'strength',
                'text' => "你的优势是更容易把事情推到“可交付”。（{$typeCode}）",
            ],
            [
                'id' => 'hl.blindspot.generated_01',
                'kind' => 'blindspot',
                'text' => "盲点提醒：你可能在某些维度更接近均衡，容易受情境影响；先写下“选择标准”会更稳。",
            ],
            [
                'id' => 'hl.action.generated_01',
                'kind' => 'action',
                'text' => "行动建议：本周选 1 个目标 → 拆成 3 个节点 → 每天推进最小一步，持续 7 天复盘。",
            ],
        ];
    }

    private function levelRank(string $level, array $order): int
    {
        $idx = array_search($level, $order, true);
        return $idx === false ? -1 : $idx;
    }

    private function levelFromDelta(int $delta): string
    {
        if ($delta >= 35) return 'very_strong';
        if ($delta >= 25) return 'strong';
        if ($delta >= 15) return 'clear';
        if ($delta >= 8)  return 'moderate';
        if ($delta >= 3)  return 'weak';
        return 'very_weak';
    }

    private function stripPrivate(array $x): array
{
    unset($x['_dim'], $x['_side'], $x['_lvl'], $x['_delta'], $x['_re']);
    return $x;
}

    private function dedupeById(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $it) {
            $id = $it['id'] ?? null;
            if (!$id || isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = $it;
        }
        return $out;
    }

    public function normalizeHighlights(array $highlights, string $typeCode): array
{
    $out = [];
    foreach ($highlights as $h) {
        if (!is_array($h)) continue;
        $out[] = $this->normalizeHighlightItem($h, $typeCode);
    }
    return $out;
}

private function normalizeHighlightItem(array $h, string $typeCode): array
    {
        // kind/id/text：保证是 string
        $h['kind'] = is_string($h['kind'] ?? null) && $h['kind'] !== '' ? $h['kind'] : 'highlight';
        $h['id']   = is_string($h['id'] ?? null)   && $h['id'] !== ''   ? $h['id']   : ('hl.generated.'.uniqid());
        $h['text'] = is_string($h['text'] ?? null) && $h['text'] !== '' ? $h['text'] : '';

        // title：blindspot/action 兜底
        if (!is_string($h['title'] ?? null) || trim($h['title']) === '') {
            $h['title'] =
                $h['kind'] === 'blindspot' ? '盲点提醒' :
                ($h['kind'] === 'action'   ? '行动建议' : '要点');
        }

        // tips/tags：先统一成数组
        if (!is_array($h['tips'] ?? null)) $h['tips'] = [];
        if (!is_array($h['tags'] ?? null)) $h['tags'] = [];

        // ✅ 这里就是你问的：统一补默认 tips 的唯一注入点
        $h = ReportContentNormalizer::fillTipsIfMissing($h);

        // tags：可选兜底
        if (count($h['tags']) < 1) {
            $h['tags'] = ['generated', $h['kind'], $typeCode];
        }

        return $h;
    }
}