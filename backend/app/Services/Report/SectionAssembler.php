<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Services\Content\ContentStore;
use Illuminate\Support\Facades\Log;

class SectionAssembler
{
    /**
     * Apply "pick N + fallback fill" for each section AFTER RuleEngine.
     *
     * Expected report shape (loose):
     * - $report['sections'] : assoc map by section_key (recommended) OR list of sections
     * - each section has: ['cards' => [...]]
     *
     * Output:
     * - $report['_meta']['sections'][sec]['assembler'] = <secExplain>   // for jq/runtime acceptance
     * - $report['_meta']['section_assembler'] = <globalMeta>           // composer acceptance
     * - (optional) $report['_explain']['assembler']['cards'] = <fullExplain>  // debug only
     */
    public function apply(array $report, ContentStore $store, array $ctx = []): array
    {
        if (!isset($report['sections']) || !is_array($report['sections'])) {
            return $report;
        }

        // ✅ 必须走 ContentStore loader（验收点 4）
        $policiesDoc = $store->loadSectionPolicies();

        // ✅ policies 可能有多种形态：
        // A) { items: { traits:{...}, career:{...} } }
        // B) { items: { cards: { traits:{...} } } }   // 常见：按 target 分组
        // C) { sections: { traits:{...} } }          // 兼容旧/未来扩展
        $rawItems = is_array($policiesDoc['items'] ?? null) ? $policiesDoc['items'] : [];

        if (is_array($rawItems['cards'] ?? null)) {
            $sectionsPolicy = $rawItems['cards'];          // 形态 B
        } elseif (is_array($policiesDoc['sections'] ?? null)) {
            $sectionsPolicy = $policiesDoc['sections'];    // 形态 C
        } else {
            $sectionsPolicy = $rawItems;                   // 形态 A
        }

        if (!is_array($sectionsPolicy)) $sectionsPolicy = [];

        // policies 文件里如果你还想放 defaults，这里也兼容
        $defaults = is_array($policiesDoc['defaults'] ?? null) ? $policiesDoc['defaults'] : [];

        // ✅ explain 开关（建议仅本地/验收开）
        $captureExplain = (bool)($ctx['capture_explain'] ?? false);

        /**
         * policies 为空：必须显式发射全局 meta，否则 composer 会认为 “assembler_did_not_emit_meta”
         */
        if ($sectionsPolicy === []) {
            $report['_meta'] = $report['_meta'] ?? [];
            $report['_meta']['sections'] = $report['_meta']['sections'] ?? [];

            // 旧字段保留
            $report['_meta']['section_policies'] = [
                'ok' => false,
                'reason' => 'missing_or_empty_section_policies',
            ];

            // ✅ NEW：验收脚本看的全局字段（关键）
            $report['_meta']['section_assembler'] = [
                'ok' => false,
                'reason' => 'missing_or_empty_section_policies',
                'meta_fallback_used' => false,
            ];

            if ($captureExplain) {
                $report['_explain'] = $report['_explain'] ?? [];
                $report['_explain']['assembler'] = $report['_explain']['assembler'] ?? [];
                $report['_explain']['assembler']['cards'] = [
                    'ok' => false,
                    'reason' => 'missing_or_empty_section_policies',
                ];
            }

            return $report;
        }

        $sections = $report['sections'];

        // Work on both: assoc map OR list
        $isAssoc = $this->isAssocArray($sections);

        $fullExplain = [
            'ok' => true,
            'policy_schema' => $policiesDoc['schema'] ?? null,
            'defaults' => $defaults,
            'by_section' => [],
        ];

        if ($isAssoc) {
            foreach ($sections as $sectionKey => $sec) {
                if (!is_array($sec)) continue;

                [$sec2, $secExplain] = $this->applyOneSection(
                    (string)$sectionKey,
                    $sec,
                    $sectionsPolicy,
                    $store,
                    $defaults
                );

                $sections[$sectionKey] = $sec2;
                if (is_array($secExplain)) {
                    $fullExplain['by_section'][(string)$sectionKey] = $secExplain;
                }
            }
        } else {
            foreach ($sections as $i => $sec) {
                if (!is_array($sec)) continue;

                $sectionKey = $this->extractSectionKey($sec) ?? (string)$i;

                [$sec2, $secExplain] = $this->applyOneSection(
                    (string)$sectionKey,
                    $sec,
                    $sectionsPolicy,
                    $store,
                    $defaults
                );

                $sections[$i] = $sec2;
                if (is_array($secExplain)) {
                    $fullExplain['by_section'][(string)$sectionKey] = $secExplain;
                }
            }
        }

        $report['sections'] = $sections;

        // ✅ 产出 meta：用于 jq/接口验收（稳定、非调试）
        $report['_meta'] = $report['_meta'] ?? [];
        $report['_meta']['sections'] = $report['_meta']['sections'] ?? [];

        // ✅ 逐 section 写入 assembler meta（这是 composer 验收用的核心字段）
        foreach (($fullExplain['by_section'] ?? []) as $secKey => $secExplain) {
            if (!is_array($secExplain)) continue;
            $secKey = (string)$secKey;

            $report['_meta']['sections'][$secKey] = $report['_meta']['sections'][$secKey] ?? [];
            $report['_meta']['sections'][$secKey]['assembler'] = $secExplain;
        }

        // ✅ NEW：全局汇总标记（验收脚本要用）
        $existingGlobal = $report['_meta']['section_assembler'] ?? null;
        $report['_meta']['section_assembler'] = is_array($existingGlobal) ? $existingGlobal : [];

        $report['_meta']['section_assembler'] = array_merge(
            [
                'ok' => true,
                'meta_fallback_used' => false,
                'policy_schema' => $policiesDoc['schema'] ?? null,
            ],
            $report['_meta']['section_assembler']
        );

        // ✅ 可选 explain：仅在 captureExplain 开启时写（避免线上 payload 变大）
        if ($captureExplain) {
            $report['_explain'] = $report['_explain'] ?? [];
            $report['_explain']['assembler'] = $report['_explain']['assembler'] ?? [];
            $report['_explain']['assembler']['cards'] = $fullExplain;
        }

        return $report;
    }

    /**
     * @return array{0: array, 1: ?array}
     */
    private function applyOneSection(
        string $sectionKey,
        array $sec,
        array $sectionsPolicy,
        ContentStore $store,
        array $defaults
    ): array {
        $policy = $sectionsPolicy[$sectionKey] ?? null;

        $policyMissing = false;
        if (!is_array($policy)) {
            $policyMissing = true;
            $policy = [];
        }

        // ✅ 兼容 key：min/min_cards, target/target_cards, max/max_cards
        $target = (int)($policy['target_cards'] ?? $policy['target'] ?? 0);
        $min    = (int)($policy['min_cards'] ?? $policy['min'] ?? 0);
        $max    = (int)($policy['max_cards'] ?? $policy['max'] ?? 0);

        $allowFallback = $policy['allow_fallback'] ?? true;
        $allowFallback = is_bool($allowFallback) ? $allowFallback : (bool)$allowFallback;

        // 防御性修正
        if ($min < 0) $min = 0;
        if ($target < 0) $target = 0;
        if ($max < 0) $max = 0;

        // cap by max
        if ($max > 0 && $target > 0) $target = min($target, $max);
        if ($max > 0 && $min > 0)    $min    = min($min, $max);

        // ✅ want：补齐目标 = max(min, target)，并且不超过 max（若 max>0）
        $want = max($min, $target);
        if ($max > 0) $want = min($want, $max);

        $cards = $sec['cards'] ?? [];
        if (!is_array($cards)) $cards = [];

        $beforeN = count($cards);

        // 1) cap max（先硬切 max，保证不会溢出）
        $trimmedToMax = false;
        if ($max > 0 && count($cards) > $max) {
            $cards = array_slice($cards, 0, $max);
            $trimmedToMax = true;
        }

        // 2) pick want（稳定输出：超过 want 就截断到 want）
        $trimmedToWant = false;
        if ($want > 0 && count($cards) > $want) {
            $cards = array_slice($cards, 0, $want);
            $trimmedToWant = true;
        }

        $afterTrimN = count($cards);

        // 3) fallback fill to want（而不是 min）
        $added = [];
        $addedIds = [];
        $fallbackUsed = false;
        $shortAfterFill = 0;

        if ($want > 0 && $afterTrimN < $want && $allowFallback) {
            // ✅ 这里必须走 ContentStore loader（验收点 4）
            $fallbackPoolRaw = $store->loadFallbackCards($sectionKey);

            // ✅ 兼容：fallbackPool 可能是 list，也可能是 doc（含 items）
            if (is_array($fallbackPoolRaw) && array_key_exists('items', $fallbackPoolRaw) && is_array($fallbackPoolRaw['items'])) {
                $fallbackPool = $fallbackPoolRaw['items'];
            } elseif (is_array($fallbackPoolRaw)) {
                $fallbackPool = $fallbackPoolRaw;
            } else {
                $fallbackPool = [];
            }

            // dedupe
            $dedupeKey = (string)($defaults['dedupe_by'] ?? 'id');
            $existingIds = $this->collectIds($cards, $dedupeKey);

            foreach ($fallbackPool as $item) {
                if (!is_array($item)) continue;

                $id = $item[$dedupeKey] ?? null;

                if (is_string($id) && $id !== '') {
                    if (isset($existingIds[$id])) continue;
                    $existingIds[$id] = true;
                    $addedIds[] = $id;
                }

                $added[] = $item;

                if (($afterTrimN + count($added)) >= $want) {
                    break;
                }
            }

            // optional repeat (default false)
            $allowRepeat = (bool)($defaults['allow_repeat_fallback'] ?? false);
            if ($allowRepeat && ($afterTrimN + count($added)) < $want && count($fallbackPool) > 0) {
                $idx = 0;
                while (($afterTrimN + count($added)) < $want && $idx < 2000) {
                    $item = $fallbackPool[$idx % count($fallbackPool)];
                    if (is_array($item)) $added[] = $item;
                    $idx++;
                }
            }

            if (count($added) > 0) {
                $fallbackUsed = true;
                $appendMode = (string)($defaults['fallback_append_mode'] ?? 'append_after_existing');
                if ($appendMode === 'prepend_before_existing') {
                    $cards = array_merge($added, $cards);
                } else {
                    $cards = array_merge($cards, $added);
                }
            }

            // 仍不足：记录短缺（不要静默）
            if (count($cards) < $want) {
                $shortAfterFill = $want - count($cards);
                Log::warning('[SectionAssembler] still_short_after_fallback_fill', [
                    'section' => $sectionKey,
                    'want' => $want,
                    'min' => $min,
                    'target' => $target,
                    'max' => $max,
                    'after_trim' => $afterTrimN,
                    'final' => count($cards),
                    'short' => $shortAfterFill,
                    'pool_count' => is_array($fallbackPool) ? count($fallbackPool) : -1,
                    'allow_repeat_fallback' => (bool)($defaults['allow_repeat_fallback'] ?? false),
                ]);
            }
        }

        // 4) final cap max (safety)
        if ($max > 0 && count($cards) > $max) {
            $cards = array_slice($cards, 0, $max);
            $trimmedToMax = true;
        }

        $sec['cards'] = $cards;

        $secExplain = [
            'ok' => !$policyMissing,
            'policy_missing' => $policyMissing,
            'policy' => [
                'target_cards'   => $target,
                'min_cards'      => $min,
                'max_cards'      => $max,
                'want_cards'     => $want,
                'allow_fallback' => $allowFallback,
                'fallback_file'  => $policy['fallback_file'] ?? "report_cards_fallback_{$sectionKey}.json",
            ],
            'counts' => [
                'before'           => $beforeN,
                'after_trim'       => $afterTrimN,
                'fallback_added'   => count($added),
                'final'            => count($cards),
                'short_after_fill' => $shortAfterFill,
            ],
            'actions' => [
                'trimmed_to_max'  => $trimmedToMax,
                'trimmed_to_want' => $trimmedToWant,
                'fallback_allowed'=> $allowFallback,
                'fallback_used'   => $fallbackUsed,
            ],
            'fallback_added_ids' => $addedIds,
        ];

        return [$sec, $secExplain];
    }

    private function extractSectionKey(array $sec): ?string
    {
        foreach (['section_key', 'key', 'id', 'name'] as $k) {
            if (isset($sec[$k]) && is_string($sec[$k]) && $sec[$k] !== '') {
                return $sec[$k];
            }
        }
        return null;
    }

    /**
     * @return array<string, bool>
     */
    private function collectIds(array $cards, string $dedupeKey): array
    {
        $set = [];
        foreach ($cards as $c) {
            if (!is_array($c)) continue;
            $id = $c[$dedupeKey] ?? null;
            if (is_string($id) && $id !== '') {
                $set[$id] = true;
            }
        }
        return $set;
    }

    private function isAssocArray(array $arr): bool
    {
        if ($arr === []) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}