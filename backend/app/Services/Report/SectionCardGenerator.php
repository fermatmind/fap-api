<?php

namespace App\Services\Report;

use App\Services\Content\ContentPack;
use App\Services\Content\ContentStore;
use App\Services\Rules\RuleEngine;
use Illuminate\Support\Facades\Log;

final class SectionCardGenerator
{
    /**
     * ✅ 新入口：统一走 ContentStore（pack chain -> store -> 标准化 doc）
     *
     * @param string $section traits/career/growth/relationships
     * @param ContentPack[] $chain primary + fallback packs
     * @param array $userTags TagBuilder 输出
     * @param array $axisInfo 建议传 report.scores（含 delta/side/pct）；可选 axisInfo['attempt_id'] 用于稳定打散
     * @param string|null $legacyContentPackageDir 仅用于日志/兜底信息（不会读取旧路径）
     * @return array[] cards
     */
    public function generateFromPackChain(
        string $section,
        array $chain,
        array $userTags,
        array $axisInfo = [],
        ?string $legacyContentPackageDir = null
    ): array {
        // ✅ 统一加载器：所有 items/rules 标准化都在 store 内完成
        $store = new ContentStore($chain);

        $doc = $store->loadCardsDoc($section);
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
        $rules = is_array($doc['rules'] ?? null) ? $doc['rules'] : [];

        Log::info('[CARDS] loaded_from_store', [
            'section'    => $section,
            'file'       => "report_cards_{$section}.json",
            'items'      => count($items),
            'rules'      => $rules,
            'legacy_dir' => $legacyContentPackageDir,
        ]);

        return $this->generateFromItems(
            $section,
            $items,
            $userTags,
            $axisInfo,
            $legacyContentPackageDir,
            $rules
        );
    }

    /**
     * ✅ 从 “已标准化的 items(+rules)” 生成 cards
     * 注意：这里不再做 rules/tags/priority/tips 的缺省补齐——这些都应由 ContentStore 负责。
     */
    private function generateFromItems(
        string $section,
        array $items,
        array $userTags,
        array $axisInfo = [],
        ?string $legacyContentPackageDir = null,
        array $rules = []
    ): array {
        // ==========
        // rules：强依赖 store 的标准输出（不在 generator 兜底）
        // ==========
        $minCardsVal    = $rules['min_cards'] ?? null;
        $targetCardsVal = $rules['target_cards'] ?? null;
        $maxCardsVal    = $rules['max_cards'] ?? null;
        $fallbackTags   = $rules['fallback_tags'] ?? null;

        if (!is_numeric($minCardsVal) || !is_numeric($targetCardsVal) || !is_numeric($maxCardsVal) || !is_array($fallbackTags)) {
            throw new \RuntimeException('CARDS_RULES_NOT_NORMALIZED: generator expects store-normalized rules (min_cards/target_cards/max_cards/fallback_tags)');
        }

        $minCards    = (int)$minCardsVal;
        $targetCards = (int)$targetCardsVal;
        $maxCards    = (int)$maxCardsVal;

        // 体验目标：axis 最多 2，且至少 1 张 non-axis（当 target>=3 时）
        $axisMax     = max(0, min(2, $targetCards - 1));
        $nonAxisMin  = ($targetCards >= 3) ? 1 : 0;

        // assets 不存在：直接返回兜底
        if (empty($items)) {
            return $this->fallbackCards($section, $minCards);
        }

        // normalize userTags set（这里仅做 userTags 的集合化，不涉及 content item 标准化）
        $userSet = [];
        foreach ($userTags as $t) {
            if (!is_string($t)) continue;
            $t = trim($t);
            if ($t !== '') $userSet[$t] = true;
        }

        // seed
        $seed = $this->stableSeed($userSet, $axisInfo);

        // RuleEngine
        $re = app(RuleEngine::class);
        $ctx = "cards:{$section}";
        $debugRE = app()->environment('local', 'development') && (bool) env('FAP_RE_DEBUG', false);

        $evalById = [];
        $rejectedSamples = [];

        // evaluate + score (items 已由 store 标准化)
        $cands = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $id = (string)($it['id'] ?? '');
            if ($id === '') continue;

            // store 已保证 tags/rules/priority 存在且类型正确；这里不再补默认，只做最小安全读取
            $tags = is_array($it['tags'] ?? null) ? $it['tags'] : [];
            $prio = (int)($it['priority'] ?? 0);
            $itRules = is_array($it['rules'] ?? null) ? $it['rules'] : [];

            $base = [
                'id'       => $id,
                'tags'     => $tags,
                'priority' => $prio,
                'rules'    => $itRules,
            ];

            $ev = $re->evaluate($base, $userSet, [
                'seed' => $seed,
                'ctx'  => $ctx,
                'debug' => $debugRE,
                'global_rules' => [],
            ]);

            $evalById[$id] = $ev;

            if (!$ev['ok']) {
                if ($debugRE && count($rejectedSamples) < 6) {
                    $rejectedSamples[] = [
                        'id' => $id,
                        'reason' => $ev['reason'],
                        'detail' => $ev['detail'] ?? null,
                        'hit' => $ev['hit'],
                        'priority' => $ev['priority'],
                        'min_match' => $ev['min_match'],
                        'score' => $ev['score'],
                    ];
                }
                continue;
            }

            // axis match 门槛
            if (!$this->passesAxisMatch($it, $userSet, $axisInfo)) {
                continue;
            }

            $isAxis = $this->isAxisCardId($id);

            // store 已保证 bullets/tips 为 array 且 tips 已由 normalizer 补齐
            $cands[] = [
                'id'       => $id,
                'section'  => (string)($it['section'] ?? $section),
                'title'    => (string)($it['title'] ?? ''),
                'desc'     => (string)($it['desc'] ?? ''),
                'bullets'  => is_array($it['bullets'] ?? null) ? array_values($it['bullets']) : [],
                'tips'     => is_array($it['tips'] ?? null) ? array_values($it['tips']) : [],
                'tags'     => $tags,
                'priority' => $prio,
                'match'    => $it['match'] ?? null,

                '_hit'     => (int)($ev['hit'] ?? 0),
                '_score'   => (int)($ev['score'] ?? 0),
                '_min'     => (int)($ev['min_match'] ?? 0),
                '_is_axis' => $isAxis,
                '_shuffle' => (int)($ev['shuffle'] ?? 0),
            ];
        }

        // sort
        usort($cands, function ($a, $b) {
            $sa = (int)($a['_score'] ?? 0);
            $sb = (int)($b['_score'] ?? 0);
            if ($sa !== $sb) return $sb <=> $sa;

            $sha = (int)($a['_shuffle'] ?? 0);
            $shb = (int)($b['_shuffle'] ?? 0);
            if ($sha !== $shb) return $sha <=> $shb;

            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        $out  = [];
        $seen = [];

        $primary = array_slice($cands, 0, $targetCards);

        // 先保证 non-axis >= 1（当 target>=3）
        if ($nonAxisMin > 0) {
            foreach ($primary as $c) {
                if (count($out) >= $targetCards) break;
                if ((int)($c['_hit'] ?? 0) <= 0) continue;
                if ((bool)($c['_is_axis'] ?? false) === true) continue;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;
                $seen[$id] = true;

                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;

                if ($this->countNonAxis($out) >= $nonAxisMin) break;
            }
        }

        // 再填 hit>0
        foreach ($primary as $c) {
            if (count($out) >= $targetCards) break;
            if ((int)($c['_hit'] ?? 0) <= 0) continue;

            $id = (string)($c['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;

            if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxis($out) >= $axisMax) continue;

            $seen[$id] = true;
            unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
            $out[] = $c;
        }

        // 不足：补齐 non-axis
        if ($nonAxisMin > 0 && $this->countNonAxis($out) < $nonAxisMin) {
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;
                if ((bool)($c['_is_axis'] ?? false) === true) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;

                if ($this->countNonAxis($out) >= $nonAxisMin) break;
            }
        }

        // 不足：补齐 axis
        if ($this->countAxis($out) < $axisMax) {
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;
                if ((bool)($c['_is_axis'] ?? false) !== true) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;

                if ($this->countAxis($out) >= $axisMax) break;
            }
        }

        // fallback tags 补齐到 minCards
        if (count($out) < $minCards) {
            foreach ($cands as $c) {
                if (count($out) >= $targetCards) break;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;

                if (!$this->hasAnyTag($c['tags'] ?? [], $fallbackTags)) continue;
                if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxis($out) >= $axisMax) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;
            }
        }

        // 仍不足：随便补到 minCards
        if (count($out) < $minCards) {
            foreach ($cands as $c) {
                if (count($out) >= $minCards) break;

                $id = (string)($c['id'] ?? '');
                if ($id === '' || isset($seen[$id])) continue;

                if ((bool)($c['_is_axis'] ?? false) === true && $this->countAxis($out) >= $axisMax) continue;

                $seen[$id] = true;
                unset($c['_hit'], $c['_is_axis'], $c['_shuffle']);
                $out[] = $c;
            }
        }

        if (count($out) < $minCards) {
            $out = array_merge($out, $this->fallbackCards($section, $minCards - count($out)));
        }

        $out = array_slice(array_values($out), 0, $maxCards);

        // RE explain
        $selectedExplains = [];
        if ($debugRE) {
            foreach ($out as $c) {
                $id = (string)($c['id'] ?? '');
                $ev = $evalById[$id] ?? null;
                if (is_array($ev)) {
                    $selectedExplains[] = [
                        'id' => $id,
                        'hit' => $ev['hit'],
                        'priority' => $ev['priority'],
                        'min_match' => $ev['min_match'],
                        'score' => $ev['score'],
                    ];
                }
            }
        }
        $re->explain($ctx, $selectedExplains, $rejectedSamples, ['debug' => $debugRE]);

        Log::info('[CARDS] selected', [
            'section' => $section,
            'ids'     => array_map(fn($x) => $x['id'] ?? null, $out),
            'legacy_dir' => $legacyContentPackageDir,
        ]);

        return $out;
    }

    /**
     * ✅ 从 store 直接生成（你已有；保留）
     */
    public function generateFromStore(
        string $section,
        ContentStore $store,
        array $userTags,
        array $axisInfo,
        ?string $legacyContentPackageDir = null
    ): array {
        $doc = $store->loadCardsDoc($section);

        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
        $rules = is_array($doc['rules'] ?? null) ? $doc['rules'] : [];

        return $this->generateFromItems(
            $section,
            $items,
            $userTags,
            $axisInfo,
            $legacyContentPackageDir,
            $rules
        );
    }

    /**
     * ⚠️ 旧入口（建议只保留给兼容调用）；开启开关后，任何旧调用直接炸
     */
    public function generate(string $section, string $contentPackageVersion, array $userTags, array $axisInfo = []): array
    {
        if ((bool) env('FAP_FORBID_LEGACY_CARDS_LOADER', false)) {
            throw new \RuntimeException('LEGACY_SECTION_CARD_GENERATOR_USED: generate(section, contentPackageVersion, ...)');
        }

        // 兼容：不再读取旧路径，直接兜底卡，避免你以为“还在用旧体系”
        return $this->fallbackCards($section, 2);
    }

    private function passesAxisMatch(array $card, array $userSet, array $axisInfo): bool
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

    private function isAxisCardId(string $id): bool
    {
        return str_contains($id, '_axis_');
    }

    private function countAxis(array $cards): int
    {
        $n = 0;
        foreach ($cards as $c) {
            if (!is_array($c)) continue;
            $id = (string)($c['id'] ?? '');
            if ($id !== '' && $this->isAxisCardId($id)) $n++;
        }
        return $n;
    }

    private function countNonAxis(array $cards): int
    {
        $n = 0;
        foreach ($cards as $c) {
            if (!is_array($c)) continue;
            $id = (string)($c['id'] ?? '');
            if ($id !== '' && !$this->isAxisCardId($id)) $n++;
        }
        return $n;
    }

    private function hasAnyTag(array $tags, array $needles): bool
    {
        if (!is_array($tags) || empty($tags)) return false;
        foreach ($needles as $t) {
            if (is_string($t) && $t !== '' && in_array($t, $tags, true)) return true;
        }
        return false;
    }

    private function stableSeed(array $userSet, array $axisInfo): int
    {
        $attemptId = $axisInfo['attempt_id'] ?? null;
        if (is_string($attemptId) && $attemptId !== '') {
            return $this->ucrc32($attemptId);
        }

        $tags = array_keys($userSet);
        sort($tags);

        $dims = ['EI','SN','TF','JP','AT'];
        $axes = [];
        foreach ($dims as $dim) {
            $v = (isset($axisInfo[$dim]) && is_array($axisInfo[$dim])) ? $axisInfo[$dim] : [];
            $side  = (string)($v['side'] ?? '');
            $delta = (int)($v['delta'] ?? 0);
            $pct   = (int)($v['pct'] ?? 0);
            $axes[] = "{$dim}:{$side}:{$delta}:{$pct}";
        }

        $payload = json_encode([$tags, $axes], JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) $payload = '';

        return $this->ucrc32($payload);
    }

    private function ucrc32(string $s): int
    {
        $u = sprintf('%u', crc32($s));
        return (int)$u;
    }

    private function fallbackCards(string $section, int $need): array
    {
        $out = [];
        for ($i = 1; $i <= $need; $i++) {
            $out[] = [
                'id'       => "{$section}_fallback_{$i}",
                'section'  => $section,
                'title'    => 'General Tip',
                'desc'     => 'Content pack did not provide enough matched cards. Showing a safe fallback tip.',
                'bullets'  => [
                    'Turn strengths into a repeatable template',
                    'Add one counter-check in key moments',
                    'Weekly review: keep what works, remove what doesn’t'
                ],
                'tips'     => [
                    'Write your first instinct, then add one alternative',
                    'Use checklists to reduce cognitive load'
                ],
                'tags'     => ['fallback'],
                'priority' => 0,
                'match'    => null,
            ];
        }
        return $out;
    }
}