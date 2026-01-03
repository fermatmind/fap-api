<?php

namespace App\Services\Report;

use Illuminate\Support\Facades\Log;
use App\Services\Rules\RuleEngine;
use App\Services\Content\ContentStore;

class HighlightBuilder
{
    /**
     * ✅ 新入口：直接从 ContentStore 读取 highlights doc（pools/templates）
     * 返回：['items' => highlights[], '_meta' => meta]
     */
    public function buildFromStore(
        array $report,
        ContentStore $store,
        int $min = 3,
        int $max = 4,
        array $selectRules = []
    ): array {
        $doc = $store->loadHighlights();
        return $this->buildFromDoc($report, $doc, $min, $max, $selectRules);
    }

    /**
     * ✅ 兼容旧入口：你以前 ReportComposer 传 doc 进来
     * 现在同样返回：['items'=>..., '_meta'=>...]
     */
    public function buildFromTemplatesDoc(
        array $report,
        $doc,
        int $min = 3,
        int $max = 4,
        array $selectRules = []
    ): array {
        $doc = $this->normalizeDoc($doc);
        return $this->buildFromDoc($report, $doc, $min, $max, $selectRules);
    }

    /**
     * ✅ 最终约束（建议在 overrides + ReportRuleEngine 之后再跑一次）
     * 作用：确保 max 2 strength、总数 3~4、补齐 blindspot/action、去重
     * 返回：['items'=>..., '_meta'=>...]
     */
    public function finalize(
        array $highlights,
        array $report,
        ContentStore $store,
        int $min = 3,
        int $max = 4
    ): array {
        $doc = $store->loadHighlights();
        $doc = $this->normalizeDoc($doc);

        [$tpls, $rulesCfg] = $this->extractTemplatesAndRules($doc);

        $meta = [
            'stage' => 'finalize',
            'schema' => is_array($doc) ? ($doc['schema'] ?? null) : null,
            'constraints' => [
                'max_strength' => 2,
                'total_min' => max(3, $min),
                'total_max' => min(4, $max),
            ],
            'input' => [
                'count_in' => is_array($highlights) ? count($highlights) : -1,
            ],
        ];

        $normalized = [];
        foreach ($highlights as $h) {
            if (is_array($h)) $normalized[] = $h;
        }

        // 去重（保留先出现的）
        $normalized = $this->dedupeById($normalized);

        // 分桶
        $strength = [];
        $blindspot = null;
        $action = null;
        $others = [];

        foreach ($normalized as $h) {
            $k = (string)($h['kind'] ?? '');
            if ($k === 'strength') $strength[] = $h;
            elseif ($k === 'blindspot' && !$blindspot) $blindspot = $h;
            elseif ($k === 'action' && !$action) $action = $h;
            else $others[] = $h;
        }

        // max 2 strength
        $strength = array_slice($strength, 0, 2);

        // 缺 blindspot/action 则生成
        $levelOrder = is_array($rulesCfg['level_order'] ?? null)
            ? $rulesCfg['level_order']
            : ["very_weak","weak","moderate","clear","strong","very_strong"];

        if (!$blindspot) {
            $blindspot = $this->buildBlindspot($report, $tpls, $levelOrder);
        }
        if (!$action) {
            $action = $this->buildAction($report, $strength);
        }

        // 确保至少 1 个 strength（否则总数达不到 3）
        if (count($strength) < 1) {
            $gen = $this->fallbackStrengthGenerated($report);
            $strength[] = $gen;
        }

        // 组装固定顺序：strength(<=2) + blindspot + action
        $out = [];
        foreach ($strength as $x) $out[] = $x;
        if (is_array($blindspot)) $out[] = $blindspot;
        if (is_array($action)) $out[] = $action;

        // 再去重一次（防止生成物撞 id）
        $out = $this->dedupeById($out);

        // 总数 3~4
        $totalMin = max(3, $min);
        $totalMax = min(4, $max);

        // 不足最小：优先补第 2 个 strength（如果还没满 2）
        if (count($out) < $totalMin && count($strength) < 2) {
            // 尝试从 “others 里已有 strength 以外的” 补一个 strength（如果存在）
            foreach ($others as $h) {
                if ((string)($h['kind'] ?? '') === 'strength') {
                    $out[] = $h;
                    break;
                }
            }
            $out = $this->dedupeById($out);
        }

        // 在 finalize() 里靠前位置加这一句（建议放在 $meta = [...] 后面）
$typeCode = (string) data_get($report, 'profile.type_code', '');

// 仍不足：补一个 “generated insight”
while (count($out) < $totalMin) {
    $out[] = [
        'id' => 'hl.insight.generated_' . substr(sha1($typeCode . microtime(true)), 0, 10),
        'kind' => 'insight',
        'title' => '补充要点',
        'text' => '补充要点：把你“最常做对的一件小事”固化成流程（写下来、复用它）。',
        'tips' => [],
        'tags' => ['generated', 'insight'],
    ];
            $out = $this->dedupeById($out);
            if (count($out) > 10) break; // safety
        }

        // 超过最大：截断到 4（保证强约束）
        if (count($out) > $totalMax) {
            $out = array_slice($out, 0, $totalMax);
        }

        $out = $this->normalizeHighlights($out, $typeCode);

        $meta['output'] = [
            'count_out' => count($out),
            'ids' => array_slice(array_map(fn($x) => $x['id'] ?? null, $out), 0, 10),
        ];

        return ['items' => $out, '_meta' => $meta];
    }

    // =========================
    // ✅ 核心：buildFromDoc
    // =========================
    private function buildFromDoc(
        array $report,
        ?array $doc,
        int $min,
        int $max,
        array $selectRules
    ): array {
        $meta = [
            'stage' => 'build',
            'schema' => is_array($doc) ? ($doc['schema'] ?? null) : null,
            'constraints' => [
                'max_strength' => 2,
                'total_min' => max(3, $min),
                'total_max' => min(4, $max),
            ],
            'rule_engine' => [
                'ctx' => 'highlights:strength',
                'evals_count' => 0,
                'evals_sample' => [],
            ],
        ];

        Log::info('[HL] builder_recv', [
            'is_null'   => $doc === null,
            'is_array'  => is_array($doc),
            'schema'    => is_array($doc) ? ($doc['schema'] ?? null) : null,
        ]);

        $schema = is_array($doc) ? (string)($doc['schema'] ?? '') : '';
$okSchema = in_array($schema, [
    // 旧 schema（你之前的 templates doc）
    'fap.report.highlights.templates.v1',
    'fap.report.highlights_templates.v1',

    // ✅ 新 schema（ContentStore::loadHighlights() 现在读到的）
    'fap.report.highlights.v1',
], true);

if (!$doc || !$okSchema) {
    $this->forbidHighlightsFallback("bad_or_missing_doc_schema={$schema}");
    $items = array_slice($this->fallbackGenerated($report), 0, min(4, $max));
    $typeCode = (string) data_get($report, 'profile.type_code', '');
    $items = $this->normalizeHighlights($items, $typeCode);
    return ['items' => $items, '_meta' => $meta];
}

        // templates/rules：支持 doc.templates / doc.pools.templates
        [$tpls, $rulesCfg] = $this->extractTemplatesAndRules($doc);

        if (!is_array($tpls) || $tpls === []) {
            $this->forbidHighlightsFallback('missing_templates');
            $items = array_slice($this->fallbackGenerated($report), 0, min(4, $max));
$typeCode = (string) data_get($report, 'profile.type_code', '');
$items = $this->normalizeHighlights($items, $typeCode);
return ['items' => $items, '_meta' => $meta];
        }

        $levelOrder = is_array($rulesCfg['level_order'] ?? null)
            ? $rulesCfg['level_order']
            : ["very_weak","weak","moderate","clear","strong","very_strong"];

        $allowed    = is_array($rulesCfg['allowed_levels'] ?? null)
            ? $rulesCfg['allowed_levels']
            : $levelOrder;

        $minLevel   = (string)($rulesCfg['min_level'] ?? 'clear');
        $minDelta   = (int)($rulesCfg['min_delta'] ?? 15);
        $topN       = (int)($rulesCfg['top_n'] ?? 2);
        $maxItems   = (int)($rulesCfg['max_items'] ?? 2);
        $allowEmpty = (bool)($rulesCfg['allow_empty'] ?? true);

        // 1) strength candidates（严格 / 降级）
        $cands = [];
        $softCands = [];

        foreach (['EI','SN','TF','JP','AT'] as $dim) {
            $side  = $this->inferSide($report, $dim);
            if (!$side) continue;

            $pct   = (int) data_get($report, "scores_pct.$dim", 50);
            $delta = abs($pct - 50);

            $level = data_get($report, "axis_states.$dim");
            if (!$level || !in_array($level, $allowed, true)) {
                $level = $this->levelFromDelta($delta);
            }

            $tpl = $this->pickTemplate($tpls, $dim, $side, $level, $levelOrder);
            if (!$tpl) continue;

            $item = [
                'kind'  => 'strength',
                'id'    => $tpl['id'] ?? "{$dim}_{$side}_{$level}",
                'title' => $tpl['title'] ?? '',
                'text'  => $tpl['text'] ?? '',
                'tips'  => $tpl['tips'] ?? [],
                'tags'  => $tpl['tags'] ?? [],
                'priority' => (int)($tpl['priority'] ?? 0),
                'rules'    => is_array($tpl['rules'] ?? null) ? $tpl['rules'] : [],
                '_dim'  => $dim,
                '_side' => $side,
                '_lvl'  => $level,
                '_delta'=> $delta,
            ];

            $gateLevelOk = $this->levelRank($level, $levelOrder) >= $this->levelRank($minLevel, $levelOrder);
            $gateDeltaOk = $delta >= $minDelta;

            if ($gateLevelOk && $gateDeltaOk) {
                $cands[] = $item;
            } else {
                $softCands[] = $item;
            }
        }

        // 2) pool：优先严格；允许空则用 soft
        $pool = $cands;
        if (empty($pool) && $allowEmpty) {
            $pool = $softCands;
        }

        // priority 兜底：没给 priority 就用 delta
        foreach ($pool as &$it) {
            if (!is_array($it)) continue;
            if (!isset($it['priority']) || (int)$it['priority'] === 0) {
                $it['priority'] = (int)($it['_delta'] ?? 0);
            }
        }
        unset($it);

        // 3) RuleEngine：selectTargeted（并收集 evals => _meta）
        /** @var RuleEngine $re */
        $re = app(RuleEngine::class);

        $debugRE = app()->environment('local', 'development') && (bool) env('FAP_RE_DEBUG', false);
        $captureExplain = (bool)($report['capture_explain'] ?? $report['_capture_explain'] ?? false);

        $explainCollector = null;
        if (isset($report['explain_collector']) && is_callable($report['explain_collector'])) {
            $explainCollector = $report['explain_collector'];
        } elseif (isset($report['_explain_collector']) && is_callable($report['_explain_collector'])) {
            $explainCollector = $report['_explain_collector'];
        }

        // userSet：tags => set
        $userSet = [];
        $rtags = $report['tags'] ?? [];
        if (is_array($rtags)) {
            foreach ($rtags as $t) {
                if (is_string($t) && $t !== '') $userSet[$t] = true;
            }
        }

        $typeCode = (string) data_get($report, 'profile.type_code', '');
if ($typeCode !== '') $userSet["type:{$typeCode}"] = true;

        $take = max(0, min((int)$topN, (int)$maxItems));
        $take = min($take, 2); // ✅ 产物层约束：max 2 strength（强约束）

        $seed = (int)(sprintf('%u', crc32($typeCode)));

        [$selectedPool, $_evals] = $re->selectTargeted(
            $pool,
            $userSet,
            [
                'target' => 'highlights',
                'rules'  => $selectRules,
                'ctx'    => 'highlights:strength',
                'seed'   => $seed,
                'max_items'         => $take,
                'rejected_samples'  => 5,
                'debug'             => $debugRE,
                'capture_explain'   => $captureExplain,
                'explain_collector' => $explainCollector,
                'tags_debug_n'      => 0,
            ]
        );

        $meta['rule_engine']['evals_count'] = is_array($_evals) ? count($_evals) : 0;
        $meta['rule_engine']['evals_sample'] = is_array($_evals) ? array_slice($_evals, 0, 20) : [];

        $strength = is_array($selectedPool) ? $selectedPool : [];

        // ✅ 如果 strength 一个都没选到：保证至少 1 个 strength（优先 soft pool 再生成）
        if (count($strength) < 1) {
            // 尝试直接按 priority 取 1（稳定）
            $fallbackPick = $pool;
            usort($fallbackPick, fn($a,$b) => ((int)($b['priority'] ?? 0)) <=> ((int)($a['priority'] ?? 0)));
            $picked = $fallbackPick[0] ?? null;

            if (is_array($picked)) {
                $strength = [$picked];
                $meta['rule_engine']['fallback_strength'] = 'picked_from_pool_by_priority';
            } else {
                $strength = [$this->fallbackStrengthGenerated($report)];
                $meta['rule_engine']['fallback_strength'] = 'generated_strength';
            }
        }

        // 4) blindspot / action（产物固定补齐）
        $blindspot = $this->buildBlindspot($report, $tpls, $levelOrder);
        $action    = $this->buildAction($report, $strength);

        // 5) merge（固定顺序）
        $out = [];
        foreach ($strength as $x) $out[] = $this->stripPrivate($x);
        if (is_array($blindspot)) $out[] = $blindspot;
        if (is_array($action))    $out[] = $action;

        // 去重
        $out = $this->dedupeById($out);

        // ✅ 产物层约束：总数 3~4
        $totalMin = max(3, $min);
        $totalMax = min(4, $max);

        // 不足 min：补一个 insight
        while (count($out) < $totalMin) {
            $out[] = [
                'id' => 'hl.insight.generated_' . substr(sha1($typeCode . microtime(true)), 0, 10),
                'kind' => 'insight',
                'title' => '补充要点',
                'text' => '补充要点：把你最有把握的一条习惯固化成“固定触发 + 固定动作 + 固定复盘”。',
                'tips' => [],
                'tags' => ['generated', 'insight', $typeCode],
            ];
            $out = $this->dedupeById($out);
            if (count($out) > 10) break; // safety
        }

        // 超过 max：截断
        if (count($out) > $totalMax) {
            $out = array_slice($out, 0, $totalMax);
        }

        // ✅ 统一补齐 title/tips/tags
        $out = $this->normalizeHighlights($out, $typeCode);

        $meta['output'] = [
            'count' => count($out),
            'ids' => array_slice(array_map(fn($x) => $x['id'] ?? null, $out), 0, 10),
        ];

        return ['items' => $out, '_meta' => $meta];
    }

    /**
     * 从 doc 里抽 templates + rulesCfg
     * - 支持 doc.templates
     * - 支持 doc.pools.templates（你方案里说的 pools/templates）
     */
    private function extractTemplatesAndRules(?array $doc): array
    {
        $tpls = [];
        $rulesCfg = [];

        if (is_array($doc)) {
            // templates
            if (is_array($doc['templates'] ?? null)) {
                $tpls = $doc['templates'];
            } elseif (is_array($doc['pools']['templates'] ?? null)) {
                $tpls = $doc['pools']['templates'];
            } elseif (is_array($doc['pools']['template_map'] ?? null)) {
                $tpls = $doc['pools']['template_map'];
            }

            // rules
            if (is_array($doc['rules'] ?? null)) {
                $rulesCfg = $doc['rules'];
            } elseif (is_array($doc['pools']['rules'] ?? null)) {
                $rulesCfg = $doc['pools']['rules'];
            }
        }

        return [$tpls, $rulesCfg];
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

        if (is_object($doc)) {
            $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
        }
        if (!is_array($doc)) return null;

        if (isset($doc['doc'])) {
            $inner = $doc['doc'];
            if (is_object($inner)) $inner = json_decode(json_encode($inner, JSON_UNESCAPED_UNICODE), true);
            if (is_array($inner)) $doc = $inner;
        } elseif (isset($doc['data'])) {
            $inner = $doc['data'];
            if (is_object($inner)) $inner = json_decode(json_encode($inner, JSON_UNESCAPED_UNICODE), true);
            if (is_array($inner)) $doc = $inner;
        }

        $doc = json_decode(json_encode($doc, JSON_UNESCAPED_UNICODE), true);
        return is_array($doc) ? $doc : null;
    }

    private function inferSide(array $report, string $dim): ?string
    {
        $typeCode = (string) data_get($report, 'profile.type_code', '');
if ($typeCode === '') return null;

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
            $pct = (int) data_get($report, "scores_pct.$dim", 50);
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
                'title' => '盲点提醒',
                'text' => '你有一条维度更接近中间值，容易受情境影响；把“选择标准”写下来会更稳。',
                'tips' => [],
                'tags' => ['generated', 'blindspot'],
            ];
        }

        $side  = $this->inferSide($report, $bestDim) ?: 'E';
        $level = data_get($report, "axis_states.$bestDim");

// ✅ normalize: 如果 axis_states 给了一个不在 levelOrder 里的值（比如 borderline）
// 就回退到 delta -> levelFromDelta，保证能命中模板结构
$level = is_string($level) ? trim($level) : '';
if ($level === '' || $this->levelRank($level, $levelOrder) < 0) {
    $level = $this->levelFromDelta($bestDelta);
}

        $tpl = $this->pickTemplate($tpls, $bestDim, $side, $level, $levelOrder);

        $title = $tpl['title'] ?? '';
        $text  = $tpl['text'] ?? '这一轴更接近均衡，你的状态更受场景影响。';

        // ✅ fix: avoid "hl.blindspot.hl.xxx"
        $tplId = (string)($tpl['id'] ?? "{$bestDim}_{$side}_{$level}");
        $tplId = preg_replace('/^hl\./', '', $tplId); // strip leading "hl."

        return [
            'id' => 'hl.blindspot.' . $tplId,
            'kind' => 'blindspot',
            'title' => '盲点提醒',
            'text' => "盲点提醒：{$title}。{$text}",
            'tips' => [],
            'tags' => is_array($tpl['tags'] ?? null) ? $tpl['tags'] : ['blindspot'],
        ];
    }

    private function buildAction(array $report, array $strength): array
    {
        $typeCode = (string) data_get($report, 'profile.type_code', '');
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
            'title' => '行动建议',
            'text' => $text,
            'tips' => [],
            'tags' => ['generated', 'action'],
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

        $typeCode = (string) data_get($report, 'profile.type_code', '');
        return [
            [
                'id' => 'hl.strength.generated_01',
                'kind' => 'strength',
                'title' => '优势亮点',
                'text' => "你的优势是更容易把事情推到“可交付”。（{$typeCode}）",
                'tips' => [],
                'tags' => ['generated', 'strength', $typeCode],
            ],
            [
                'id' => 'hl.blindspot.generated_01',
                'kind' => 'blindspot',
                'title' => '盲点提醒',
                'text' => "盲点提醒：你可能在某些维度更接近均衡，容易受情境影响；先写下“选择标准”会更稳。",
                'tips' => [],
                'tags' => ['generated', 'blindspot', $typeCode],
            ],
            [
                'id' => 'hl.action.generated_01',
                'kind' => 'action',
                'title' => '行动建议',
                'text' => "行动建议：本周选 1 个目标 → 拆成 3 个节点 → 每天推进最小一步，持续 7 天复盘。",
                'tips' => [],
                'tags' => ['generated', 'action', $typeCode],
            ],
        ];
    }

    private function fallbackStrengthGenerated(array $report): array
    {
        $typeCode = (string) data_get($report, 'profile.type_code', '');
        return [
            'id' => 'hl.strength.generated_fallback_01',
            'kind' => 'strength',
            'title' => '优势亮点',
            'text' => "优势补齐：你更容易把复杂任务拆解成可执行步骤。（{$typeCode}）",
            'tips' => [],
            'tags' => ['generated', 'strength', $typeCode],
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
            if (!is_array($it)) continue;
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
    $h['kind'] = is_string($h['kind'] ?? null) && $h['kind'] !== '' ? $h['kind'] : 'highlight';
    $h['id']   = is_string($h['id'] ?? null)   && $h['id'] !== ''   ? $h['id']   : ('hl.generated.'.uniqid());
    $h['text'] = is_string($h['text'] ?? null) && $h['text'] !== '' ? $h['text'] : '';

    if (!is_string($h['title'] ?? null) || trim($h['title']) === '') {
        $h['title'] =
            $h['kind'] === 'blindspot' ? '盲点提醒' :
            ($h['kind'] === 'action'   ? '行动建议' :
            ($h['kind'] === 'strength' ? '优势亮点' : '要点'));
    }

    if (!is_array($h['tips'] ?? null)) $h['tips'] = [];
    if (!is_array($h['tags'] ?? null)) $h['tags'] = [];

    // ✅ 你现有的统一注入点（保持不动）
    $h = ReportContentNormalizer::fillTipsIfMissing($h);

    if (count($h['tags']) < 1) {
        $h['tags'] = ['generated', $h['kind'], $typeCode];
    }

    // =========================
    // ✅ 新增：pool + explain（为了验收闭环）
    // - pool：优先 pool，否则用 kind
    // - explain：必须非空字符串（验收要 all items 有 explain）
    // =========================
    if (!is_string($h['pool'] ?? null) || trim((string)$h['pool']) === '') {
        $h['pool'] = (string)($h['kind'] ?? 'highlight');
    }

    if (!is_string($h['explain'] ?? null) || trim((string)$h['explain']) === '') {
        // 给一个稳定可读的 explain（不要空）
        $src = in_array('generated', $h['tags'] ?? [], true) ? 'generated' : 'selected';
        $h['explain'] = "{$src}:{$h['pool']}";
    }

    return $h;
}
}