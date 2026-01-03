<?php

declare(strict_types=1);

namespace App\Services\RuleEngine;

use Illuminate\Support\Facades\Log;

class ReportRuleEngine
{
    /**
     * 现有：用于 cards/reads 等 item list 的 keep/drop 规则
     */
    public function apply(string $target, array $items, ?array $rulesDoc, array $ctx, string $explainCtx): array
    {
        $rules = $this->filterRulesByTarget($rulesDoc, $target);
        if (empty($rules)) {
            $this->emitExplain($target, $explainCtx, $ctx, $items, [], [], []);
            return array_values($items);
        }

        // 排序：priority desc
        usort($rules, fn($a,$b) => (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0));

        $ctxTags = $ctx['tags'] ?? [];
        if (is_array($ctxTags)) {
            // 兼容 set/map
            if (!$this->isListArray($ctxTags)) $ctxTags = array_keys($ctxTags);
        } else {
            $ctxTags = [];
        }
        $ctxTags = array_values(array_filter($ctxTags, fn($x)=>is_string($x) && $x !== ''));

        $originIds = $this->idsOf($items);
        $everIds   = $originIds;
        $everMap   = array_fill_keys($originIds, true);

        $trace = []; // id => reasons[]
        $pushReason = function(string $id, array $reason) use (&$trace) {
            if ($id === '') return;
            if (!isset($trace[$id])) $trace[$id] = [];
            $trace[$id][] = $reason;
        };

        $keepHit = [];
        $dropHit = [];
        $defaultDropCandidate = []; // id => ['rule'=>..., 'details'=>...]

        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            if (!$this->ruleMatchesContext($rule, $ctx)) continue;

            $rid = (string)($rule['id'] ?? 'rule');
            $action = strtolower((string)($rule['effect']['action'] ?? ($rule['action'] ?? 'drop')));

            // selector（可选）
            $selector = $rule['selector'] ?? null;
            if ($selector === null && isset($rule['match']['item']) && is_array($rule['match']['item'])) {
                $selector = ['ids' => array_values(array_filter($rule['match']['item'], fn($x)=>is_string($x) && $x!==''))];
            }

            // 命中 indexes
            $matchesIdx = [];
            if ($selector !== null) {
                $matchesIdx = $this->selectIndexes($items, $selector);
            } elseif ($this->hasTagConditions($rule)) {
                foreach ($items as $i => $it) {
                    if (!is_array($it)) continue;
                    $id = (string)($it['id'] ?? '');
                    if ($id === '') continue;

                    $itemTags = $this->getItemTags($it);

                    // reads：优先 item.tags；空才回退 ctxTags
                    if ($target === 'reads') {
                        $tagset = !empty($itemTags) ? $itemTags : $ctxTags;
                    } else {
                        // cards/highlights：合并
                        $tagset = array_values(array_unique(array_merge($ctxTags, $itemTags)));
                    }

                    if ($this->tagConditionsMatch($rule, $tagset)) {
                        $matchesIdx[] = (int)$i;
                    }
                }
            } else {
                // 无 selector、无 tag 条件：匹配全量（用于 drop_all_default）
                $matchesIdx = $this->selectIndexes($items, null);
            }

            $matchedMap = array_fill_keys($matchesIdx, true);

            foreach ($items as $i => $it) {
                if (!is_array($it)) continue;
                $id = (string)($it['id'] ?? '');
                if ($id === '') continue;

                $itemTags = $this->getItemTags($it);
                if ($target === 'reads') {
                    $tagset = !empty($itemTags) ? $itemTags : $ctxTags;
                } else {
                    $tagset = array_values(array_unique(array_merge($ctxTags, $itemTags)));
                }

                $isMatched = isset($matchedMap[$i]);
                $details = $this->explainDetailsForRuleOnTags($rule, $tagset);

                if ($action === 'keep') {
                    if ($isMatched) {
                        $keepHit[$id] = true;
                        $pushReason($id, [
                            'rule' => $rid,
                            'decision' => 'keep',
                            'matched' => true,
                            'details' => $details,
                        ]);
                    } else {
                        // keep-only: 不命中 => drop（matched=false）
                        $pushReason($id, [
                            'rule' => $rid,
                            'decision' => 'drop',
                            'matched' => false,
                            'details' => $details,
                        ]);
                    }
                    continue;
                }

                if ($action === 'drop' || $action === 'remove') {
                    if ($isMatched) {
                        if ($this->isDefaultDropRule($rule)) {
                            if (!isset($defaultDropCandidate[$id])) {
                                $defaultDropCandidate[$id] = ['rule'=>$rid, 'details'=>$details];
                            }
                        } else {
                            $dropHit[$id] = true;
                            $pushReason($id, [
                                'rule' => $rid,
                                'decision' => 'drop',
                                'matched' => true,
                                'details' => $details,
                            ]);
                        }
                    }
                    continue;
                }
            }
        }

        // 最终决策：drop > keep > default drop（default 只在没 keep 时生效）
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $id = (string)($it['id'] ?? '');
            if ($id === '') continue;

            // everIds 记录
            if (!isset($everMap[$id])) {
                $everMap[$id] = true;
                $everIds[] = $id;
            }

            if (!empty($dropHit[$id])) continue;
            if (!empty($keepHit[$id])) { $out[] = $it; continue; }

            if (isset($defaultDropCandidate[$id])) {
                $pushReason($id, [
                    'rule' => $defaultDropCandidate[$id]['rule'],
                    'decision' => 'drop',
                    'matched' => true,
                    'details' => $defaultDropCandidate[$id]['details'],
                ]);
                continue;
            }

            // 没 default drop 就放过
            $out[] = $it;
        }

        // explain
        $this->emitExplain($target, $explainCtx, $ctx, $out, $everIds, $trace, $originIds);

        return array_values($out);
    }

    // ==========================================================
    // ✅ 新增：highlights 选择规则入口（rules -> pick_ids + explain）
    // ==========================================================
    /**
     * 输入：report_highlights_rules.json（或 ContentStore normalize 后的 doc）
     *
     * 输出：
     * [
     *   'selected' => [
     *     'strength' => [ ['id'=>..., 'explain'=>..., 'rule'=>..., 'priority'=>...], ... ],
     *     'blindspot' => [ ... ],
     *     'action' => [ ... ],
     *   ]
     * ]
     *
     * 规则匹配：
     * - 支持 match.type_code / match.type_codes
     * - 支持 when.require_all / when.require_any / when.forbid / min_match
     * - ✅ 兼容 rule.tags：若没有 when/require_*，则 tags 会被当作 require_all
     */
    public function runHighlightsRules(?array $rulesDoc, array $ctx, string $explainCtx = 'highlights_rules'): array
    {
        $rules = [];
        if (is_array($rulesDoc)) {
            $rules = $rulesDoc['rules'] ?? $rulesDoc;
        }
        if (!is_array($rules)) $rules = [];

        $ctxTags = $this->normalizeCtxTags($ctx);

        // priority desc
        usort($rules, fn($a,$b) => (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0));

        $selected = [
            'strength' => [],
            'blindspot' => [],
            'action' => [],
        ];

        $seen = [
            'strength' => [],
            'blindspot' => [],
            'action' => [],
        ];

        // explain: 记录命中哪些规则（可用于验收）
        $hitRules = [];

        foreach ($rules as $idx => $r) {
            if (!is_array($r)) continue;

            $pool = is_string($r['pool'] ?? null) ? trim((string)$r['pool']) : '';
            if (!in_array($pool, ['strength','blindspot','action'], true)) continue;

            $rid = (string)($r['id'] ?? ('hl_rule_' . $idx));
            $prio = is_numeric($r['priority'] ?? null) ? (int)$r['priority'] : 0;

            // 兼容：tags -> require_all（如果没写 when/require_any/require_all/forbid）
            $r = $this->normalizeHighlightRuleCompat($r);

            // 1) match（type_code 等）
            if (!$this->ruleMatchesContext($r, $ctx)) continue;

            // 2) tags 条件（when / require_*）
            if ($this->hasTagConditions($r)) {
                if (!$this->tagConditionsMatch($r, $ctxTags)) continue;
            }

            $pick = $r['pick_ids'] ?? ($r['pick'] ?? null);
            if (!is_array($pick)) $pick = [];
            $pick = array_values(array_filter($pick, fn($x)=>is_string($x) && trim($x) !== ''));

            if ($pick === []) continue;

            $explain = $r['explain'] ?? null;
            if (is_array($explain)) {
                $explain = array_values(array_filter($explain, fn($x)=>is_string($x) && trim($x) !== ''));
                $explain = $explain ?: null;
            } elseif (!is_string($explain)) {
                $explain = null;
            }

            $hitRules[] = [
                'id'       => $rid,
                'pool'     => $pool,
                'priority' => $prio,
                'pick_ids' => $pick,
                'explain'  => $explain,
            ];

            foreach ($pick as $tid) {
                if (isset($seen[$pool][$tid])) continue;
                $seen[$pool][$tid] = true;

                $selected[$pool][] = [
                    'id'       => $tid,
                    'explain'  => $explain,
                    'rule'     => $rid,
                    'priority' => $prio,
                ];
            }
        }

        // ✅ emit explain（走你已有 capture_explain 机制）
        $this->emitHighlightsRulesExplain($explainCtx, $ctx, $ctxTags, $selected, $hitRules);

        return [
            'selected' => $selected,
        ];
    }

    private function emitExplain(string $target, string $ctxName, array $ctx, array $finalList, array $everIds, array $trace, array $originIds): void
    {
        $capture = (bool)($ctx['capture_explain'] ?? false);
        $collector = $ctx['explain_collector'] ?? null;
        if (!$capture || !is_callable($collector)) return;

        $ctxTags = $ctx['tags'] ?? [];
        if (is_array($ctxTags)) {
            if (!$this->isListArray($ctxTags)) $ctxTags = array_keys($ctxTags);
        } else {
            $ctxTags = [];
        }
        $ctxTags = array_values(array_filter($ctxTags, fn($x)=>is_string($x) && $x !== ''));

        $finalIds = $this->idsOf($finalList);
        $finalMap = array_fill_keys($finalIds, true);

        // 如果 everIds 为空，用 originIds 兜底
        if (empty($everIds)) $everIds = $originIds;

        $selected = [];
        foreach ($finalIds as $id) {
            $selected[] = ['id'=>$id,'final'=>'keep','reasons'=>$trace[$id] ?? []];
        }

        $rejected = [];
        foreach ($everIds as $id) {
            if (!isset($finalMap[$id])) {
                $rejected[] = ['id'=>$id,'final'=>'drop','reasons'=>$trace[$id] ?? []];
            }
        }

        $payload = [
            'target'       => $target,
            'ctx'          => $ctxName,
            'section_key'  => $ctx['section_key'] ?? null,
            'context_tags' => $ctxTags,
            'selected_n'   => count($selected),
            'rejected_n'   => count($rejected),
            'selected'     => array_slice($selected, 0, (int)(env('RE_EXPLAIN_ITEMS_MAX', 60))),
            'rejected'     => array_slice($rejected, 0, (int)(env('RE_EXPLAIN_ITEMS_MAX', 60))),
        ];

        $collector($ctxName, $payload);
    }

    // ====== highlights explain（给验收） ======
    private function emitHighlightsRulesExplain(string $ctxName, array $ctx, array $ctxTags, array $selected, array $hitRules): void
    {
        $capture = (bool)($ctx['capture_explain'] ?? false);
        $collector = $ctx['explain_collector'] ?? null;
        if (!$capture || !is_callable($collector)) return;

        $payload = [
            'target'       => 'highlights_rules',
            'ctx'          => $ctxName,
            'context_tags' => $ctxTags,
            'matched_rules_n' => count($hitRules),
            'matched_rules'   => array_slice($hitRules, 0, (int)(env('RE_EXPLAIN_ITEMS_MAX', 60))),
            'selected'        => $selected,
        ];

        $collector($ctxName, $payload);
    }

    private function filterRulesByTarget(?array $doc, string $target): array
    {
        if (!is_array($doc)) return [];
        $rules = $doc['rules'] ?? null;
        if (!is_array($rules)) return [];

        $out = [];
        foreach ($rules as $r) {
            if (!is_array($r)) continue;
            $t = $r['target'] ?? null;
            $ts = $r['targets'] ?? null;
            $ok = (is_string($t) && $t === $target) || (is_array($ts) && in_array($target, $ts, true));
            if ($ok) $out[] = $r;
        }
        return $out;
    }

    private function ruleMatchesContext(array $rule, array $ctx): bool
    {
        $match = $rule['match'] ?? null;
        if (!is_array($match)) $match = [];

        $typeCode = (string)($ctx['type_code'] ?? '');
        $sectionKey = (string)($ctx['section_key'] ?? '');

        if (isset($match['type_code'])) {
            $want = $match['type_code'];
            if (is_string($want) && $want !== $typeCode) return false;
            if (is_array($want) && !in_array($typeCode, $want, true)) return false;
        }
        if (isset($match['type_codes']) && is_array($match['type_codes'])) {
            if (!in_array($typeCode, $match['type_codes'], true)) return false;
        }

        if (isset($match['section'])) {
            $want = $match['section'];
            if (is_string($want) && $want !== $sectionKey) return false;
            if (is_array($want) && !in_array($sectionKey, $want, true)) return false;
        }
        return true;
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
        $tags = array_values(array_filter($tags, fn($x)=>is_string($x) && $x !== ''));

        $when = (isset($rule['when']) && is_array($rule['when'])) ? $rule['when'] : [];

        $forbid = is_array($when['forbid'] ?? null) ? $when['forbid'] : (is_array($rule['forbid'] ?? null) ? $rule['forbid'] : []);
        $reqAny = is_array($when['require_any'] ?? null) ? $when['require_any'] : (is_array($rule['require_any'] ?? null) ? $rule['require_any'] : []);
        $reqAll = is_array($when['require_all'] ?? null) ? $when['require_all'] : (is_array($rule['require_all'] ?? null) ? $rule['require_all'] : []);
        $min = (int)($rule['min_match'] ?? ($when['min_match'] ?? 0));

        foreach ($forbid as $t) {
            if (is_string($t) && $t !== '' && in_array($t, $tags, true)) return false;
        }

        foreach ($reqAll as $t) {
            if (!is_string($t) || $t === '') continue;
            if (!in_array($t, $tags, true)) return false;
        }

        if (!empty($reqAny)) {
            if ($min < 1) $min = 1;
            $hit = 0;
            foreach ($reqAny as $t) {
                if (is_string($t) && $t !== '' && in_array($t, $tags, true)) $hit++;
            }
            if ($hit < $min) return false;
        }

        return true;
    }

    private function isDefaultDropRule(array $rule): bool
    {
        $id = (string)($rule['id'] ?? '');
        if ($id !== '' && str_contains($id, 'drop_all_default')) return true;

        $action = strtolower((string)($rule['effect']['action'] ?? ($rule['action'] ?? '')));
        if ($action !== 'drop' && $action !== 'remove') return false;

        $hasSelector = isset($rule['selector']) && is_array($rule['selector']);
        $hasTagCond = $this->hasTagConditions($rule);

        return !$hasSelector && !$hasTagCond;
    }

    private function getItemTags(array $it): array
    {
        $tags = $it['tags'] ?? [];
        if (!is_array($tags)) return [];
        return array_values(array_filter($tags, fn($x)=>is_string($x) && $x !== ''));
    }

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

    private function selectIndexes(array $list, $selector): array
    {
        $n = count($list);
        if ($selector === null) return $n > 0 ? range(0, $n - 1) : [];
        if (!is_array($selector)) return [];

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
        return [];
    }

    private function explainDetailsForRuleOnTags(array $rule, array $tags): array
    {
        $when = (isset($rule['when']) && is_array($rule['when'])) ? $rule['when'] : [];

        $forbid = is_array($when['forbid'] ?? null) ? $when['forbid'] : (is_array($rule['forbid'] ?? null) ? $rule['forbid'] : []);
        $reqAny = is_array($when['require_any'] ?? null) ? $when['require_any'] : (is_array($rule['require_any'] ?? null) ? $rule['require_any'] : []);
        $reqAll = is_array($when['require_all'] ?? null) ? $when['require_all'] : (is_array($rule['require_all'] ?? null) ? $rule['require_all'] : []);

        $min = (int)($rule['min_match'] ?? ($when['min_match'] ?? 0));

        $detail = [
            'hit_require_all'  => [],
            'miss_require_all' => [],
            'hit_require_any'  => [],
            'need_min_match'   => $min,
            'hit_forbid'       => [],
        ];

        foreach ($forbid as $t) {
            if (is_string($t) && $t !== '' && in_array($t, $tags, true)) $detail['hit_forbid'][] = $t;
        }
        foreach ($reqAny as $t) {
            if (is_string($t) && $t !== '' && in_array($t, $tags, true)) $detail['hit_require_any'][] = $t;
        }
        if (!empty($reqAny) && $detail['need_min_match'] < 1) $detail['need_min_match'] = 1;

        foreach ($reqAll as $t) {
            if (!is_string($t) || $t === '') continue;
            if (in_array($t, $tags, true)) $detail['hit_require_all'][] = $t;
            else $detail['miss_require_all'][] = $t;
        }

        foreach (['hit_require_all','miss_require_all','hit_require_any','hit_forbid'] as $k) {
            $detail[$k] = array_values(array_unique($detail[$k]));
        }

        return $detail;
    }

    // ===== internal helpers for highlights =====

    private function normalizeCtxTags(array $ctx): array
    {
        $tags = $ctx['tags'] ?? [];
        if (!is_array($tags)) return [];

        // 兼容 set/map（TagBuilder 的 set）
        if (!$this->isListArray($tags)) {
            $tags = array_keys($tags);
        }

        return array_values(array_filter($tags, fn($x)=>is_string($x) && trim($x) !== ''));
    }

    private function normalizeHighlightRuleCompat(array $r): array
    {
        // 如果已经有 when/require_* 就不动
        $hasWhen = isset($r['when']) && is_array($r['when']);
        $hasTopCond = isset($r['require_all']) || isset($r['require_any']) || isset($r['forbid']);

        if ($hasWhen || $hasTopCond) return $r;

        // 兼容：tags -> require_all
        $tags = $r['tags'] ?? null;
        if (is_array($tags) && $tags !== []) {
            $tags = array_values(array_filter($tags, fn($x)=>is_string($x) && trim($x) !== ''));
            if ($tags !== []) {
                $r['require_all'] = $tags;
            }
        }

        return $r;
    }

    private function isListArray(array $a): bool
    {
        if ($a === []) return true;
        return array_keys($a) === range(0, count($a) - 1);
    }
}