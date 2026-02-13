<?php

namespace App\Services\Rules;

use Illuminate\Support\Facades\Log;

final class RuleEngine
{
    /**
     * 单条评估
     *
     * @param array $item  至少包含：id,tags,priority；规则可在 item.rules 或顶层字段里
     * @param array $userSet  形如 ['role:SJ'=>true,'axis:EI:E'=>true,...]
     * @param array $opt  ['seed'=>123,'ctx'=>'cards:traits','debug'=>true,'global_rules'=>[...] ]
     * @return array 评估结果：ok/hit/priority/min_match/score/reason/detail/shuffle
     */
    public function evaluate(array $item, array $userSet, array $opt = []): array
{
    $id = (string)($item['id'] ?? '');

    // ✅ 1) contextSet vs evalSet
    // - contextSet：只包含“上下文/用户”tags（TagBuilder 产物）
    // - evalSet：用于规则判断，= contextSet ∪ item.tags ∪ section:xxx ∪ item:<id>
    $contextSet = is_array($userSet) ? $userSet : [];
    $evalSet    = $contextSet;

    // item.tags（候选 item 自己的 tags）
    $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
    $tags = array_values(array_filter($tags, fn ($x) => is_string($x) && trim($x) !== ''));

// --- build evalSet: context ∪ item.tags ∪ section ∪ item:id ---
foreach ($tags as $t) {
    $evalSet[$t] = true;
}

// section：优先 opt.section，其次 item.section，最后尝试从 opt.ctx 里猜（cards:traits 这种）
$section = null;
if (isset($opt['section']) && is_string($opt['section'])) $section = $opt['section'];
elseif (isset($item['section']) && is_string($item['section'])) $section = $item['section'];
elseif (isset($opt['ctx']) && is_string($opt['ctx']) && str_contains($opt['ctx'], ':')) {
    $section = explode(':', $opt['ctx'], 2)[1] ?? null;
}

if (is_string($section) && trim($section) !== '') {
    $evalSet['section:' . trim($section)] = true;
}

if ($id !== '') {
    $evalSet['item:' . $id] = true;
}

    $priority = (int)($item['priority'] ?? 0);

    // ✅ 先取 global_rules（避免先用后定义）
    $globalRules = is_array(($opt['global_rules'] ?? null)) ? $opt['global_rules'] : [];

    // item.rules（嵌套规则）
    $itemRules = is_array($item['rules'] ?? null) ? $item['rules'] : [];

    // 顶层扁平规则（兼容 cards/highlights/reads 直接塞在 item 上）
    $flatRules = [];
    foreach (['require_all', 'require_any', 'forbid'] as $k) {
        if (is_array($item[$k] ?? null)) {
            $flatRules[$k] = $item[$k];
        }
    }
    if (array_key_exists('min_match', $item)) {
        $flatRules['min_match'] = (int)$item['min_match'];
    }

    // ✅ 优先级：global -> flat -> rules（后者覆盖/补充前者）
    $rules = $this->mergeRules(
        $this->mergeRules($globalRules, $flatRules),
        $itemRules
    );

    // ✅ 2) hitCount：只算 item.tags 与 contextSet 的交集（不要用 evalSet）
    $hit = 0;
foreach ($tags as $t) {
    if (isset($contextSet[$t])) $hit++;
}

    // ✅ 3) 规则校验：用 evalSet（能看到 item.tags/section/item:id）
[$ok, $reason, $detail] = $this->passesRules($rules, $evalSet, $hit);
    // score（最小版）
    $score = $priority + 10 * $hit;

    return [
        'id'        => $id,
        'ok'        => (bool)$ok,
        'reason'    => (string)$reason,
        'detail'    => is_array($detail) ? $detail : [],
        'hit'       => (int)$hit,
        'priority'  => (int)$priority,
        'min_match' => (int)($rules['min_match'] ?? 0),
        'score'     => (int)$score,
        'shuffle'   => $this->stableShuffleKey((int)($opt['seed'] ?? 0), $id),
    ];
}

    /**
     * 批量选择：filter(ok) + sort(score desc, shuffle asc) + take(max_items)
     *
     * @param array $items
     * @param array $userSet  ['tag'=>true,...]
     * @param array $opt ['ctx'=>'reads:by_role','debug'=>true,'seed'=>123,'max_items'=>3,'rejected_samples'=>5,'global_rules'=>[...] ]
     * @return array [$selectedItems, $evaluations]
     */
    public function select(array $items, array $userSet, array $opt = []): array
    {
        if (app()->environment('local') && (bool)\App\Support\RuntimeConfig::value('RE_TAGS', false)) {
    Log::debug('[RE] select_enter', [
        'ctx' => $opt['ctx'] ?? null,
        'items_n' => is_array($items) ? count($items) : null,
        'tags_debug_n' => $opt['tags_debug_n'] ?? null,
        'debug' => (bool)($opt['debug'] ?? false),
    ]);
}

        $ctx   = (string)($opt['ctx'] ?? 're');
        $seed  = (int)($opt['seed'] ?? 0);
        $max   = array_key_exists('max_items', $opt) ? (int)$opt['max_items'] : count($items);
        $rejN  = array_key_exists('rejected_samples', $opt) ? (int)$opt['rejected_samples'] : 5;
        $debug = (bool)($opt['debug'] ?? false);

// --- DEBUG CONTEXT TAGS (local only) ---
if (
    app()->environment('local') &&
    (bool) \App\Support\RuntimeConfig::value('RE_CTX_TAGS', false) &&
    ((bool)($opt['debug'] ?? false) || (bool)\App\Support\RuntimeConfig::value('RE_TAGS', false))
) {
    Log::debug('[RE] context_tags', [
        'ctx'  => $ctx,
        'keys' => $this->pickContextKeys($userSet),
    ]);
}

// ✅ tags_debug 抽样：默认只打前 3 条（可用 opt['tags_debug_n'] 调整）
$tagsDebugN = array_key_exists('tags_debug_n', $opt)
    ? (int)$opt['tags_debug_n']
    : (int) \App\Support\RuntimeConfig::value('RE_TAGS_DEBUG_N', 3);

// 非 cards ctx：直接关掉 tags_debug
if (!str_starts_with((string)($opt['ctx'] ?? ''), 'cards:')) {
    $tagsDebugN = 0;
}
if ($tagsDebugN < 0) $tagsDebugN = 0;
$tagsDebugI = 0;

        $globalRules = is_array(($opt['global_rules'] ?? null)) ? $opt['global_rules'] : [];

        if ($max < 0) $max = 0;
        if ($rejN < 0) $rejN = 0;

        $evals    = [];
        $oks      = [];
        $rejected = [];

        foreach ($items as $it) {
            if (!is_array($it)) continue;

        // --- DEBUG TAGS (local only) ---
if (
    $tagsDebugI < $tagsDebugN &&
    app()->environment('local') &&
    ((bool)($opt['debug'] ?? false) || (bool)\App\Support\RuntimeConfig::value('RE_TAGS', false))
) {
    $section = null;
    if (isset($opt['section']) && is_string($opt['section'])) {
        $section = $opt['section'];
    } elseif (isset($it['section']) && is_string($it['section'])) {
        $section = $it['section'];
    } elseif (isset($opt['ctx']) && is_string($opt['ctx']) && str_contains($opt['ctx'], ':')) {
        $section = explode(':', $opt['ctx'], 2)[1] ?? null;
    }

    $itemId = (string)($it['id'] ?? '');

    $evalSet = \App\Services\Rules\TagBuilder::buildEvalTags($userSet, $it, $section, $itemId);

    $added = array_values(array_diff(array_keys($evalSet), array_keys($userSet)));
    sort($added, SORT_STRING);

    Log::debug('[RE] tags_debug', [
        'ctx' => $opt['ctx'] ?? null,
        'section' => $section,
        'item_id' => $itemId,
        'added' => $added,
        'has_section' => ($section !== null && $section !== '') ? isset($evalSet['section:' . $section]) : false,
        'has_item' => ($itemId !== '') ? isset($evalSet['item:' . $itemId]) : false,
    ]);

    $tagsDebugI++; // ✅ 只对打过日志的 item 计数
}

            $e = $this->evaluate($it, $userSet, [
                'seed'         => $seed,
                'global_rules' => $globalRules,
            ] + $opt);

            $evals[] = $e;

            if ($e['ok'] ?? false) {
                $it['_re'] = $e;
                $oks[] = $it;
            } else {
                $rejected[] = $e;
            }
        }

        // sort: score desc, shuffle asc（稳定）
        usort($oks, function ($a, $b) {
            $ea = $a['_re'] ?? [];
            $eb = $b['_re'] ?? [];

            $sa = (int)($ea['score'] ?? 0);
            $sb = (int)($eb['score'] ?? 0);
            if ($sa !== $sb) return $sb <=> $sa;

            $ha = (int)($ea['shuffle'] ?? 0);
            $hb = (int)($eb['shuffle'] ?? 0);
            return $ha <=> $hb;
        });

        $selected = array_slice($oks, 0, $max);

        // ✅ explain payload（固定格式：selected/rejected + reasons.details 最小字段）
$selectedExplains = array_map(function ($it) {
    $e = $it['_re'] ?? [];
    return [
        'id'    => (string)($e['id'] ?? ($it['id'] ?? '')),
        'final' => 'keep',
        'reasons' => [[
            'rule'     => 'rule_engine',
            'decision' => 'keep',
            'matched'  => true,
            'details'  => is_array($e['detail'] ?? null) ? $e['detail'] : [
                'hit_require_all' => [],
                'miss_require_all' => [],
                'hit_require_any' => [],
                'need_min_match' => (int)($e['min_match'] ?? 0),
                'hit_forbid' => [],
            ],
        ]],
    ];
}, $selected);

$rejectedNTotal = count($rejected);

$rejectedSamples = array_slice(array_map(function ($e) {
    return [
        'id'    => (string)($e['id'] ?? ''),
        'final' => 'drop',
        'reasons' => [[
            'rule'     => 'rule_engine',
            'decision' => 'drop',
            'matched'  => true,
            'details'  => is_array($e['detail'] ?? null) ? $e['detail'] : [
                'hit_require_all' => [],
                'miss_require_all' => [],
                'hit_require_any' => [],
                'need_min_match' => (int)($e['min_match'] ?? 0),
                'hit_forbid' => [],
            ],
        ]],
    ];
}, $rejected), 0, $rejN);

// ✅ 统一 explain 日志（reads/highlights/cards 都允许；不再限制 cards）
$this->explain($ctx, $selectedExplains, $rejectedSamples, [
    'debug'        => $debug,
    'seed'         => $seed,
    'context_tags' => $this->pickContextKeys($userSet),
    'selected_n'   => count($selectedExplains),
    'rejected_n'   => $rejectedNTotal,
] + $opt);

return [$selected, $evals];
}

    public function selectConstrained(array $items, array $userSet, array $opt = []): array
{
    $ctx   = (string)($opt['ctx'] ?? 'cards');
    $seed  = (int)($opt['seed'] ?? 0);
    $debug = (bool)($opt['debug'] ?? false);

// --- DEBUG CONTEXT TAGS (local only) ---
if (
    app()->environment('local') &&
    (bool) \App\Support\RuntimeConfig::value('RE_CTX_TAGS', false) &&
    ($debug || (bool)\App\Support\RuntimeConfig::value('RE_TAGS', false))
) {
    Log::debug('[RE] context_tags', [
        'ctx'  => $ctx,
        'keys' => $this->pickContextKeys($userSet),
    ]);
}

    // 约束（从 SectionCardGenerator rules 来）
    $minCards      = (int)($opt['min_cards'] ?? 0);
    $targetCards   = (int)($opt['target_cards'] ?? count($items));
    $maxCards      = (int)($opt['max_cards'] ?? count($items));
    $fallbackTags  = is_array($opt['fallback_tags'] ?? null) ? $opt['fallback_tags'] : [];

    if ($minCards < 0) $minCards = 0;
    if ($targetCards < 0) $targetCards = 0;
    if ($maxCards < 0) $maxCards = 0;

    // axis/non-axis 约束（保持你现在逻辑）
    $axisMax    = array_key_exists('axis_max', $opt) ? (int)$opt['axis_max'] : max(0, min(2, $targetCards - 1));
    $nonAxisMin = array_key_exists('non_axis_min', $opt) ? (int)$opt['non_axis_min'] : (($targetCards >= 3) ? 1 : 0);

    $globalRules = is_array(($opt['global_rules'] ?? null)) ? $opt['global_rules'] : [];

    // tags_debug 抽样
$tagsDebugN = array_key_exists('tags_debug_n', $opt)
    ? (int)$opt['tags_debug_n']
    : (int) \App\Support\RuntimeConfig::value('RE_TAGS_DEBUG_N', 3);

// 非 cards ctx：直接关掉 tags_debug
if (!str_starts_with((string)($opt['ctx'] ?? ''), 'cards:')) {
    $tagsDebugN = 0;
}
    if ($tagsDebugN < 0) $tagsDebugN = 0;
    $tagsDebugI = 0;

    $evals = [];
$cands = [];

// ✅ explain 是否会输出（local + (debug || RE_EXPLAIN)）
$shouldExplain =
    (bool)($opt['capture_explain'] ?? false) ||
    (bool) \App\Support\RuntimeConfig::value('RE_EXPLAIN_PAYLOAD', false) ||
    (app()->environment('local') && ($debug || (bool) \App\Support\RuntimeConfig::value('RE_EXPLAIN', false)));

$rejectedTotal = 0;      // ✅ rejected 总数（用于 rejected_n）
$rejectedSamples = [];   // ✅ rejected 采样（用于 rejected 详情）
$rejN = array_key_exists('rejected_samples', $opt) ? (int)$opt['rejected_samples'] : 6;

foreach ($items as $it) {
        if (!is_array($it)) continue;
        $id = (string)($it['id'] ?? '');
        if ($id === '') continue;

        // --- DEBUG TAGS (local only, sample N) ---
        if (
            $tagsDebugI < $tagsDebugN &&
            app()->environment('local') &&
            ($debug || (bool)\App\Support\RuntimeConfig::value('RE_TAGS', false))
        ) {
            $section = null;
            if (isset($opt['section']) && is_string($opt['section'])) {
                $section = $opt['section'];
            } elseif (isset($it['section']) && is_string($it['section'])) {
                $section = $it['section'];
            } elseif (isset($opt['ctx']) && is_string($opt['ctx']) && str_contains($opt['ctx'], ':')) {
                $section = explode(':', $opt['ctx'], 2)[1] ?? null;
            }

            $evalSetDbg = \App\Services\Rules\TagBuilder::buildEvalTags($userSet, $it, $section, $id);
            $added = array_values(array_diff(array_keys($evalSetDbg), array_keys($userSet)));
            sort($added, SORT_STRING);

            Log::debug('[RE] tags_debug', [
                'ctx' => $opt['ctx'] ?? null,
                'section' => $section,
                'item_id' => $id,
                'added' => $added,
                'has_section' => ($section !== null && $section !== '') ? isset($evalSetDbg['section:' . $section]) : false,
                'has_item' => ($id !== '') ? isset($evalSetDbg['item:' . $id]) : false,
            ]);

            $tagsDebugI++;
        }

        // evaluate
        $base = [
            'id'       => $id,
            'tags'     => is_array($it['tags'] ?? null) ? $it['tags'] : [],
            'priority' => (int)($it['priority'] ?? 0),
            'rules'    => is_array($it['rules'] ?? null) ? $it['rules'] : [],
            // 兼容：如果 item 顶层有 require_all/any/forbid/min_match，也会在 evaluate() 里合并
            'require_all' => $it['require_all'] ?? null,
            'require_any' => $it['require_any'] ?? null,
            'forbid'      => $it['forbid'] ?? null,
            'min_match'   => $it['min_match'] ?? null,
        ];

        $ev = $this->evaluate($base, $userSet, [
            'seed'         => $seed,
            'ctx'          => $ctx,
            'section'      => $opt['section'] ?? ($it['section'] ?? null),
            'debug'        => $debug,
            'global_rules' => $globalRules,
        ] + $opt);

        $evals[] = $ev;

        if (!($ev['ok'] ?? false)) {
    $rejectedTotal++;

    // ✅ 只要 explain 会输出，就允许采样（不再只依赖 debug）
    if ($shouldExplain && count($rejectedSamples) < $rejN) {
        $rejectedSamples[] = [
            'id'        => (string)($ev['id'] ?? $id),
            'reason'    => (string)($ev['reason'] ?? ''),
            'detail'    => is_array($ev['detail'] ?? null) ? $ev['detail'] : [],
            'hit'       => (int)($ev['hit'] ?? 0),
            'priority'  => (int)($ev['priority'] ?? 0),
            'min_match' => (int)($ev['min_match'] ?? 0),
            'score'     => (int)($ev['score'] ?? 0),
        ];
    }
    continue;
}

        // axis match（把你 SectionCardGenerator::passesAxisMatch 搬过来）
if (!$this->passesAxisMatchForCard($it, $userSet, $opt['axis_info'] ?? [])) {
    $rejectedTotal++;

    // 可选：也采样 axis_mismatch（这样 RE_EXPLAIN 时能看到原因）
    if ($shouldExplain && count($rejectedSamples) < $rejN) {
        // 沿用 explain 最小 detail 结构，保持统一
        $detail = [
            'hit_require_all'  => [],
            'miss_require_all' => [],
            'hit_require_any'  => [],
            'need_min_match'   => 0,
            'hit_forbid'       => [],
        ];

        $rejectedSamples[] = [
            'id'        => (string)($id),
            'reason'    => 'axis_mismatch',
            'detail'    => $detail,
            'hit'       => (int)($ev['hit'] ?? 0),
            'priority'  => (int)($ev['priority'] ?? 0),
            'min_match' => (int)($ev['min_match'] ?? 0),
            'score'     => (int)($ev['score'] ?? 0),
        ];
    }

    continue;
}

        $isAxis = $this->isAxisCardId((string)$id);

        // 候选：保留原 item，挂上内部字段
        $it['_re'] = $ev;
        $it['_hit'] = (int)($ev['hit'] ?? 0);
        $it['_score'] = (int)($ev['score'] ?? 0);
        $it['_min'] = (int)($ev['min_match'] ?? 0);
        $it['_shuffle'] = (int)($ev['shuffle'] ?? 0);
        $it['_is_axis'] = $isAxis;

        $cands[] = $it;
    }

    // sort: score desc, shuffle asc, id asc
    usort($cands, function ($a, $b) {
        $sa = (int)($a['_score'] ?? 0);
        $sb = (int)($b['_score'] ?? 0);
        if ($sa !== $sb) return $sb <=> $sa;

        $sha = (int)($a['_shuffle'] ?? 0);
        $shb = (int)($b['_shuffle'] ?? 0);
        if ($sha !== $shb) return $sha <=> $shb;

        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });

    // ========== 下面是你 SectionCardGenerator 的“编排”逻辑 ==========
    $out  = [];
    $seen = [];

    $primary = array_slice($cands, 0, $targetCards);

    // 先保证 non-axis >= 1（target>=3）
    if ($nonAxisMin > 0) {
        foreach ($primary as $c) {
            if (count($out) >= $targetCards) break;
            if ((int)($c['_hit'] ?? 0) <= 0) continue;
            if ((bool)($c['_is_axis'] ?? false) === true) continue;

            $id = (string)($c['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;

            $seen[$id] = true;
            $out[] = $c;

            if ($this->countNonAxisById($out) >= $nonAxisMin) break;
        }
    }

    // 再填 hit>0
    foreach ($primary as $c) {
        if (count($out) >= $targetCards) break;
        if ((int)($c['_hit'] ?? 0) <= 0) continue;

        $id = (string)($c['id'] ?? '');
        if ($id === '' || isset($seen[$id])) continue;

        if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxisById($out) >= $axisMax) continue;

        $seen[$id] = true;
        $out[] = $c;
    }

    // 不足：补齐 non-axis
    if ($nonAxisMin > 0 && $this->countNonAxisById($out) < $nonAxisMin) {
        foreach ($cands as $c) {
            if (count($out) >= $targetCards) break;

            $id = (string)($c['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;
            if ((bool)($c['_is_axis'] ?? false) === true) continue;

            $seen[$id] = true;
            $out[] = $c;

            if ($this->countNonAxisById($out) >= $nonAxisMin) break;
        }
    }

    // 不足：补齐 axis
    if ($this->countAxisById($out) < $axisMax) {
        foreach ($cands as $c) {
            if (count($out) >= $targetCards) break;

            $id = (string)($c['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;
            if ((bool)($c['_is_axis'] ?? false) !== true) continue;

            $seen[$id] = true;
            $out[] = $c;

            if ($this->countAxisById($out) >= $axisMax) break;
        }
    }

    // fallback tags 补齐到 minCards
    if (count($out) < $minCards) {
        foreach ($cands as $c) {
            if (count($out) >= $targetCards) break;

            $id = (string)($c['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;

            if (!$this->hasAnyTagLocal($c['tags'] ?? [], $fallbackTags)) continue;
            if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxisById($out) >= $axisMax) continue;

            $seen[$id] = true;
            $out[] = $c;
        }
    }

    // 仍不足：随便补到 minCards
    if (count($out) < $minCards) {
        foreach ($cands as $c) {
            if (count($out) >= $minCards) break;

            $id = (string)($c['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;

            if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxisById($out) >= $axisMax) continue;

            $seen[$id] = true;
            $out[] = $c;
        }
    }

    $out = array_slice(array_values($out), 0, $maxCards);

// ✅ explain payload（固定格式：selected/rejected + reasons.details 最小字段）
$selectedExplains = array_map(function ($it) {
    $e = $it['_re'] ?? [];
    return [
        'id'    => (string)($e['id'] ?? ($it['id'] ?? '')),
        'final' => 'keep',
        'reasons' => [[
            'rule'     => 'rule_engine',
            'decision' => 'keep',
            'matched'  => true,
            'details'  => is_array($e['detail'] ?? null) ? $e['detail'] : [
                'hit_require_all'  => [],
                'miss_require_all' => [],
                'hit_require_any'  => [],
                'need_min_match'   => (int)($e['min_match'] ?? 0),
                'hit_forbid'       => [],
            ],
        ]],
    ];
}, $out);

// 你这里的 $rejectedSamples 当前是“旧结构采样”（含 reason/detail/hit...）
// 我们把它映射成统一结构：id/final/reasons/details
$rejectedExplains = array_map(function ($r) {
    $detail = is_array($r['detail'] ?? null) ? $r['detail'] : [];
    // 确保最小字段存在
    $detail = array_merge([
        'hit_require_all'  => [],
        'miss_require_all' => [],
        'hit_require_any'  => [],
        'need_min_match'   => (int)($r['min_match'] ?? 0),
        'hit_forbid'       => [],
    ], $detail);

    return [
        'id'    => (string)($r['id'] ?? ''),
        'final' => 'drop',
        'reasons' => [[
            'rule'     => 'rule_engine',
            'decision' => 'drop',
            'matched'  => true,
            'details'  => $detail,
        ]],
    ];
}, $rejectedSamples);

$this->explain($ctx, $selectedExplains, $rejectedExplains, [
    'debug'        => $debug,
    'seed'         => $seed,
    'context_tags' => $this->pickContextKeys($userSet),
    'selected_n'   => count($selectedExplains),
    'rejected_n'   => $rejectedTotal, // 这里用采样数即可（你没保存总 rejected）
] + $opt);

    // 清理内部字段（避免污染下游）
    foreach ($out as &$c) {
        unset($c['_hit'], $c['_score'], $c['_min'], $c['_shuffle'], $c['_is_axis'], $c['_re']);
    }
    unset($c);

    return [$out, $evals];
}

    /**
     * ✅ 统一选择器（cards/highlights/reads 三通）
     *
     * opt:
     * - target: 'cards' | 'highlights' | 'reads' (必须)
     * - rules:  [ ... rule objects ... ] (可选，默认 [])
     * - ctx/seed/debug/max_items/rejected_samples/tags_debug_n/global_rules...
     * - section: (可选) 默认从 ctx 推断
     */
    public function selectTargeted(array $items, array $contextSet, array $opt = []): array
    {
        $target = (string)($opt['target'] ?? '');
        if ($target === '') {
            // 为了避免 silent bug：强制要求 target
            throw new \RuntimeException('RE_SELECT_TARGETED_MISSING_TARGET');
        }

        $ctx   = (string)($opt['ctx'] ?? $target);
        $seed  = (int)($opt['seed'] ?? 0);
        $debug = (bool)($opt['debug'] ?? false);

        $max = array_key_exists('max_items', $opt) ? (int)$opt['max_items'] : count($items);
        if ($max < 0) $max = 0;

        $allRules = is_array($opt['rules'] ?? null) ? $opt['rules'] : [];

        // ---- Step B: 先筛出 target 命中的 rules，并按 priority desc 排序（稳定）----
        $rules = [];
        foreach ($allRules as $r) {
            if (!is_array($r)) continue;
            if ((string)($r['target'] ?? '') !== $target) continue;

            $rp = (int)($r['priority'] ?? 0);
            $rid = (string)($r['id'] ?? '');
            $r['_p'] = $rp;
            $r['_id'] = $rid;
            $rules[] = $r;
        }
        usort($rules, function ($a, $b) {
            $pa = (int)($a['_p'] ?? 0);
            $pb = (int)($b['_p'] ?? 0);
            if ($pa !== $pb) return $pb <=> $pa;
            return strcmp((string)($a['_id'] ?? ''), (string)($b['_id'] ?? ''));
        });

        // 可选：只打印一次 contextSet 的关键 keys（你已经实现过类似 context_tags）
        if (app()->environment('local') && ($debug || (bool)\App\Support\RuntimeConfig::value('RE_TAGS', false)) && (bool)\App\Support\RuntimeConfig::value('RE_CONTEXT_KEYS', true)) {
            $keys = array_keys(is_array($contextSet) ? $contextSet : []);
            $keep = $this->pickContextKeys($keys);
            Log::debug('[RE] context_tags', ['ctx' => $ctx, 'keys' => $keep]);
        }

        $kept = [];
        $evals = [];

        foreach ($items as $it) {
            if (!is_array($it)) continue;

            // ---- Step A: normalize item ----
            $id = (string)($it['id'] ?? '');
            if ($id === '') continue;

            $prio = (int)($it['priority'] ?? 0);
            $tags = is_array($it['tags'] ?? null) ? array_values($it['tags']) : [];
            $tags = array_values(array_filter($tags, fn($x) => is_string($x) && trim($x) !== ''));

            // section 推断
            $section = null;
            if (isset($opt['section']) && is_string($opt['section'])) $section = $opt['section'];
            elseif (isset($it['section']) && is_string($it['section'])) $section = $it['section'];
            elseif (isset($opt['ctx']) && is_string($opt['ctx']) && str_contains($opt['ctx'], ':')) {
                $section = explode(':', $opt['ctx'], 2)[1] ?? null;
            }

            // evalTags：context + item.tags + section/item
            $evalSet = \App\Services\Rules\TagBuilder::buildEvalTags($contextSet, $it, $section, $id);

            // hitCount：只算 item.tags 与 context 的交集（保持你现有规则）
            $hit = 0;
            foreach ($tags as $t) {
                if (isset($contextSet[$t])) $hit++;
            }

            // 先用 item 自身 rules 评估（你现有 evaluate 的能力）
            $baseEval = $this->evaluate([
                'id'       => $id,
                'tags'     => $tags,
                'priority' => $prio,
                'rules'    => is_array($it['rules'] ?? null) ? $it['rules'] : [],
                'require_all' => $it['require_all'] ?? null,
                'require_any' => $it['require_any'] ?? null,
                'forbid'      => $it['forbid'] ?? null,
                'min_match'   => $it['min_match'] ?? null,
                'section'     => $section,
            ], $contextSet, [
                'ctx'  => $ctx,
                'seed' => $seed,
                'debug'=> $debug,
                'global_rules' => is_array(($opt['global_rules'] ?? null)) ? $opt['global_rules'] : [],
            ] + $opt);

            $evals[] = $baseEval;

            // item 自身没过：直接淘汰
            if (!($baseEval['ok'] ?? false)) {
                continue;
            }

            // ---- Step C: 对该 item 执行 rules（priority 高→低，命中 drop 立刻淘汰）----
            $dropped = false;
            $bestKeepRulePrio = null;
            $bestKeepRuleId   = null;
            $bestHitRulePrio  = null;

            foreach ($rules as $r) {
                // Step B: match 命中才执行
                if (!$this->ruleMatchItem($r, $evalSet, $section, $id)) {
                    continue;
                }

                $rulePrio = (int)($r['priority'] ?? 0);
                $bestHitRulePrio = $bestHitRulePrio === null ? $rulePrio : max($bestHitRulePrio, $rulePrio);

                // 规则本身的约束（require/forbid/min_match）
                $rrules = $this->mergeRules([], [
                    'require_all' => $r['require_all'] ?? [],
                    'require_any' => $r['require_any'] ?? [],
                    'forbid'      => $r['forbid'] ?? [],
                    'min_match'   => (int)($r['min_match'] ?? 0),
                ]);

                [$ok, $reason, $detail] = $this->passesRules($rrules, $evalSet, $hit);
                if (!$ok) continue;

                $action = (string)($r['action'] ?? 'keep'); // 默认 keep

                if ($action === 'drop') {
                    $dropped = true;
                    if ($debug) {
                        Log::debug('[RE] rule_drop', [
                            'ctx' => $ctx,
                            'target' => $target,
                            'item' => $id,
                            'rule_id' => (string)($r['id'] ?? ''),
                            'rule_priority' => $rulePrio,
                            'reason' => $reason,
                            'detail' => $detail,
                        ]);
                    }
                    break; // ✅ v1：命中 drop 立刻淘汰
                }

                // keep：记录“明确保留”的最高优先级
                if ($bestKeepRulePrio === null || $rulePrio > $bestKeepRulePrio) {
                    $bestKeepRulePrio = $rulePrio;
                    $bestKeepRuleId   = (string)($r['id'] ?? '');
                }
            }

            if ($dropped) continue;

            // 默认 keep（未命中 drop）
            $it['_re'] = $baseEval;
            $it['_rule_keep_prio'] = $bestKeepRulePrio ?? -1;
            $it['_rule_keep_id']   = $bestKeepRuleId;
            $it['_rule_hit_prio']  = $bestHitRulePrio ?? -1;

            $kept[] = $it;
        }

        // ---- Step D: deterministic sort + take(max) ----
        usort($kept, function ($a, $b) {
            $pa = (int)($a['priority'] ?? 0);
            $pb = (int)($b['priority'] ?? 0);
            if ($pa !== $pb) return $pb <=> $pa;

            $ra = (int)($a['_rule_hit_prio'] ?? -1);
            $rb = (int)($b['_rule_hit_prio'] ?? -1);
            if ($ra !== $rb) return $rb <=> $ra;
 
            // 稳定：id asc
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        $selected = array_slice($kept, 0, $max);

        // 清理内部字段
        foreach ($selected as &$x) {
            unset($x['_re'], $x['_rule_keep_prio'], $x['_rule_keep_id'], $x['_rule_hit_prio']);
        }
        unset($x);

        return [$selected, $evals];
    }

    // ===== helpers for selectTargeted =====

    private function ruleMatchItem(array $rule, array $evalSet, ?string $section, string $itemId): bool
    {
        $m = is_array($rule['match'] ?? null) ? $rule['match'] : null;
        if (!$m) return true; // 没有 match 就表示全体适用

        // 多个字段同时出现 = AND
        // match.item: item.id 在里面
        if (isset($m['item']) && is_array($m['item']) && !empty($m['item'])) {
            $ok = in_array($itemId, $m['item'], true);
            if (!$ok) return false;
        }

        // match.section: item.section 在里面
        if (isset($m['section']) && is_array($m['section']) && !empty($m['section'])) {
            $sec = (string)($section ?? '');
            if ($sec === '') return false;
            if (!in_array($sec, $m['section'], true)) return false;
        }

        // match.type_code: 当前 type_code 在里面（依赖 contextTags 里有 type:XXX）
        if (isset($m['type_code']) && is_array($m['type_code']) && !empty($m['type_code'])) {
            $ok = false;
            foreach ($m['type_code'] as $tc) {
                if (!is_string($tc) || $tc === '') continue;
                if (isset($evalSet['type:' . $tc])) { $ok = true; break; }
            }
            if (!$ok) return false;
        }

        return true;
    }

    private function pickContextKeys(array $contextSetOrKeys): array
{
    // 兼容两种输入：
    // - 传的是 set：['type:ENTJ-A'=>true, ...]
    // - 传的是 keys：['type:ENTJ-A', 'axis:EI:E', ...]
    $keys = array_is_list($contextSetOrKeys)
        ? $contextSetOrKeys
        : array_keys($contextSetOrKeys);

    $wantedPrefixes = ['type:', 'axis:', 'role:', 'strategy:', 'borderline:'];
    $out = [];

    foreach ($keys as $k) {
        if (!is_string($k) || $k === '') continue;
        foreach ($wantedPrefixes as $p) {
            if (str_starts_with($k, $p)) {
                $out[] = $k;
                break;
            }
        }
    }

    sort($out, SORT_STRING);
    if (count($out) > 80) $out = array_slice($out, 0, 80);

    return $out;
}

// ===== helpers（加在 RuleEngine 类里，private 区域即可） =====
private function isAxisCardId(string $id): bool
{
    return str_contains($id, '_axis_');
}

private function countAxisById(array $items): int
{
    $n = 0;
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $id = (string)($it['id'] ?? '');
        if ($id !== '' && $this->isAxisCardId($id)) $n++;
    }
    return $n;
}

private function countNonAxisById(array $items): int
{
    $n = 0;
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $id = (string)($it['id'] ?? '');
        if ($id !== '' && !$this->isAxisCardId($id)) $n++;
    }
    return $n;
}

private function hasAnyTagLocal(array $tags, array $needles): bool
{
    if (!is_array($tags) || empty($tags)) return false;
    foreach ($needles as $t) {
        if (is_string($t) && $t !== '' && in_array($t, $tags, true)) return true;
    }
    return false;
}

// 搬你 SectionCardGenerator::passesAxisMatch
private function passesAxisMatchForCard(array $card, array $userSet, array $axisInfo): bool
{
    $match = is_array($card['match'] ?? null) ? $card['match'] : null;
    if (!$match) return true;

    $ax = is_array($match['axis'] ?? null) ? $match['axis'] : null;
    if (!$ax) return true;

    $dim = (string)($ax['dim'] ?? '');
    $side = (string)($ax['side'] ?? '');
    $minDelta = (int)($ax['min_delta'] ?? 0);

    if ($dim === '' || $side === '') return false;
    if (!isset($userSet["axis:{$dim}:{$side}"])) return false;

    $delta = 0;
    if (isset($axisInfo[$dim]) && is_array($axisInfo[$dim])) {
        $delta = (int)($axisInfo[$dim]['delta'] ?? 0);
    }
    if ($delta < $minDelta) return false;

    return true;
}

    public function explain(string $ctx, array $selectedExplains, array $rejectedSamples = [], array $opt = []): void
{
    // ✅ target 从 ctx 前缀推断：cards:traits -> cards / reads:overrides -> reads
    $target = $ctx;
    if (str_contains($ctx, ':')) {
        $target = explode(':', $ctx, 2)[0] ?: $ctx;
    }

    $contextTags = $opt['context_tags'] ?? null;
    if (!is_array($contextTags)) $contextTags = [];

    // ✅ payload capture：不要求 local；只要你显式开了 RE_EXPLAIN_PAYLOAD 或 opt.capture_explain，就收集
    $captureOn =
        (bool)($opt['capture_explain'] ?? false) ||
        (bool)($opt['captureExplain'] ?? false) ||
        (bool) \App\Support\RuntimeConfig::value('RE_EXPLAIN_PAYLOAD', false);

    // collector 可以是：
    // - opt['explain_collector'] = callable(string $ctx, array $payload)
    $collector = $opt['explain_collector'] ?? null;
    if (!is_callable($collector)) $collector = null;

    // ✅ log 开关：保持你原先逻辑（local + (debug || RE_EXPLAIN)）
    $debug = (bool)($opt['debug'] ?? false);
    $envOn = (bool) \App\Support\RuntimeConfig::value('RE_EXPLAIN', false);
    $logOn = app()->environment('local') && ($debug || $envOn);

    // 两个都没开就直接返回
    if (!$captureOn && !$logOn) return;

    // ✅ 统一 payload 结构（你验收点要的字段）
    $payload = [
        'target'       => (string)$target,
        'ctx'          => (string)$ctx,
        'context_tags' => array_values(array_filter($contextTags, fn($x)=>is_string($x) && $x!=='')),
        'selected'     => is_array($selectedExplains) ? array_values($selectedExplains) : [],
        'rejected'     => is_array($rejectedSamples) ? array_values($rejectedSamples) : [],
    ];

    // ✅ 1) 写入 collector（payload）
    if ($captureOn && $collector) {
        try {
            $collector($ctx, $payload);
        } catch (\Throwable $e) {
            // collector 失败不影响主流程
            Log::debug('[RE] explain_collector_failed', [
                'ctx' => $ctx,
                'err' => $e->getMessage(),
            ]);
        }
    }

    // ✅ 2) 打日志（log）
    if ($logOn) {
        Log::debug('[RE] explain', [
            '_explain' => [
                'target'       => (string)$target,
                'ctx'          => (string)$ctx,
                'seed'         => $opt['seed'] ?? null,
                'context_tags' => $payload['context_tags'],
                'selected_n'   => (int)($opt['selected_n'] ?? count($payload['selected'])),
                'rejected_n'   => (int)($opt['rejected_n'] ?? count($payload['rejected'])),
                'selected'     => $payload['selected'],
                'rejected'     => $payload['rejected'],
            ],
        ]);
    }
}

    private function mergeRules(array $global, array $item): array
    {
        $out = [];

        foreach (['require_all', 'require_any', 'forbid'] as $k) {
            $ga = is_array($global[$k] ?? null) ? $global[$k] : [];
            $ia = is_array($item[$k] ?? null) ? $item[$k] : [];
            $out[$k] = array_values(array_unique(array_filter(
                array_merge($ga, $ia),
                fn ($x) => is_string($x) && $x !== ''
            )));
        }

        $out['min_match'] = max(
            (int)($global['min_match'] ?? 0),
            (int)($item['min_match'] ?? 0)
        );

        return $out;
    }

    /**
 * @return array [ok(bool), reason(string), detail(array)]
 */
private function passesRules(array $rules, array $userSet, int $hit): array
{
    $reqAll   = is_array($rules['require_all'] ?? null) ? $rules['require_all'] : [];
    $reqAny   = is_array($rules['require_any'] ?? null) ? $rules['require_any'] : [];
    $forbid   = is_array($rules['forbid'] ?? null) ? $rules['forbid'] : [];
    $minMatch = (int)($rules['min_match'] ?? 0);

    // ✅ explain 最小字段（所有分支都返回这些 key）
    $detail = [
        'hit_require_all'  => [],
        'miss_require_all' => [],
        'hit_require_any'  => [],
        'need_min_match'   => $minMatch,
        'hit_forbid'       => [],
    ];

    // forbid：命中即排除
    $forbidHit = [];
    foreach ($forbid as $t) {
        if (is_string($t) && $t !== '' && isset($userSet[$t])) $forbidHit[] = $t;
    }
    if (!empty($forbidHit)) {
        $detail['hit_forbid'] = array_values($forbidHit);
        return [false, 'forbid_hit', $detail];
    }

    // require_all：全部命中
    $missing = [];
    $hitAll  = [];
    foreach ($reqAll as $t) {
        if (!is_string($t) || $t === '') continue;
        if (isset($userSet[$t])) $hitAll[] = $t;
        else $missing[] = $t;
    }
    $detail['hit_require_all']  = array_values($hitAll);
    $detail['miss_require_all'] = array_values($missing);

    if (!empty($missing)) {
        return [false, 'require_all_missing', $detail];
    }

    // require_any：至少命中一个（如果 reqAny 非空）
    if (!empty($reqAny)) {
        $hitAny = [];
        foreach ($reqAny as $t) {
            if (!is_string($t) || $t === '') continue;
            if (isset($userSet[$t])) $hitAny[] = $t;
        }
        $detail['hit_require_any'] = array_values($hitAny);

        if (count($hitAny) < 1) {
            return [false, 'require_any_miss', $detail];
        }
    }

    // min_match：hitCount 阈值
    if ($hit < $minMatch) {
        return [false, 'min_match_fail', $detail];
    }

    return [true, 'ok', $detail];
}

    private function stableShuffleKey(int $seed, string $id): int
    {
        $u = sprintf('%u', crc32($seed . '|' . $id));
        return (int)((int)$u & 0x7fffffff);
    }

    private function normalizeRuleToTagConstraints(array $rule): array
{
    $reqAll = is_array($rule['require_all'] ?? null) ? $rule['require_all'] : [];
    $reqAny = is_array($rule['require_any'] ?? null) ? $rule['require_any'] : [];
    $forbid = is_array($rule['forbid'] ?? null) ? $rule['forbid'] : [];

    $match = $rule['match'] ?? null;
    if (is_array($match)) {
        if (isset($match['section']) && is_array($match['section'])) {
            foreach ($match['section'] as $s) {
                if (is_string($s) && trim($s) !== '') $reqAny[] = "section:" . trim($s);
            }
        }
        if (isset($match['type_code']) && is_array($match['type_code'])) {
            foreach ($match['type_code'] as $t) {
                if (is_string($t) && trim($t) !== '') $reqAny[] = "type:" . trim($t);
            }
        }
        if (isset($match['item']) && is_array($match['item'])) {
            foreach ($match['item'] as $id) {
                if (is_string($id) && trim($id) !== '') $reqAny[] = "item:" . trim($id);
            }
        }
    }

    $rule['require_all'] = array_values($reqAll);
    $rule['require_any'] = array_values($reqAny);
    $rule['forbid']      = array_values($forbid);

    return $rule;
}
}