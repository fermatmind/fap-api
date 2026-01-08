<?php

declare(strict_types=1);

namespace App\Services\Overrides;
use App\Services\Report\ReportContentNormalizer;

use Illuminate\Support\Facades\Log;

class ReportOverridesApplier
{
    /** @var array<string,mixed> */
private array $lastExplain = [
    'highlights' => null,
    'reads'      => null,
    'cards'      => [],
];

public function resetExplain(): void
{
    $this->lastExplain = [
        'highlights' => null,
        'reads'      => null,
        'cards'      => [],
    ];
}

public function getExplain(): array
{
    return $this->lastExplain;
}

    /**
     * Unified entry: highlights
     */
    public function applyHighlights(string $contentPackageDir, string $typeCode, array $highlights, array $ctx = []): array
{
    $doc = $this->loadOverridesDoc($contentPackageDir, $ctx);
    $rules = $this->filterRulesByTarget($doc, 'highlights');

    // ✅ 最小验证：让 highlights 也能看到 RE 的三类日志（context_tags/tags_debug/explain）
    $this->logReCtx('highlights:overrides', $ctx);
    $this->logReExplain('highlights:overrides', $rules, $ctx);

    $context = [
    'target' => 'highlights',
    'type_code' => $typeCode,
    'content_package_dir' => $contentPackageDir,
    'section_key' => null,
    'ctx' => $ctx,

    // ✅ 关键：给 applyRulesToList 的 payloadExplain 用
    'explain_ctx' => 'highlights:overrides',
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

        // ✅ 最小验证：cards 也输出 RE 的三类日志（context_tags/tags_debug/explain）
$this->logReCtx("cards:{$sectionKey}:overrides", $ctx);
$this->logReExplain("cards:{$sectionKey}:overrides", $rules, $ctx);

$context = [
    'target' => 'cards',
    'type_code' => $typeCode,
    'content_package_dir' => $contentPackageDir,
    'section_key' => $sectionKey,
    'ctx' => $ctx,

    // ✅ 关键：给 applyRulesToList 的 payloadExplain 用
    'explain_ctx' => "cards:{$sectionKey}:overrides",
];

// ✅ 先跑 overrides（会生成 $this->lastExplain['cards'][$sectionKey]）
$out = $this->applyRulesToList($cards, $rules, $context);

// ✅ 关键：把 overrides 的 explain payload 写回 ReportComposer 的 collector => report._explain.cards.<section>
$capture = (bool)($ctx['capture_explain'] ?? false);
$collector = $ctx['explain_collector'] ?? ($GLOBALS['__re_explain_collector__'] ?? null);

if ($capture && is_callable($collector) && is_array($this->lastExplain['cards'][$sectionKey] ?? null)) {
    $payload = $this->lastExplain['cards'][$sectionKey];

    $payload['ctx'] = "cards:{$sectionKey}:overrides";
    $payload['target'] = 'cards';
    $payload['section_key'] = $sectionKey;

    if (!isset($payload['context_tags']) || !is_array($payload['context_tags'])) $payload['context_tags'] = [];
    if (!isset($payload['selected']) || !is_array($payload['selected'])) $payload['selected'] = [];
    if (!isset($payload['rejected']) || !is_array($payload['rejected'])) $payload['rejected'] = [];

    // 你 ReportComposer collector 规则：ctx 以 cards: 开头会落到 _explain.cards.<section>
    $collector("cards:{$sectionKey}:overrides", $payload);

    // 同步写回 lastExplain
    $this->lastExplain['cards'][$sectionKey] = $payload;
}

// ✅ final normalize for cards: tips must be array[string] and >= 1
$out = array_map(function ($it) {
    if (!is_array($it)) $it = [];

    if (!isset($it['tips']) || !is_array($it['tips'])) $it['tips'] = [];
    $it['tips'] = array_values(array_filter($it['tips'], fn($x) => is_string($x) && trim($x) !== ''));

    return $it;
}, $out);

return $out;
    }

    /**
     * Unified entry: recommended reads
     */
public function applyReads(string $contentPackageDir, string $typeCode, array $reads, array $ctx = []): array
{
    $doc = $this->loadOverridesDoc($contentPackageDir, $ctx);
    $rules = $this->filterRulesByTarget($doc, 'reads');

    Log::debug('[reads] ovr_doc', [
        'doc_is_null' => !is_array($doc),
        'rules_n' => is_array($doc['rules'] ?? null) ? count($doc['rules']) : -1,
        'src_chain' => $doc['__src_chain'] ?? null,
        'sample_rule_ids' => array_slice(array_map(fn($r)=>$r['id']??null, $doc['rules'] ?? []), 0, 12),
        'reads_rules_n' => is_array($rules) ? count($rules) : -1,
        'reads_rule_ids' => array_slice(array_map(fn($r)=>$r['id']??null, $rules ?? []), 0, 12),
    ]);

    // ✅ 最小验证：让 reads 也能看到 RE 的三类日志（context_tags/tags_debug/explain）
    $this->logReCtx('reads:overrides', $ctx);
    $this->logReExplain('reads:overrides', $rules, $ctx);

    $context = [
    'target' => 'reads',
    'type_code' => $typeCode,
    'content_package_dir' => $contentPackageDir,
    'section_key' => null,
    'ctx' => $ctx,

    // ✅ 关键：给 applyRulesToList 的 payloadExplain 用
    'explain_ctx' => 'reads:overrides',
];

    // ✅ 先跑 overrides（这里会生成 $this->lastExplain['reads']）
    $out = $this->applyRulesToList($reads, $rules, $context);

    // ✅ 关键：把 overrides 的 explain payload 写回 ReportComposer 的 collector => report._explain.reads
    // ReportComposer 的 collector 规则：ctx=reads 或 reads:* 都会落到 _explain.reads
    $capture = (bool)($ctx['capture_explain'] ?? false);
    $collector = $ctx['explain_collector'] ?? ($GLOBALS['__re_explain_collector__'] ?? null);

    if ($capture && is_callable($collector) && is_array($this->lastExplain['reads'] ?? null)) {
        $payload = $this->lastExplain['reads'];

        // 补齐 A 验收要求的 ctx 字段（你现在 payload 里还没有 ctx）
        $payload['ctx'] = 'reads:overrides';
        $payload['target'] = 'reads';

        // 兜底确保 key 存在
        if (!isset($payload['context_tags']) || !is_array($payload['context_tags'])) $payload['context_tags'] = [];
        if (!isset($payload['selected']) || !is_array($payload['selected'])) $payload['selected'] = [];
        if (!isset($payload['rejected']) || !is_array($payload['rejected'])) $payload['rejected'] = [];

        $collector('reads:overrides', $payload);

        $this->lastExplain['reads'] = $payload;
    }

    return $out;
}

    // ======================================================================
    // Engine internals
    // ======================================================================

    /**
 * Load unified overrides doc.
 *
 * ✅ Step3: 不再自己扫文件/拼路径；只认调用方（ReportComposer）按 manifest 展开的结果
 * - 支持 ctx['report_overrides_doc']（推荐）
 * - 兼容 ctx['report_overrides_docs']（数组：多个 doc）
 */
private function loadOverridesDoc(string $contentPackageDir, array $ctx = []): ?array
{
    // 1) 最推荐：Composer 已经合并好并注入 __src 到每条 rule
    if (isset($ctx['report_overrides_doc']) && is_array($ctx['report_overrides_doc'])) {
        return $ctx['report_overrides_doc'];
    }

    // 2) 兼容：Composer 也可能传多个 doc（不合并）
    if (isset($ctx['report_overrides_docs']) && is_array($ctx['report_overrides_docs'])) {
        $docs = $ctx['report_overrides_docs'];

        $base = [
            'schema' => 'fap.report.overrides.v1',
            'rules' => [],
            '__src_chain' => [],
        ];

        foreach ($docs as $d) {
            if (!is_array($d)) continue;

            $rules = null;
            if (is_array($d['rules'] ?? null)) $rules = $d['rules'];
            elseif (is_array($d['overrides'] ?? null)) $rules = $d['overrides']; // 兼容 key

            if (is_array($rules)) {
                foreach ($rules as $r) {
                    if (is_array($r)) $base['rules'][] = $r;
                }
            }

            if (is_array($d['__src'] ?? null)) $base['__src_chain'][] = $d['__src'];
        }

        return $base;
    }

    // ✅ Step3：没有 ctx 注入就视为“无 overrides”
    return null;
}

    /**
     * Select rules matching a target: highlights/cards/reads
     */
    private function filterRulesByTarget(?array $doc, string $target): array
    {
        if (!is_array($doc)) return [];

        $rules = $doc['rules'] ?? ($doc['overrides'] ?? []);
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

    $wantExplain = $this->shouldExplain((array)($context['ctx'] ?? []));

    // context tags（ctx.tags）
    $ctxTags = $context['ctx']['tags'] ?? [];
    if (!is_array($ctxTags)) $ctxTags = [];
    $ctxTags = array_values(array_filter($ctxTags, fn($x)=>is_string($x) && $x !== ''));

    // origin ids（用于最终 selected/rejected 对比）
    $originIds = $this->idsOf($list);

    // ✅ ever ids：记录“过程中出现过的所有 id”（含 append 又 remove 的）
$everIds = $originIds;
$everMap = array_fill_keys($originIds, true);

    // per-item reasons trace
    $trace = []; // id => reasons[]
    $pushReason = function(string $id, array $reason) use (&$trace) {
        if ($id === '') return;
        if (!isset($trace[$id])) $trace[$id] = [];
        $trace[$id][] = $reason;
    };

    $appliedIds = [];

    // 1) 分离 filter / normal
    $filterRules = [];
    $normalRules = [];

    foreach ($rules as $r) {
        if (!is_array($r)) continue;

        $mode = (string)($r['mode'] ?? 'patch');
        if (!isset($r['mode']) && isset($r['action'])) $mode = 'filter';

        if ($mode === 'filter') {
    Log::warning('[OVR] filter_rule_ignored (use RuleEngine)', [
        'id' => $r['id'] ?? null,
        'target' => $context['target'] ?? null,
        'section_key' => $context['section_key'] ?? null,
    ]);
    continue;
}
$normalRules[] = $r;
    }

    // 2) 先跑非 filter（保持原顺序语义）
    foreach ($normalRules as $rule) {
        if (!is_array($rule)) continue;
        if (!$this->ruleMatchesContext($rule, $context)) continue;

        $mode = (string)($rule['mode'] ?? 'patch');
$selector = $rule['selector'] ?? null;

// 兼容 match.item -> selector
if ($selector === null && isset($rule['match']['item']) && is_array($rule['match']['item'])) {
    $selector = $this->selectorFromMatchItem($rule['match']['item']);
}

// ✅ 支持 match.id / match.ids（更符合 overrides schema 写法）
if ($selector === null && isset($rule['match']) && is_array($rule['match'])) {
    $m = $rule['match'];

    $ids = null;
    if (isset($m['id'])) $ids = $m['id'];
    if (isset($m['ids'])) $ids = $m['ids'];

    if (is_string($ids)) $ids = [$ids];
    if (is_array($ids)) {
        $ids = array_values(array_filter($ids, fn($x)=>is_string($x) && $x !== ''));
        if (!empty($ids)) $selector = ['ids' => $ids];
    }
}

$before = $list;
$beforeIds = $this->idsOf($before);

// ✅ Step2：append/prepend/upsert 在 selector=null 时，不允许 match-all
// 否则 selectIndexes(null) 会返回全量 indexes，导致你看到的“test_append_highlight_dummy matched:true 覆盖所有已有 item”
if ($selector === null && in_array($mode, ['append', 'prepend', 'upsert'], true)) {
    $matches = [];
} else {
    $matches = $this->selectIndexes($list, $selector);
}

        // appliedIds（保持你原逻辑）
        if (!empty($matches)) {
            foreach ($matches as $idx) {
                $it = $list[$idx] ?? null;
                if (is_array($it)) {
                    $id = $it['id'] ?? null;
                    if (is_string($id) && $id !== '') $appliedIds[] = $id;
                }
            }
        } else {
            if (in_array($mode, ['append','prepend'], true) || $mode === 'upsert') {
                $newItems = $this->ruleItems($rule, $context) ?? [];
                foreach ($newItems as $x) {
                    if (!is_array($x)) continue;
                    $id = $x['id'] ?? null;
                    if (is_string($id) && $id !== '') $appliedIds[] = $id;
                }
            }
        }

        if ($wantExplain && !empty($matches)) {
    $rid = (string)($rule['id'] ?? 'rule');
    $details = $this->explainDetailsForRuleOnTags($rule, $ctxTags);

    // ✅ remove/replace 这种属于“让原 item 消失”的动作，应记录 drop
    $decision = in_array($mode, ['remove','replace'], true) ? 'drop' : 'keep';

    foreach ($matches as $idx) {
        $it = $before[$idx] ?? null;
        $id = is_array($it) ? (string)($it['id'] ?? '') : '';
        if ($id === '') continue;

        $pushReason($id, [
            'rule' => $rid,
            'decision' => $decision,
            'matched' => true,
            'details' => $details,
        ]);
    }
}

        $beforeCount = count($list);
        $list = $this->applyRuleToList($list, $matches, $rule, $context);

        // append/prepend 新增项 reasons
        if ($wantExplain) {
            $rid = (string)($rule['id'] ?? 'rule');
            $afterIds = $this->idsOf($list);
            $beforeMap = array_fill_keys($beforeIds, true);

            foreach ($afterIds as $aid) {
    if (!isset($beforeMap[$aid])) {

        // ✅ 记录到 everIds：即便后面被 remove，也要能在 explain.rejected 里看到
        if (!isset($everMap[$aid])) {
            $everMap[$aid] = true;
            $everIds[] = $aid;
        }

        $pushReason($aid, [
            'rule' => $rid,
            'decision' => 'keep',
            'matched' => true,
            'details' => $this->explainDetailsForRuleOnTags($rule, $ctxTags),
        ]);
    }
}
        }

        // log
        $src = (isset($rule['__src']) && is_array($rule['__src'])) ? $rule['__src'] : null;

// ✅ matched_by：用于验收“为什么命中”
$matchedBy = [];
if ($selector !== null) $matchedBy[] = 'selector';
if (isset($rule['match']) && is_array($rule['match']) && !empty($rule['match'])) $matchedBy[] = 'match';
if (isset($rule['when']) && is_array($rule['when']) && !empty($rule['when'])) $matchedBy[] = 'when';
if (empty($matchedBy)) $matchedBy[] = 'default';

// ✅ affected_count：按 mode 计算影响条数
$affectedCount = 0;
if (!empty($matches)) {
    $affectedCount = count($matches); // patch/remove/replace/upsert(matched)
} else {
    if (in_array($mode, ['append','prepend','upsert'], true)) {
        $newItems = $this->ruleItems($rule, $context) ?? [];
        $affectedCount = is_array($newItems) ? count($newItems) : 0; // append/prepend/upsert(insert)
    }
}

if ($debugPerRule || (bool) env('FAP_OVR_TRACE', false)) {
    Log::info('[OVR] rule_applied', [
        'id' => $rule['id'] ?? null,
        'target' => $context['target'] ?? null,
        'section_key' => $context['section_key'] ?? null,
        'type_code' => $context['type_code'] ?? null,

        'mode' => $mode,
        'matched_by' => $matchedBy,
        'matched_n' => is_array($matches) ? count($matches) : 0,
        'affected_count' => $affectedCount,

        'before' => $beforeCount,
        'after' => count($list),

        'src_idx'     => $src['idx'] ?? null,
        'src_pack_id' => $src['pack_id'] ?? null,
        'src_version' => $src['version'] ?? null,
        'src_file'    => $src['file'] ?? null,
        'src_rel'     => $src['rel'] ?? null,
    ]);
}
    }

    // applied log（保留）
    $appliedIds = array_values(array_unique(array_filter($appliedIds, fn($x)=>is_string($x) && $x !== '')));
    if (!empty($appliedIds) && $wantExplain) {
        Log::info('[OVR] applied', [
            'target' => (string)($context['target'] ?? ''),
            'section_key' => $context['section_key'] ?? null,
            'type_code' => (string)($context['type_code'] ?? ''),
            'applied_ids_n' => count($appliedIds),
            'applied_ids' => $appliedIds,
            'final_n' => count($list),
            'final_first' => is_array($list[0] ?? null) ? ($list[0]['id'] ?? null) : null,
        ]);
    }

    // 4) 产出 explain（selected/rejected + reasons）
    if ($wantExplain) {
        $finalIds = $this->idsOf($list);
        $finalMap = array_fill_keys($finalIds, true);

        $selected = [];
        foreach ($finalIds as $id) {
            $selected[] = [
                'id' => $id,
                'final' => 'keep',
                'reasons' => $trace[$id] ?? [],
            ];
        }

        $rejected = [];
foreach ($everIds as $id) {
    if (!isset($finalMap[$id])) {
        $rejected[] = [
            'id' => $id,
            'final' => 'drop',
            'reasons' => $trace[$id] ?? [],
        ];
    }
}

        $maxItems = (int) env('RE_EXPLAIN_ITEMS_MAX', 60);
        if ($maxItems < 0) $maxItems = 0;

        $payloadExplain = [
            'target'       => (string)($context['target'] ?? ''),
            'ctx'          => (isset($context['explain_ctx']) && is_string($context['explain_ctx']) && $context['explain_ctx'] !== '')
                ? $context['explain_ctx']
                : null,
            'section_key'  => $context['section_key'] ?? null,
            'context_tags' => $ctxTags,
            'selected_n'   => count($selected),
            'rejected_n'   => count($rejected),
            'selected'     => ($maxItems > 0) ? array_slice($selected, 0, $maxItems) : [],
            'rejected'     => ($maxItems > 0) ? array_slice($rejected, 0, $maxItems) : [],
        ];

        $t = (string)($context['target'] ?? '');
        if ($t === 'cards') {
            $sk = (string)($context['section_key'] ?? '');
            if ($sk === '') $sk = '_';
            $this->lastExplain['cards'][$sk] = $payloadExplain;
        } elseif ($t === 'reads') {
            $this->lastExplain['reads'] = $payloadExplain;
        } elseif ($t === 'highlights') {
            $this->lastExplain['highlights'] = $payloadExplain;
        }
    }

    return array_values($list);
}

private function applyFilterRulesToList(
    array $list,
    array $filterRules,
    array $context,
    array $ctxTags,
    bool $wantExplain,
    callable $pushReason
): array {
    // sort by priority desc
    usort($filterRules, function($a, $b) {
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        return $pb <=> $pa;
    });

    $target = (string)($context['target'] ?? '');

    // per-id state
    $keepHit = [];
    $dropHit = [];
    $defaultDropCandidate = []; // id => ['rule'=>..., 'details'=>...]

    foreach ($filterRules as $rule) {
        if (!is_array($rule)) continue;
        if (!$this->ruleMatchesContext($rule, $context)) continue;

        $rid = (string)($rule['id'] ?? 'rule');

        // selector
        $selector = $rule['selector'] ?? null;
        if ($selector === null && isset($rule['match']['item']) && is_array($rule['match']['item'])) {
            $selector = $this->selectorFromMatchItem($rule['match']['item']);
        }

        $action = strtolower((string)($rule['effect']['action'] ?? ($rule['action'] ?? 'drop')));

        // ✅ filter 命中集合：优先 selector；否则按 item.tags/ctx.tags 的 tag 条件；再否则全量
        $matchesIdx = [];
        if ($selector !== null) {
            $matchesIdx = $this->selectIndexes($list, $selector);
        } elseif ($this->hasTagConditions($rule)) {
            foreach ($list as $i => $it) {
                if (!is_array($it)) continue;
                $id = (string)($it['id'] ?? '');
                if ($id === '') continue;

                $itemTags = $this->getItemTags($it);

                // reads：用 item.tags；cards/highlights：若 item.tags 为空则回退 ctxTags
                $itemTags = $this->getItemTags($it);

if ($target === 'reads') {
    // reads: per-item tags 为主（空才回退 ctx）
    $tagset = !empty($itemTags) ? $itemTags : $ctxTags;
} else {
    // cards/highlights: 合并 ctx + item
    $tagset = array_values(array_unique(array_merge($ctxTags, $itemTags)));
}

                if ($this->tagConditionsMatch($rule, $tagset)) {
                    $matchesIdx[] = (int)$i;
                }
            }
        } else {
            $matchesIdx = $this->selectIndexes($list, null); // all
        }

        $matchedMap = array_fill_keys($matchesIdx, true);

        // ✅ details 要用“实际用于匹配的 tagset”
        foreach ($list as $i => $it) {
            if (!is_array($it)) continue;
            $id = (string)($it['id'] ?? '');
            if ($id === '') continue;

            $itemTags = $this->getItemTags($it);
            $itemTags = $this->getItemTags($it);

if ($target === 'reads') {
    // reads: per-item tags 为主（空才回退 ctx）
    $tagset = !empty($itemTags) ? $itemTags : $ctxTags;
} else {
    // cards/highlights: 合并 ctx + item
    $tagset = array_values(array_unique(array_merge($ctxTags, $itemTags)));
}

            $isMatched = isset($matchedMap[$i]);
            $details = $this->explainDetailsForRuleOnTags($rule, $tagset);

            if ($action === 'keep') {
                if ($isMatched) {
                    $keepHit[$id] = true;
                    if ($wantExplain) {
                        $pushReason($id, [
                            'rule' => $rid,
                            'decision' => 'keep',
                            'matched' => true,
                            'details' => $details,
                        ]);
                    }
                } else {
                    // keep-only：不命中 => drop（matched=false 要体现在 explain）
                    if ($wantExplain) {
                        $pushReason($id, [
                            'rule' => $rid,
                            'decision' => 'drop',
                            'matched' => false,
                            'details' => $details,
                        ]);
                    }
                }
                continue;
            }

            if ($action === 'drop' || $action === 'remove') {
                if ($isMatched) {
                    if ($this->isDefaultDropRule($rule)) {
                        // ✅ default drop 先暂存，最后“只在没 keep 命中时”才真正生效&写 explain
                        if (!isset($defaultDropCandidate[$id])) {
                            $defaultDropCandidate[$id] = [
                                'rule' => $rid,
                                'details' => $details,
                            ];
                        }
                    } else {
                        $dropHit[$id] = true;
                        if ($wantExplain) {
                            $pushReason($id, [
                                'rule' => $rid,
                                'decision' => 'drop',
                                'matched' => true,
                                'details' => $details,
                            ]);
                        }
                    }
                }
                continue;
            }
        }
    }

    // ✅ 最终决策：drop > keep > default drop（default 只在没 keep 时出现）
    $out = [];
    foreach ($list as $it) {
        if (!is_array($it)) continue;
        $id = (string)($it['id'] ?? '');
        if ($id === '') continue;

        if (!empty($dropHit[$id])) {
            continue; // drop
        }

        if (!empty($keepHit[$id])) {
            $out[] = $it; // keep
            continue;
        }

        // no keep, no explicit drop => default drop?
        if (isset($defaultDropCandidate[$id])) {
            if ($wantExplain) {
                $pushReason($id, [
                    'rule' => $defaultDropCandidate[$id]['rule'],
                    'decision' => 'drop',
                    'matched' => true,
                    'details' => $defaultDropCandidate[$id]['details'],
                ]);
            }
            continue; // drop
        }

        // 没 default drop，就放过（更安全）
        $out[] = $it;
    }

    return $out;
}

private function hasTagConditions(array $rule): bool
{
    $when = (isset($rule['when']) && is_array($rule['when'])) ? $rule['when'] : [];

    foreach (['require_any', 'require_all', 'forbid'] as $k) {
        if (isset($rule[$k]) && is_array($rule[$k]) && $rule[$k] !== []) return true;
        if (isset($when[$k]) && is_array($when[$k]) && $when[$k] !== []) return true;
    }
    return false;
}

private function tagConditionsMatch(array $rule, array $tags): bool
{
    // 规范化 tags
    if (!is_array($tags)) $tags = [];
    $tags = array_values(array_filter($tags, fn($x)=>is_string($x) && $x !== ''));

    // ✅ 统一 gate 来源：优先 when，其次顶层
    $when = (isset($rule['when']) && is_array($rule['when'])) ? $rule['when'] : [];

    $forbid = is_array($when['forbid'] ?? null) ? $when['forbid'] : (is_array($rule['forbid'] ?? null) ? $rule['forbid'] : []);
    $reqAny = is_array($when['require_any'] ?? null) ? $when['require_any'] : (is_array($rule['require_any'] ?? null) ? $rule['require_any'] : []);
    $reqAll = is_array($when['require_all'] ?? null) ? $when['require_all'] : (is_array($rule['require_all'] ?? null) ? $rule['require_all'] : []);

    $min = (int)($rule['min_match'] ?? ($when['min_match'] ?? 0));

    // 1) forbid：命中任意一个 => 不匹配
    foreach ($forbid as $t) {
        if (is_string($t) && $t !== '' && in_array($t, $tags, true)) {
            return false;
        }
    }

    // 2) require_all：必须全部命中
    foreach ($reqAll as $t) {
        if (!is_string($t) || $t === '') continue;
        if (!in_array($t, $tags, true)) return false;
    }

    // 3) require_any：至少命中 min（默认 1）
    if (!empty($reqAny)) {
        if ($min < 1) $min = 1;

        $hit = 0;
        foreach ($reqAny as $t) {
            if (is_string($t) && $t !== '' && in_array($t, $tags, true)) $hit++;
        }
        if ($hit < $min) return false;
    }

    // 没有任何 tag 条件时，hasTagConditions() 已经拦住了，一般不会走到这里
    return true;
}

private function getItemTags(array $it): array
{
    $tags = $it['tags'] ?? [];
    if (!is_array($tags)) return [];
    return array_values(array_filter($tags, fn($x)=>is_string($x) && $x !== ''));
}

private function isDefaultDropRule(array $rule): bool
{
    $id = (string)($rule['id'] ?? '');
    if ($id !== '' && str_contains($id, 'drop_all_default')) return true;

    $action = strtolower((string)($rule['effect']['action'] ?? ($rule['action'] ?? '')));
    if ($action !== 'drop' && $action !== 'remove') return false;

    $hasSelector = isset($rule['selector']) && is_array($rule['selector']);
    $hasMatchItem = isset($rule['match']['item']) && is_array($rule['match']['item']);
    $hasTagCond = $this->hasTagConditions($rule);

    $match = $rule['match'] ?? null;
    $hasOtherMatch = is_array($match) && array_diff(array_keys($match), ['item']) !== [];

    // ✅ 没 selector、没 match、没 tag 条件 => 才认为是 default drop
    return !$hasSelector && !$hasMatchItem && !$hasTagCond && !$hasOtherMatch;
}

// ===== helpers for explain =====
private function idsOf(array $list): array
{
    $out = [];
    foreach ($list as $it) {
        if (!is_array($it)) continue;
        $id = $it['id'] ?? null;
        if (is_string($id) && $id !== '') $out[] = $id;
    }
    return array_values(array_unique($out));
}

private function explainDetailsForRuleOnTags(array $rule, array $tags): array
{
    // ✅ 统一 gate 来源：优先 when，其次顶层
    $when = (isset($rule['when']) && is_array($rule['when'])) ? $rule['when'] : [];

    $forbid = is_array($when['forbid'] ?? null) ? $when['forbid'] : (is_array($rule['forbid'] ?? null) ? $rule['forbid'] : []);
    $reqAny = is_array($when['require_any'] ?? null) ? $when['require_any'] : (is_array($rule['require_any'] ?? null) ? $rule['require_any'] : []);
    $reqAll = is_array($when['require_all'] ?? null) ? $when['require_all'] : (is_array($rule['require_all'] ?? null) ? $rule['require_all'] : []);

    $min = $rule['min_match'] ?? ($when['min_match'] ?? 0);
    $min = (int)$min;

    // ✅ details 最小字段
    $detail = [
        'hit_require_all'  => [],
        'miss_require_all' => [],
        'hit_require_any'  => [],
        'need_min_match'   => $min,
        'hit_forbid'       => [],
    ];

    // forbid hits
    foreach ($forbid as $t) {
        if (is_string($t) && $t !== '' && in_array($t, $tags, true)) {
            $detail['hit_forbid'][] = $t;
        }
    }

    // require_any hits
    foreach ($reqAny as $t) {
        if (is_string($t) && $t !== '' && in_array($t, $tags, true)) {
            $detail['hit_require_any'][] = $t;
        }
    }
    // require_any 默认 min_match=1
    if (!empty($reqAny) && $detail['need_min_match'] < 1) $detail['need_min_match'] = 1;

    // require_all hits/miss
    foreach ($reqAll as $t) {
        if (!is_string($t) || $t === '') continue;
        if (in_array($t, $tags, true)) $detail['hit_require_all'][] = $t;
        else $detail['miss_require_all'][] = $t;
    }

    // 去重
    foreach (['hit_require_all','miss_require_all','hit_require_any','hit_forbid'] as $k) {
        $detail[$k] = array_values(array_unique($detail[$k]));
    }

    return $detail;
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
    // ✅ filter 规则：不要在这里做 when/require/forbid 的 tag 门槛
    // tag 门槛交给 applyFilterRulesToList 逐 item 处理（reads 用 item.tags，cards/highlights 用 ctxTags）
    $modeNow = (string)($rule['mode'] ?? 'patch');
    if (!isset($rule['mode']) && isset($rule['action'])) $modeNow = 'filter';

    // 只在非 filter 时，才允许 when.* 作为“上下文禁用/门槛”
    if ($modeNow !== 'filter') {
        $ctxTags = $context['ctx']['tags'] ?? [];
        if (!is_array($ctxTags)) $ctxTags = [];
        $ctxTags = array_values(array_filter($ctxTags, fn($x) => is_string($x) && $x !== ''));

        $when = $rule['when'] ?? null;
        if (is_array($when)) {
            // when.forbid：命中则整条 rule 不生效（非 filter 语义）
            $whenForbid = $when['forbid'] ?? null;
            if (is_array($whenForbid) && $whenForbid !== []) {
                foreach ($whenForbid as $t) {
                    if (is_string($t) && $t !== '' && in_array($t, $ctxTags, true)) {
                        return false;
                    }
                }
            }

            // when.require_all
            $reqAll = $when['require_all'] ?? null;
            if (is_array($reqAll) && $reqAll !== []) {
                foreach ($reqAll as $t) {
                    if (!is_string($t) || $t === '') continue;
                    if (!in_array($t, $ctxTags, true)) return false;
                }
            }

            // when.require_any + when.min_match
            $reqAny = $when['require_any'] ?? null;
            if (is_array($reqAny) && $reqAny !== []) {
                $min = (int)($when['min_match'] ?? 1);
                if ($min < 1) $min = 1;

                $hit = 0;
                foreach ($reqAny as $t) {
                    if (is_string($t) && $t !== '' && in_array($t, $ctxTags, true)) $hit++;
                }
                if ($hit < $min) return false;
            }
        }
    }

    // ✅ B) match 字段（type_code/section/locale...）
    $match = $rule['match'] ?? [];
if ($match === null) return true;
if (!is_array($match)) return true;

$typeCode   = (string)($context['type_code'] ?? '');
$sectionKey = (string)($context['section_key'] ?? '');

// ✅ match.any_tags / match.all_tags：基于 ctx.tags
$ctxTags = $context['ctx']['tags'] ?? [];
if (!is_array($ctxTags)) $ctxTags = [];
$ctxTags = array_values(array_filter($ctxTags, fn($x) => is_string($x) && $x !== ''));

if (isset($match['any_tags'])) {
    $any = $match['any_tags'];
    if (is_string($any)) $any = [$any];
    if (is_array($any)) {
        $any = array_values(array_filter($any, fn($x)=>is_string($x) && $x !== ''));
        if (!empty($any)) {
            $hit = false;
            foreach ($any as $t) {
                if (in_array($t, $ctxTags, true)) { $hit = true; break; }
            }
            if (!$hit) return false;
        }
    }
}

if (isset($match['all_tags'])) {
    $all = $match['all_tags'];
    if (is_string($all)) $all = [$all];
    if (is_array($all)) {
        $all = array_values(array_filter($all, fn($x)=>is_string($x) && $x !== ''));
        foreach ($all as $t) {
            if (!in_array($t, $ctxTags, true)) return false;
        }
    }
}

    if (array_key_exists('type_code', $match)) {
        $want = $match['type_code'];
        if (is_string($want)) {
            if ($want !== $typeCode) return false;
        } elseif (is_array($want)) {
            if (!in_array($typeCode, $want, true)) return false;
        }
    }

    if (isset($match['type_codes']) && is_array($match['type_codes'])) {
        if (!in_array($typeCode, $match['type_codes'], true)) return false;
    }

    if (array_key_exists('section', $match)) {
        $want = $match['section'];
        if (is_string($want)) {
            if ($want !== $sectionKey) return false;
        } elseif (is_array($want)) {
            if (!in_array($sectionKey, $want, true)) return false;
        }
    }

    if (isset($match['section_key']) && is_string($match['section_key'])) {
        if ($sectionKey !== $match['section_key']) return false;
    }
    if (isset($match['sections']) && is_array($match['sections'])) {
        if (!in_array($sectionKey, $match['sections'], true)) return false;
    }

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
private function applyRuleToList(array $list, array $matches, array $rule, array $context): array
{
    $mode = (string)($rule['mode'] ?? 'patch');

    if ($mode === 'filter') {
    Log::warning('[OVR] mode_filter_ignored (use RuleEngine)', [
        'id' => $rule['id'] ?? null,
        'target' => $context['target'] ?? null,
    ]);
    return $list;
}

// ✅ 兼容 select_rules 风格：如果没有 mode 但有 action，就当 filter
if (!isset($rule['mode']) && isset($rule['action'])) {
    $mode = 'filter';
}

$list = match ($mode) {
    'append'  => $this->modeAppend($list, $rule, $context),
    'prepend' => $this->modePrepend($list, $rule, $context),
    'remove'  => $this->modeRemove($list, $matches),
    'replace' => $this->modeReplace($list, $matches, $rule, $context),
    'upsert'  => $this->modeUpsert($list, $matches, $rule, $context),
    default   => $this->modePatch($list, $matches, $rule, $context),
};

    // ✅ 只对 cards 做 card normalize，避免 highlights/reads 被“卡片化”
$target = (string)($context['target'] ?? '');
if ($target === 'cards') {
    $list = array_map(
        fn($it) => \App\Services\Report\ReportContentNormalizer::card(is_array($it) ? $it : []),
        $list
    );
}

return $list;
}

private function selectorFromMatchItem($item): ?array
{
    // ✅ 形态 0：match.item 是 string
    if (is_string($item) && $item !== '') {
        return ['ids' => [$item]];
    }

    if (!is_array($item)) return null;

    // ✅ 形态 1：match.item 是 list of string：["read.canary.rule_engine"]
    $isList = array_keys($item) === range(0, count($item) - 1);
    if ($isList) {
        $ids = array_values(array_filter($item, fn($x) => is_string($x) && $x !== ''));
        if (!empty($ids)) return ['ids' => $ids];
    }

    // ✅ 形态 2：match.item.id: ["read_03"] or "read_03"
    if (isset($item['id'])) {
        $ids = $item['id'];
        if (is_string($ids)) $ids = [$ids];
        if (is_array($ids)) {
            $ids = array_values(array_filter($ids, fn($x) => is_string($x) && $x !== ''));
            if (!empty($ids)) return ['ids' => $ids];
        }
    }

    // ✅ 形态 3：match.item.kind
    if (isset($item['kind'])) {
        $kinds = $item['kind'];
        if (is_string($kinds)) $kinds = [$kinds];
        if (is_array($kinds)) {
            $kinds = array_values(array_filter($kinds, fn($x) => is_string($x) && $x !== ''));
            if (count($kinds) === 1) return ['kind' => $kinds[0]];
        }
    }

    return null;
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

private function modeFilter(array $list, array $matches, array $rule): array
{
    // report_rules.json: effect.action
    $action = $rule['effect']['action'] ?? ($rule['action'] ?? 'drop');
    $action = strtolower((string)$action);

    if ($action === 'drop' || $action === 'remove') {
        return $this->modeRemove($list, $matches);
    }
    if ($action === 'keep') {
        return $this->modeKeepOnly($list, $matches);
    }

    return $list;
}

private function modeKeepOnly(array $list, array $matches): array
{
    if (empty($matches)) return [];
    $keep = array_flip($matches);

    $out = [];
    foreach ($list as $i => $it) {
        if (isset($keep[$i])) $out[] = $it;
    }
    return $out;
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

// ✅ ignore null (do not overwrite)
if ($v === null) {
    continue;
}

// ✅ tags/tips：支持 {"mode":"append","values":[...]} 这种 replace_fields 写法
$leaf = $k;
if (str_contains($leaf, '.')) {
    $parts = explode('.', $leaf);
    $leaf = (string)end($parts);
}
if (in_array($leaf, ['tags','tips'], true) && $this->isArrayFieldOp($v)) {
    $cur = $this->getByDotPath($item, $k);
    if (!is_array($cur)) $cur = [];
    $new = $this->applyArrayFieldOp($cur, $v);
    $this->setByDotPath($item, $k, $new);
    continue;
}

$this->setByDotPath($item, $k, $v);
    }
    return $item;
}

private function isArrayFieldOp($v): bool
{
    return is_array($v)
        && isset($v['mode'])
        && is_string($v['mode'])
        && in_array($v['mode'], ['append','prepend','replace','unique_append'], true);
}

private function applyArrayFieldOp(array $cur, array $spec): array
{
    $mode = (string)($spec['mode'] ?? 'replace');
    $vals = $spec['values'] ?? ($spec['value'] ?? []);
    if (is_string($vals)) $vals = [$vals];
    if (!is_array($vals)) $vals = [];
    $vals = array_values(array_filter($vals, fn($x)=>is_string($x) && trim($x) !== ''));

    $cur = array_values(array_filter($cur, fn($x)=>is_string($x) && trim($x) !== ''));

    return match ($mode) {
        'append' => array_values(array_merge($cur, $vals)),
        'prepend' => array_values(array_merge($vals, $cur)),
        'unique_append' => array_values(array_unique(array_merge($cur, $vals))),
        default => $vals, // replace
    };
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
 * Deep merge (NON-NULL):
 * - assoc arrays merge recursively
 * - numeric arrays replace
 * - ✅ null in patch will be ignored (will NOT overwrite base)
 */
private function deepMerge(array $base, array $patch): array
{
    foreach ($patch as $k => $v) {
        // ✅ ignore null (do not overwrite)
        if ($v === null) {
            continue;
        }

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

private function logReCtx(string $ctxName, array $ctx): void
{
    if (!app()->environment('local')) return;

    $tags = $ctx['tags'] ?? [];
    if (!is_array($tags)) $tags = [];
    $tags = array_values(array_filter($tags, fn($x) => is_string($x) && $x !== ''));

    // ✅ 让 reads/highlights 也有 [RE] context_tags
    Log::debug('[RE] context_tags', [
        'ctx' => $ctxName,
        'keys' => $tags,
    ]);

    // ✅ 你 grep 里也要看得到 tags_debug
    Log::debug('[RE] tags_debug', [
        'ctx' => $ctxName,
        'tags_n' => count($tags),
        'tags_sample' => array_slice($tags, 0, 40),
    ]);
}

private function logReExplain(string $ctxName, array $rules, array $ctx): void
{
    if (!app()->environment('local')) return;

    // 只在你开启 explain 时输出（避免太吵）
    if (!$this->shouldExplain($ctx)) return;

    Log::debug('[RE] explain', [
        'ctx' => $ctxName,
        'rules_n' => is_array($rules) ? count($rules) : -1,
        'rule_ids' => array_slice(
            array_map(fn($r) => is_array($r) ? ($r['id'] ?? null) : null, $rules ?? []),
            0,
            50
        ),
    ]);
}

private function withSrcOnRules(array $doc, array $src): array
{
    $rules = $doc['rules'] ?? null;
    if (!is_array($rules)) return $doc;

    foreach ($rules as $i => $r) {
        if (!is_array($r)) continue;
        $r['__src'] = $r['__src'] ?? $src;
        $doc['rules'][$i] = $r;
    }

    return $doc;
}
}