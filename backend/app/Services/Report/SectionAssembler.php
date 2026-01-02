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
     * - (optional) $report['_explain']['assembler']['cards'] = <fullExplain>  // debug only
     */
    public function apply(array $report, ContentStore $store, array $ctx = []): array
    {
        if (!isset($report['sections']) || !is_array($report['sections'])) {
            return $report;
        }

        // ✅ 必须走 ContentStore loader（验收点 4）
        $policiesDoc = $store->loadSectionPolicies();

        // ContentStore::loadSectionPolicies() 已经统一返回 ['schema'=>..., 'items'=> <policies map>]
        $sectionsPolicy = is_array($policiesDoc['items'] ?? null) ? $policiesDoc['items'] : [];

        // policies 文件里如果你还想放 defaults，这里也兼容（即使 ContentStore 未来扩展）
        $defaults = is_array($policiesDoc['defaults'] ?? null) ? $policiesDoc['defaults'] : [];

        // ✅ explain 开关（建议仅本地/验收开）
        $captureExplain = (bool)($ctx['capture_explain'] ?? false);

        if ($sectionsPolicy === []) {
            // No policies => no-op (but keep report stable)
            $report['_meta'] = $report['_meta'] ?? [];
            $report['_meta']['sections'] = $report['_meta']['sections'] ?? [];
            $report['_meta']['section_policies'] = [
                'ok' => false,
                'reason' => 'missing_or_empty_section_policies',
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

        foreach (($fullExplain['by_section'] ?? []) as $secKey => $secExplain) {
            if (!is_array($secExplain)) continue;
            $secKey = (string)$secKey;

            $report['_meta']['sections'][$secKey] = $report['_meta']['sections'][$secKey] ?? [];
            $report['_meta']['sections'][$secKey]['assembler'] = $secExplain;
        }

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
        if (!is_array($policy)) {
            // no policy for this section => no-op
            return [$sec, null];
        }

        $target = (int)($policy['target_cards'] ?? 0);
        $min = (int)($policy['min_cards'] ?? 0);
        $max = (int)($policy['max_cards'] ?? 0);

        $allowFallback = $policy['allow_fallback'] ?? true;
        $allowFallback = is_bool($allowFallback) ? $allowFallback : (bool)$allowFallback;

        if ($max > 0 && $target > 0) $target = min($target, $max);
        if ($max > 0 && $min > 0) $min = min($min, $max);

        $cards = $sec['cards'] ?? [];
        if (!is_array($cards)) $cards = [];

        $beforeN = count($cards);

        // 1) cap max
        $trimmedToMax = false;
        if ($max > 0 && count($cards) > $max) {
            $cards = array_slice($cards, 0, $max);
            $trimmedToMax = true;
        }

        // 2) pick target (stable output)
        $trimmedToTarget = false;
        if ($target > 0 && count($cards) > $target) {
            $cards = array_slice($cards, 0, $target);
            $trimmedToTarget = true;
        }

        $afterTrimN = count($cards);

        // 3) fallback fill to min
        $added = [];
        $addedIds = [];
        $fallbackUsed = false;

        if ($min > 0 && $afterTrimN < $min && $allowFallback) {
            // ✅ 这里必须走 ContentStore loader（验收点 4）
            $fallbackPool = $store->loadFallbackCards($sectionKey);

            // dedupe
            $dedupeKey = (string)($defaults['dedupe_by'] ?? 'id');
            $existingIds = $this->collectIds($cards, $dedupeKey);

            foreach ($fallbackPool as $item) {
                if (!is_array($item)) continue;

                $id = $item[$dedupeKey] ?? null;

                // If has id, dedupe by id
                if (is_string($id) && $id !== '') {
                    if (isset($existingIds[$id])) continue;
                    $existingIds[$id] = true;
                    $addedIds[] = $id;
                }

                $added[] = $item;

                if (($afterTrimN + count($added)) >= $min) {
                    break;
                }
            }

            // optional repeat (default false)
            $allowRepeat = (bool)($defaults['allow_repeat_fallback'] ?? false);
            if ($allowRepeat && ($afterTrimN + count($added)) < $min && count($fallbackPool) > 0) {
                $idx = 0;
                while (($afterTrimN + count($added)) < $min && $idx < 2000) {
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
            } else {
                // 这条 log 仅辅助排查：缺卡但 fallbackPool 不够/为空
                Log::warning('[SectionAssembler] fallback_pool_empty_or_insufficient', [
                    'section' => $sectionKey,
                    'need_min' => $min,
                    'after_trim' => $afterTrimN,
                    'pool_count' => is_array($fallbackPool) ? count($fallbackPool) : -1,
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
            'policy' => [
                'target_cards'   => $target,
                'min_cards'      => $min,
                'max_cards'      => $max,
                'allow_fallback' => $allowFallback,
                'fallback_file'  => $policy['fallback_file'] ?? "report_cards_fallback_{$sectionKey}.json",
            ],
            'counts' => [
                'before'         => $beforeN,
                'after_trim'     => $afterTrimN,
                'fallback_added' => count($added),
                'final'          => count($cards),
            ],
            'actions' => [
                'trimmed_to_max'    => $trimmedToMax,
                'trimmed_to_target' => $trimmedToTarget,
                'fallback_allowed'  => $allowFallback,
                'fallback_used'     => $fallbackUsed,
            ],
            'fallback_added_ids' => $addedIds,
        ];

        return [$sec, $secExplain];
    }

    /**
     * Extract section key from a section object (if sections is a list).
     */
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
     * Collect ids for dedupe.
     *
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